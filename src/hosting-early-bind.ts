import { createServer, type IncomingMessage, type ServerResponse } from 'http';
import { existsSync } from 'fs';
import { join } from 'path';

type Boot = { phase: 'starting' | 'failed'; error: string | null };

const hosting = globalThis as typeof globalThis & {
  __karnacabHttpServer?: ReturnType<typeof createServer>;
  __karnacabSetError?: (error: unknown) => void;
  __karnacabBoot?: Boot;
};

function escapeHtml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function diag() {
  const root = process.cwd();
  return {
    node: process.version,
    cwd: root,
    PORT: process.env.PORT || 'NOT SET (host must inject PORT)',
    HOST: process.env.HOST || '0.0.0.0',
    NODE_ENV: process.env.NODE_ENV || 'not set',
    'dist/main.js': existsSync(join(root, 'dist', 'main.js')) ? 'yes' : 'NO',
    '.env': existsSync(join(root, '.env')) ? 'yes' : 'NO',
    'production.env': existsSync(join(root, 'production.env')) ? 'yes' : 'NO',
    DATABASE_URL: process.env.DATABASE_URL ? 'set' : 'MISSING',
    JWT_SECRET: process.env.JWT_SECRET ? 'set' : 'MISSING',
  };
}

function html(boot: Boot): string {
  const rows = Object.entries(diag())
    .map(([k, v]) => `<tr><th style="text-align:left;padding:6px 12px 6px 0">${escapeHtml(k)}</th><td><code>${escapeHtml(String(v))}</code></td></tr>`)
    .join('');
  const err = boot.error
    ? `<h2>Error (fix this)</h2><pre style="white-space:pre-wrap;background:#111;color:#fee;padding:16px;border-radius:8px">${escapeHtml(boot.error)}</pre>`
    : '<p>App bound the port. Nest is still loading. Refresh in 20 seconds.</p>';
  return `<!DOCTYPE html><html><head><meta charset="utf-8"><title>KarnaCab API error</title></head>
<body style="font-family:Segoe UI,sans-serif;max-width:900px;margin:32px auto;padding:0 16px">
<p style="color:#c2410c;font-weight:700;letter-spacing:.05em">api.karnacab.in</p>
<h1>${boot.error ? 'Startup error — read below and fix' : 'Starting KarnaCab API'}</h1>
${err}
<table>${rows}</table>
<p style="margin-top:24px;color:#444">cPanel Node.js App → startup file must be <code>app.js</code>. Then Restart.</p>
</body></html>`;
}

const boot: Boot = hosting.__karnacabBoot ?? { phase: 'starting', error: null };
hosting.__karnacabBoot = boot;

function setError(error: unknown) {
  boot.phase = 'failed';
  boot.error = error instanceof Error ? error.stack || error.message : String(error);
  console.error(boot.error);
}

hosting.__karnacabSetError = setError;

process.on('uncaughtException', setError);
process.on('unhandledRejection', setError);

function send(req: IncomingMessage, res: ServerResponse) {
  const url = String(req.url || '/');
  const json =
    String(req.headers.accept || '').includes('application/json') ||
    url.includes('/api') ||
    url.includes('health');
  if (json) {
    res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
    res.end(JSON.stringify({ ok: false, phase: boot.phase, error: boot.error, checks: diag() }, null, 2));
    return;
  }
  res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
  res.end(html(boot));
}

if (!hosting.__karnacabHttpServer) {
  const server = createServer(send);
  hosting.__karnacabHttpServer = server;
  const port = Number(process.env.PORT || process.env.APP_PORT || 3000);
  const host = process.env.HOST || '0.0.0.0';
  server.listen(port, host, () => {
    console.log(`KarnaCab debug server ${host}:${port}`);
  });
}
