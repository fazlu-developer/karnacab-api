/**
 * Concurrent Chrome proxy to the live KarnaRide API.
 * PHP's built-in server handles one request at a time, so customer and driver
 * polls queue for several seconds and then time out. This process forwards
 * /cors-live/* to https://api.karnaride.in in parallel.
 *
 *   node scripts/cors-live-proxy.mjs
 *   Chrome debug apps call http://127.0.0.1:8003/cors-live/api/v1
 */
import http from 'node:http';
import https from 'node:https';
import { lookup } from 'node:dns/promises';

const PORT = Number(process.env.CORS_LIVE_PORT || 8003);
const HOST = process.env.CORS_LIVE_HOST || '127.0.0.1';
const UPSTREAM = 'api.karnaride.in';

const agent = new https.Agent({ keepAlive: true, maxSockets: 64, scheduling: 'fifo' });
let ipv4 = '';

async function upstreamAddress() {
  if (ipv4) return ipv4;
  const found = await lookup(UPSTREAM, { family: 4 });
  ipv4 = found.address;
  return ipv4;
}

function corsHeaders(req) {
  const origin = req.headers.origin || '*';
  return {
    'Access-Control-Allow-Origin': origin,
    'Access-Control-Allow-Methods': 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
    'Access-Control-Allow-Headers': 'Authorization, Content-Type, Accept, Accept-Language, X-Requested-With',
    'Access-Control-Max-Age': '86400',
    Vary: 'Origin',
  };
}

function fail(res, cors, status, detail) {
  if (res.headersSent || res.writableEnded) {
    res.destroy();
    return;
  }
  res.writeHead(status, { 'Content-Type': 'application/json', ...cors });
  res.end(JSON.stringify({
    message: 'Cannot reach KarnaRide live API through the local Chrome proxy.',
    detail,
  }));
}

const server = http.createServer(async (req, res) => {
  const cors = corsHeaders(req);
  if (req.method === 'OPTIONS') {
    res.writeHead(204, cors);
    res.end();
    return;
  }

  const url = new URL(req.url || '/', `http://${HOST}`);
  if (!url.pathname.startsWith('/cors-live/')) {
    res.writeHead(404, { 'Content-Type': 'application/json', ...cors });
    res.end(JSON.stringify({ message: 'This port is the Chrome live proxy. Use /cors-live/.' }));
    return;
  }

  let address = '';
  try {
    address = await upstreamAddress();
  } catch (error) {
    fail(res, cors, 502, error instanceof Error ? error.message : 'DNS lookup failed');
    return;
  }

  const headers = { host: UPSTREAM };
  for (const name of ['authorization', 'accept', 'accept-language', 'content-type', 'content-length']) {
    const value = req.headers[name];
    if (typeof value === 'string' && value !== '') headers[name] = value;
  }

  const proxyReq = https.request({
    host: address,
    servername: UPSTREAM,
    port: 443,
    path: `${url.pathname.replace(/^\/cors-live/, '')}${url.search}`,
    method: req.method,
    headers,
    agent,
    timeout: 60000,
  }, (proxyRes) => {
    const out = { ...cors };
    const type = proxyRes.headers['content-type'];
    if (typeof type === 'string' && type !== '') out['Content-Type'] = type;
    res.writeHead(proxyRes.statusCode || 502, out);
    proxyRes.pipe(res);
  });

  proxyReq.on('timeout', () => {
    ipv4 = '';
    proxyReq.destroy(new Error('Live API timed out'));
  });
  proxyReq.on('error', (error) => {
    ipv4 = '';
    fail(res, cors, 502, error.message);
  });
  req.pipe(proxyReq);
});

server.listen(PORT, HOST, () => {
  process.stdout.write(`Chrome live proxy http://${HOST}:${PORT}/cors-live -> https://${UPSTREAM}\n`);
});
