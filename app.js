/**
 * WebDeluxe / cPanel Node.js App — STARTUP FILE = app.js
 *
 * Binds process.env.PORT immediately so the PaaS proxy does not return
 * the generic "Service Unavailable" text. Then boots Nest. If Nest fails,
 * this same URL shows the real error (file, env, dist, stack).
 */
'use strict';

const fs = require('fs');
const http = require('http');
const path = require('path');
const { page, jsonPayload } = require('./hosting-status');

process.chdir(__dirname);

function loadEnvFile(fileName) {
  const fullPath = path.join(__dirname, fileName);
  if (!fs.existsSync(fullPath)) {
    return;
  }
  const text = fs.readFileSync(fullPath, 'utf8');
  for (const rawLine of text.split(/\r?\n/)) {
    const line = rawLine.trim();
    if (!line || line.startsWith('#')) {
      continue;
    }
    const eq = line.indexOf('=');
    if (eq === -1) {
      continue;
    }
    const key = line.slice(0, eq).trim();
    if (!key || process.env[key] !== undefined) {
      continue;
    }
    let value = line.slice(eq + 1).trim();
    if (
      (value.startsWith('"') && value.endsWith('"')) ||
      (value.startsWith("'") && value.endsWith("'"))
    ) {
      value = value.slice(1, -1);
    }
    process.env[key] = value;
  }
}

loadEnvFile('.env');
loadEnvFile('.env.production');

if (!process.env.NODE_ENV) {
  process.env.NODE_ENV = 'production';
}
if (process.env.PORT) {
  process.env.APP_PORT = process.env.PORT;
}

const boot = { phase: 'starting', error: null };

function sendPlaceholder(req, res) {
  const url = String(req.url || '/');
  const accept = String(req.headers.accept || '');
  const asJson =
    accept.includes('application/json') ||
    url.startsWith('/api') ||
    url.startsWith('/healthz');
  const payload = jsonPayload(boot);
  if (asJson) {
    // 200 so the PaaS proxy does not replace our body with "Service Unavailable"
    res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
    res.end(JSON.stringify(payload, null, 2));
    return;
  }
  res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
  res.end(page(boot));
}

const server = http.createServer(sendPlaceholder);

global.__karnacabHttpServer = server;
global.__karnacabSetError = (err) => {
  boot.phase = 'failed';
  boot.error =
    err instanceof Error ? err.stack || err.message : String(err);
  console.error('KarnaCab API startup failed');
  console.error(boot.error);
};

const listenPort = Number(process.env.PORT || process.env.APP_PORT || 3000);
const listenHost = process.env.HOST || '0.0.0.0';
const distMain = path.join(__dirname, 'dist', 'main.js');

server.listen(listenPort, listenHost, () => {
  console.log(`KarnaCab API bound ${listenHost}:${listenPort} (startup file app.js)`);
  if (!fs.existsSync(distMain)) {
    global.__karnacabSetError(
      new Error(
        'dist/main.js is missing. Upload the dist/ folder from your PC (npm run build) or run npm run build in cPanel Terminal.',
      ),
    );
    return;
  }
  try {
    require(distMain);
  } catch (error) {
    global.__karnacabSetError(error);
  }
});
