'use strict';

const { chromium } = require('playwright');
const { AdminApi, MarketplaceApi, CoreStubApi, MockControl } = require('./lib/api');
const db = require('./lib/db');

const ADMIN_URL = 'http://127.0.0.1:3200';
const MARKETPLACE_URL = 'http://127.0.0.1:3100';
const API_BASE = 'http://127.0.0.1/api';
// routes/core-stub.php is mounted at /dev/core directly -- NOT under /api.
const CORE_STUB_BASE = 'http://127.0.0.1';

async function run({ config, report }) {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });

  const ctx = {
    config,
    browser,
    adminUrl: ADMIN_URL,
    marketplaceUrl: MARKETPLACE_URL,
    admin: new AdminApi(API_BASE),
    // The end-customer's own identity: config/core.php's fixed dev-token,
    // resolved by LocalCoreStub to user_ref=dev-user -- the same identity
    // NUXT_PUBLIC_DEV_TOKEN wires the marketplace client to use.
    marketplace: new MarketplaceApi(API_BASE, 'dev-token'),
    core: new CoreStubApi(CORE_STUB_BASE, 'dev-service-credential'),
    mock: new MockControl(`http://127.0.0.1:${config.mockService.port}`),
    mockUrl: `http://127.0.0.1:${config.mockService.port}`,
    db,
    // Populated as the scenario progresses; later parts read what earlier
    // parts wrote here.
    state: {},
  };

  try {
    await require('./parts/part1-admin').run(ctx, report);
    await require('./parts/part2-user').run(ctx, report);
    await require('./parts/part3-failures').run(ctx, report);
    await require('./parts/part4-operator').run(ctx, report);
  } finally {
    await browser.close();
  }
}

module.exports = { run };
