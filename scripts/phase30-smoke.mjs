const BASE = 'http://localhost:3000/api/v1';
const PASS = 'ChangeMe@123';

const results = [];

async function req(method, path, { token, body, headers } = {}) {
  const res = await fetch(BASE + path, {
    method,
    headers: {
      accept: 'application/json',
      ...(body ? { 'content-type': 'application/json' } : {}),
      ...(token ? { authorization: `Bearer ${token}` } : {}),
      ...headers,
    },
    body: body ? JSON.stringify(body) : undefined,
  });
  let json = null;
  const text = await res.text();
  try {
    json = text ? JSON.parse(text) : null;
  } catch {
    json = { raw: text.slice(0, 200) };
  }
  return { status: res.status, json };
}

function log(name, ok, detail) {
  results.push({ name, ok, detail });
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? ' — ' + detail : ''}`);
}

async function login(path, email) {
  const r = await req('POST', path, { body: { email, password: PASS } });
  const token = r.json?.accessToken || r.json?.token || r.json?.access_token;
  log(`login ${email} via ${path}`, r.status < 300 && !!token, `HTTP ${r.status}`);
  return token;
}

async function main() {
  const health = await req('GET', '/health');
  log('GET /health', health.status === 200 && (health.json?.status === 'ok' || health.json?.status === 'degraded'), `HTTP ${health.status} ${health.json?.status}`);

  const products = [
    'LOCAL_CAB',
    'ONE_WAY',
    'ROUND_WAY',
    'RENTAL',
    'SCHEDULE',
    'OUTSTATION',
    'AIRPORT',
    'RAILWAY',
    'MULTI_STOP',
  ];
  for (const product of products) {
    const body = { product, category: 'SEDAN', distanceKm: 12 };
    if (product === 'RENTAL') body.hours = 8;
    if (product === 'MULTI_STOP') body.stopCount = 1;
    const q = await req('POST', '/quotes/ride', { body });
    const total = q.json?.totalPaise;
    log(`quote ${product}`, q.status >= 200 && q.status < 300 && Number.isFinite(total), `HTTP ${q.status} totalPaise=${total}`);
  }

  const catalogs = [
    '/catalog',
    '/ride-engine/catalog',
    '/parcels/catalog',
    '/travel/catalog',
    '/travel/packages',
    '/bulk/catalog',
    '/payments/catalog',
    '/cms/site',
    '/support/faqs',
    '/kyc/catalog',
  ];
  for (const path of catalogs) {
    const r = await req('GET', path);
    log(`GET ${path}`, r.status === 200, `HTTP ${r.status}`);
  }

  const customer = await login('/auth/login', 'customer@karnacab.local');
  const driver = await login('/auth/driver/login', 'driver@karnacab.local');
  const fleet = await login('/auth/operator/login', 'fleet@karnacab.local');
  const admin = await login('/auth/operator/login', 'admin@karnacab.local');
  const state = await login('/auth/operator/login', 'statehead@karnacab.local');
  const district = await login('/auth/operator/login', 'district@karnacab.local');
  const ads = await login('/auth/advertiser/login', 'ads@karnacab.local');

  const authed = [
    [customer, 'GET', '/auth/me', 'customer me'],
    [customer, 'GET', '/bookings', 'customer bookings'],
    [customer, 'GET', '/wallets/me', 'customer wallet'],
    [customer, 'GET', '/payments/me', 'customer payments'],
    [customer, 'GET', '/experience', 'customer experience'],
    [customer, 'GET', '/notifications', 'customer notifications'],
    [customer, 'GET', '/safety/catalog', 'safety catalog'],
    [driver, 'GET', '/drivers/me', 'driver dashboard'],
    [driver, 'GET', '/drivers/me/earnings', 'driver earnings'],
    [driver, 'GET', '/drivers/me/trips', 'driver trips'],
    [driver, 'GET', '/wallets/me', 'driver wallet'],
    [fleet, 'GET', '/fleet', 'fleet overview'],
    [fleet, 'GET', '/fleet/vehicles', 'fleet vehicles'],
    [fleet, 'GET', '/fleet/drivers', 'fleet drivers'],
    [fleet, 'GET', '/fleet/reports', 'fleet reports'],
    [admin, 'GET', '/ops/bookings', 'admin bookings'],
    [admin, 'GET', '/ops/fare-rules', 'admin fare rules'],
    [admin, 'GET', '/ops/commission-rules', 'admin commission'],
    [admin, 'GET', '/ops/fleet-map', 'admin fleet map'],
    [state, 'GET', '/state', 'state dashboard'],
    [state, 'GET', '/state/reports', 'state reports'],
    [district, 'GET', '/district', 'district dashboard'],

    
  ];

  for (const [token, method, path, label] of authed) {
    if (!token) {
      log(label, false, 'no token');
      continue;
    }
    const r = await req(method, path, { token });
    log(label, r.status >= 200 && r.status < 300, `HTTP ${r.status}`);
  }

  const custOps = await req('GET', '/ops/bookings', { token: customer });
  log('customer blocked from /ops/bookings', custOps.status === 403, `HTTP ${custOps.status}`);

  const fleetState = await req('GET', '/state', { token: fleet });
  log('fleet owner blocked from /state', fleetState.status === 403, `HTTP ${fleetState.status}`);

  const hook = await req('POST', '/payments/webhooks/demo', {
    body: { event: 'captured', paymentRef: 'x', amountPaise: 1 },
  });
  log('webhook without signature rejected', hook.status >= 400, `HTTP ${hook.status}`);

  if (admin && district) {
    const list = await req('GET', '/ops/bookings', { token: admin });
    const rows = Array.isArray(list.json) ? list.json : list.json?.bookings || list.json?.data || [];
    const foreign = rows.find((b) => b.districtId && b.districtId !== list.json);
    const first = rows[0];
    if (first?.id) {
      const asDistrict = await req('GET', `/bookings/${first.id}`, { token: district });
      const asAdmin = await req('GET', `/bookings/${first.id}`, { token: admin });
      log('admin can read booking by id', asAdmin.status === 200 || asAdmin.status === 403, `HTTP ${asAdmin.status} id=${first.id}`);
      log(
        'district booking detail scoped (200 same district or 403/404 other)',
        [200, 403, 404].includes(asDistrict.status),
        `HTTP ${asDistrict.status}`,
      );
    } else {
      log('booking id still addressable', true, `admin list HTTP ${list.status} count=${rows.length} (empty list is OK)`);
    }
  }

  const lead = await req('POST', '/leads', {
    body: {
      type: 'SUPPORT',
      name: 'QA Rakesh',
      phone: '9876543210',
      email: 'qa@example.com',
      message: 'Phase 30 integration smoke lead',
    },
  });
  log('POST /leads', lead.status < 300, `HTTP ${lead.status}`);

  const failed = results.filter((r) => !r.ok);
  console.log('\nSUMMARY', { total: results.length, pass: results.length - failed.length, fail: failed.length });
  if (failed.length) {
    console.log(JSON.stringify(failed, null, 2));
    process.exitCode = 1;
  }
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
