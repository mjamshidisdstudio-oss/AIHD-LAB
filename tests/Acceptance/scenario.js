'use strict';

const { chromium } = require('playwright');
const { AdminApi, MarketplaceApi, CoreStubApi, MockControl } = require('./lib/api');

const ADMIN_URL = 'http://127.0.0.1:3200';
const MARKETPLACE_URL = 'http://127.0.0.1:3100';
const API_BASE = 'http://127.0.0.1/api';

async function run({ config, report }) {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });

  const ctx = {
    config,
    browser,
    adminUrl: ADMIN_URL,
    marketplaceUrl: MARKETPLACE_URL,
    admin: new AdminApi(API_BASE),
    core: new CoreStubApi(API_BASE, 'dev-service-credential'),
    mock: new MockControl(`http://127.0.0.1:${config.mockService.port}`),
    mockUrl: `http://127.0.0.1:${config.mockService.port}`,
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
