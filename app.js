/**
 * WebDeluxe Node.js App startup file: app.js
 * Binds PORT first (so you never see only "Service Unavailable"), then starts Nest.
 */
'use strict';

const fs = require('fs');
const http = require('http');
const path = require('path');

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
loadEnvFile('production.env');
if (!process.env.NODE_ENV) {
  process.env.NODE_ENV = 'production';
}
if (process.env.PORT) {
  process.env.APP_PORT = process.env.PORT;
}

const boot = { phase: 'starting', error: null };

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function diag() {
  return {
    node: process.version,
    cwd: process.cwd(),
    PORT: process.env.PORT || 'NOT SET',
    NODE_ENV: process.env.NODE_ENV || 'not set',
    'dist/main.js': fs.existsSync(path.join(__dirname, 'dist', 'main.js'))
      ? 'yes'
      : 'NO',
    '.env': fs.existsSync(path.join(__dirname, '.env')) ? 'yes' : 'NO',
    'production.env': fs.existsSync(path.join(__dirname, 'production.env'))
      ? 'yes'
      : 'NO',
    DATABASE_URL: process.env.DATABASE_URL ? 'set' : 'MISSING',
    JWT_SECRET: process.env.JWT_SECRET ? 'set' : 'MISSING',
  };
}

function html() {
  const rows = Object.entries(diag())
    .map(
      ([k, v]) =>
        `<tr><th style="text-align:left;padding:6px 12px 6px 0">${escapeHtml(k)}</th><td><code>${escapeHtml(String(v))}</code></td></tr>`,
    )
    .join('');
  const err = boot.error
    ? `<h2>Error (fix this)</h2><pre style="white-space:pre-wrap;background:#111;color:#fee;padding:16px;border-radius:8px">${escapeHtml(boot.error)}</pre>`
    : '<p>Port is bound. Nest is loading — refresh in 20 seconds.</p>';
  return `<!DOCTYPE html><html><head><meta charset="utf-8"><title>KarnaCab API error</title></head>
<body style="font-family:Segoe UI,sans-serif;max-width:900px;margin:32px auto;padding:0 16px">
<p style="color:#c2410c;font-weight:700">api.karnacab.in</p>
<h1>${boot.error ? 'Startup error — read below and fix' : 'Starting KarnaCab API'}</h1>
${err}
<table>${rows}</table>
<p>Node.js App startup file must be <code>app.js</code>. Open this URL: <code>/</code> not only /health.</p>
</body></html>`;
}

function setError(error) {
  boot.phase = 'failed';
  boot.error = error && error.stack ? error.stack : String(error);
  console.error(boot.error);
}

function send(req, res) {
  const url = String(req.url || '/');
  const asJson =
    String(req.headers.accept || '').includes('application/json') ||
    url.includes('/api') ||
    url.includes('health');
  if (asJson) {
    res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
    res.end(
      JSON.stringify(
        { ok: false, phase: boot.phase, error: boot.error, checks: diag() },
        null,
        2,
      ),
    );
    return;
  }
  res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
  res.end(html());
}

process.on('uncaughtException', setError);
process.on('unhandledRejection', setError);

const server = http.createServer(send);
global.__karnacabHttpServer = server;
global.__karnacabSetError = setError;

const listenPort = Number(process.env.PORT || process.env.APP_PORT || 3000);
const listenHost = process.env.HOST || '0.0.0.0';

server.listen(listenPort, listenHost, () => {
  console.log('KarnaCab debug server', listenHost + ':' + listenPort);
  const distMain = path.join(__dirname, 'dist', 'main.js');
  if (!fs.existsSync(distMain)) {
    setError(
      new Error(
        'dist/main.js is missing. On the server run: npm run build   OR upload the dist folder from your PC.',
      ),
    );
    return;
  }
  try {
    require(distMain);
  } catch (error) {
    setError(error);
  }
});

module.exports = server;
