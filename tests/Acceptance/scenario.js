'use strict';

const fs = require('fs');
const { execSync } = require('child_process');
const { chromium } = require('playwright');
const { AdminApi, MarketplaceApi, CoreStubApi, MockControl } = require('./lib/api');
const { waitForPort } = require('./lib/processes');
const db = require('./lib/db');

const ADMIN_URL = 'http://127.0.0.1:3200';
const MARKETPLACE_URL = 'http://127.0.0.1:3100';
const API_BASE = 'http://127.0.0.1/api';
// routes/core-stub.php is mounted at /dev/core directly -- NOT under /api.
const CORE_STUB_BASE = 'http://127.0.0.1';
// This exact symlink is specific to the pre-provisioned dev sandbox this
// suite was originally built in; CI and any other machine rely on
// Playwright's own resolution instead (its default cache, or
// PLAYWRIGHT_BROWSERS_PATH if set) after `npx playwright install chromium`.
const SANDBOX_CHROMIUM_PATH = '/opt/pw-browsers/chromium';

async function run({ config, report, group, laravelEnv, root }) {
  const launchOptions = fs.existsSync(SANDBOX_CHROMIUM_PATH) ? { executablePath: SANDBOX_CHROMIUM_PATH } : {};
  const browser = await chromium.launch(launchOptions);

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

  // Core connectivity (CORE_BASE_URL) is a whole-process config value, not
  // something any single request can be made to see differently -- the
  // "core unreachable" failure path (Part 3, step 31) needs the actual
  // Laravel process restarted with a deliberately-dead base_url, and
  // restarted again afterward with the real one before Part 4 relies on a
  // working core connection.
  ctx.restartLaravel = async (envOverrides = {}) => {
    await group.restartProcess('laravel', 'php', ['artisan', 'serve', '--port=80', '--no-reload'], {
      cwd: root,
      env: { ...laravelEnv, ...envOverrides },
    });
    // A raw port check, not a full authenticated health check -- an
    // auth.core-gated endpoint would itself hang/fail while core is
    // deliberately broken, which is exactly the scenario under test.
    await waitForPort('laravel', '127.0.0.1', 80, { timeoutMs: 30000 });
  };

  // The poll sweep (Part 3's silent/duplicate/slow paths) is a real Laravel
  // scheduled command (`poll:sweep`, everyMinute in routes/console.php) --
  // this suite runs no scheduler process, so nothing would ever invoke it on
  // its own. Rather than wait up to 60 real seconds for a cron tick (or add
  // a whole extra always-running process just to get one), the test invokes
  // the EXACT same artisan command directly, at the moment it actually needs
  // the sweep to happen -- deterministic control over timing, not a shortcut
  // around the real mechanism.
  ctx.runArtisan = (args) => {
    return execSync(`php artisan ${args}`, { cwd: root, env: laravelEnv, stdio: 'pipe' }).toString();
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
