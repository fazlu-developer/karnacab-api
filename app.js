/**
 * WebDeluxe / cPanel → Setup Node.js App
 *
 * Node.js version: 20 or 22
 * Application mode: Production
 * Application root: folder that contains this file (package.json + dist/)
 * Application URL: https://api.karnacab.in  (or the subdomain you attached)
 * Application startup file: app.js
 *
 * Then: Run NPM Install → npm run build (if dist/ was not uploaded) → Restart
 * Copy .env.production to .env in this same folder.
 */
'use strict';

const fs = require('fs');
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

if (!process.env.NODE_ENV) {
  process.env.NODE_ENV = 'production';
}

// Passenger / CloudLinux injects PORT. Nest must use that, not a hardcoded 3000.
if (process.env.PORT) {
  process.env.APP_PORT = process.env.PORT;
}

const distMain = path.join(__dirname, 'dist', 'main.js');
if (!fs.existsSync(distMain)) {
  console.error(
    'KarnaCab API: dist/main.js is missing. Upload dist/ from a local npm run build, or in cPanel Terminal run: npm run build',
  );
  process.exit(1);
}

require(distMain);
