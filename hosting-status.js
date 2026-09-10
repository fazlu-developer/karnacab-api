'use strict';

const fs = require('fs');
const path = require('path');

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function diagnostics() {
  const root = process.cwd();
  return {
    node: process.version,
    cwd: root,
    port: process.env.PORT || '(PaaS PORT not set)',
    appPort: process.env.APP_PORT || '(not set)',
    nodeEnv: process.env.NODE_ENV || '(not set)',
    hasDistMain: fs.existsSync(path.join(root, 'dist', 'main.js')),
    hasEnv: fs.existsSync(path.join(root, '.env')),
    hasEnvProduction: fs.existsSync(path.join(root, '.env.production')),
    hasDatabaseUrl: Boolean(process.env.DATABASE_URL),
    hasJwt: Boolean(process.env.JWT_SECRET),
    startupFile: 'app.js',
  };
}

function page({ phase, error }) {
  const d = diagnostics();
  const failed = phase === 'failed';
  const title = failed ? 'KarnaCab API failed to start' : 'KarnaCab API is starting';
  const rows = Object.entries(d)
    .map(([key, value]) => `<tr><th>${escapeHtml(key)}</th><td><code>${escapeHtml(String(value))}</code></td></tr>`)
    .join('');

  const errorBlock = error
    ? `<h2>Error</h2><pre>${escapeHtml(error)}</pre>`
    : '<p>Waiting for Nest to finish loading (Prisma / MySQL). Keep this tab open and refresh in 20 seconds.</p>';

  const hints = `
    <h2>Fix checklist (WebDeluxe Node.js App)</h2>
    <ol>
      <li>Application startup file must be <code>app.js</code> (not dist/main.js).</li>
      <li>Copy <code>.env.production</code> to <code>.env</code> in the same folder as app.js.</li>
      <li>Do not set PORT in the env form — the host injects it.</li>
      <li>Run NPM Install, then upload <code>dist/</code> or run <code>npm run build</code>.</li>
      <li>Restart the Node.js App. Open cPanel stderr / passenger log if this page stays on failed.</li>
    </ol>
    <p>When healthy: <code>/api/v1/health</code> and <code>/api/docs</code></p>
  `;

  return `<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>${escapeHtml(title)}</title></head>
<body style="font-family:Segoe UI,system-ui,sans-serif;max-width:880px;margin:40px auto;padding:0 16px;color:#111">
<p style="color:#b45309;font-weight:700;text-transform:uppercase;letter-spacing:.04em;font-size:12px">api.karnacab.in debug</p>
<h1>${escapeHtml(title)}</h1>
${errorBlock}
<table style="border-collapse:collapse;width:100%;font-size:14px">${rows}</table>
${hints}
</body></html>`;
}

function jsonPayload({ phase, error }) {
  return {
    ok: phase === 'ready',
    service: 'karnacab-api',
    phase,
    error: error || null,
    checks: diagnostics(),
    urls: {
      health: '/api/v1/health',
      docs: '/api/docs',
    },
  };
}

module.exports = { diagnostics, page, jsonPayload };
