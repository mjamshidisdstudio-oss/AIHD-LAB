#!/usr/bin/env node
'use strict';

/**
 * Phase L3: a SECOND acceptance scenario, run as a wholly separate process
 * from the existing 35-step suite (run.js/scenario.js), which this file does
 * not touch or import. That suite proves the full auth+coin path; this one
 * proves the opposite corner of the same flag space -- LAB_BILLING_ENABLED=
 * false, LAB_AUTH_MODE=anonymous -- still boots a working, real-browser
 * marketplace with nothing removed, only reconfigured.
 *
 * Same harness shape as run.js (fresh DB, real Laravel/Reverb/mock-service/
 * marketplace-nuxt processes, waits for health, tears down on exit) but:
 *   - no admin-nuxt process -- this scenario has no admin journey
 *   - LAB_BILLING_ENABLED=false / LAB_AUTH_MODE=anonymous on the Laravel env
 *   - the marketplace client boots with NO dev token at all, so it never
 *     presents a bearer credential -- identity comes only from the
 *     anonymous cookie AnonymousAuth issues
 *   - season-gen (the seeded, already-published, coin_cost=0 service) is
 *     redirected at the mock-service via a direct DB update (lib/arrange.js)
 *     since a published version's post_url/get_url are frozen and there is
 *     no admin-API path to change them
 *
 * Usage: node run-launch-mode.js [--profile=fast|realistic]
 */

const path = require('path');
const fs = require('fs');
const { execSync } = require('child_process');
const { waitForHttp, waitForPort, ProcessGroup, log } = require('./lib/processes');
const { Report } = require('./lib/report');
const { pointSeasonGenAtMockService } = require('./lib/arrange');

const ROOT = path.resolve(__dirname, '..', '..');

function parseArgs() {
  const profileArg = process.argv.find((a) => a.startsWith('--profile='));
  const profile = profileArg ? profileArg.split('=')[1] : (process.env.ACCEPTANCE_PROFILE || 'fast');
  return { profile };
}

function loadConfig(profile) {
  const file = path.join(__dirname, 'config', `${profile}.json`);
  if (!fs.existsSync(file)) {
    throw new Error(`Unknown profile "${profile}" -- no config/${profile}.json`);
  }
  return JSON.parse(fs.readFileSync(file, 'utf8'));
}

async function main() {
  const { profile } = parseArgs();
  const config = loadConfig(profile);
  console.log(`\nAIHD-LAB acceptance suite -- LAUNCH MODE -- profile: ${profile} (${config.description})\n`);

  const dbName = 'aihd_lab_acceptance';
  const dbRootUser = process.env.ACCEPTANCE_DB_ROOT_USER || 'root';
  const dbRootPassword = process.env.ACCEPTANCE_DB_ROOT_PASSWORD || '';

  const laravelEnv = {
    ...process.env,
    APP_ENV: 'local',
    DB_DATABASE: dbName,
    ...(process.env.ACCEPTANCE_DB_USERNAME ? { DB_USERNAME: process.env.ACCEPTANCE_DB_USERNAME } : {}),
    ...(process.env.ACCEPTANCE_DB_PASSWORD !== undefined ? { DB_PASSWORD: process.env.ACCEPTANCE_DB_PASSWORD } : {}),
    CACHE_STORE: 'file',
    SESSION_DRIVER: 'array',
    QUEUE_CONNECTION: 'sync',
    BROADCAST_CONNECTION: 'reverb',
    REVERB_APP_KEY: 'acceptance-key',
    REVERB_APP_SECRET: 'acceptance-secret',
    REVERB_APP_ID: 'acceptance-app',
    REVERB_HOST: '127.0.0.1',
    REVERB_PORT: '8080',
    REVERB_SCHEME: 'http',
    REVERB_SERVER_HOST: '0.0.0.0',
    REVERB_SERVER_PORT: '8080',
    PHP_CLI_SERVER_WORKERS: String(config.phpWorkers),
    // Phase L3's whole point: launch mode, live.
    LAB_BILLING_ENABLED: 'false',
    LAB_AUTH_MODE: 'anonymous',
  };

  const group = new ProcessGroup();
  const report = new Report();
  let exitCode = 1;

  try {
    log('setup', `creating database ${dbName} (if not present)`);
    execSync(
      `mysql -u${dbRootUser} ${dbRootPassword ? `-p${dbRootPassword}` : ''} -e "CREATE DATABASE IF NOT EXISTS \\\`${dbName}\\\`;"`,
      { stdio: 'inherit', cwd: ROOT },
    );

    log('setup', 'migrate:fresh --seed (clean-state guarantee)');
    execSync('php artisan migrate:fresh --seed', { stdio: 'inherit', cwd: ROOT, env: laravelEnv });

    log('setup', 'cache:clear (clean-state guarantee)');
    execSync('php artisan cache:clear', { stdio: 'inherit', cwd: ROOT, env: laravelEnv });

    log('setup', 'pointing the seeded season-gen service at the standalone mock-service');
    pointSeasonGenAtMockService(`http://127.0.0.1:${config.mockService.port}`);

    // --- Boot every process this scenario needs, in parallel ---------------
    group.spawnProcess('laravel', 'php', ['artisan', 'serve', '--port=80', '--no-reload'], { cwd: ROOT, env: laravelEnv });
    group.spawnProcess('reverb', 'php', ['artisan', 'reverb:start', '--port=8080'], { cwd: ROOT, env: laravelEnv });
    group.spawnProcess('mock-service', 'node', ['server.js'], {
      cwd: path.join(ROOT, 'tools', 'mock-service'),
      env: {
        ...process.env,
        PORT: String(config.mockService.port),
        OUR_BASE_URL: 'http://127.0.0.1',
        // Must match season-gen's own seeded webhook_signing_key
        // (database/seeders/SeasonalViewsSeeder.php) -- season-gen never
        // goes through the admin secret-entry form this suite's OTHER
        // fixture services do, so there is no "acceptance-shared-key"
        // equivalent to set; the mock is configured to match IT instead.
        SHARED_KEY: 'dev-season-gen-signing-key',
        PROCESSING_DELAY_MS: String(config.mockService.processingDelayMs),
        SLOW_DELAY_MS: String(config.mockService.slowDelayMs),
      },
    });
    group.spawnProcess('marketplace-nuxt', 'npm', ['run', 'dev', '--', '--port=3100'], {
      cwd: path.join(ROOT, 'marketplace'),
      env: {
        ...process.env,
        NUXT_PUBLIC_API_BASE: 'http://127.0.0.1/api',
        // Deliberately EMPTY, not omitted -- nuxt.config.ts's fallback only
        // applies when this var is entirely unset. A no-login visitor must
        // never present a bearer credential; identity comes solely from the
        // anonymous cookie.
        NUXT_PUBLIC_DEV_TOKEN: '',
        NUXT_PUBLIC_PUSHER_APP_KEY: 'acceptance-key',
        NUXT_PUBLIC_PUSHER_HOST: '127.0.0.1',
        NUXT_PUBLIC_PUSHER_PORT: '8080',
        NUXT_PUBLIC_PUSHER_SCHEME: 'http',
      },
    });

    log('setup', 'waiting for every process to become healthy...');
    await Promise.all([
      // No Authorization header at all -- under LAB_AUTH_MODE=anonymous this
      // still resolves (a fresh anonymous identity), which is itself part of
      // what this scenario is proving.
      waitForHttp('laravel', 'http://127.0.0.1/api/marketplace/services', { timeoutMs: 30000 }),
      waitForPort('reverb', '127.0.0.1', 8080, { timeoutMs: 30000 }),
      waitForHttp('mock-service', `http://127.0.0.1:${config.mockService.port}/health`, { timeoutMs: 15000 }),
      waitForHttp('marketplace-nuxt', 'http://127.0.0.1:3100/', { timeoutMs: 60000 }),
    ]);
    log('setup', 'all processes healthy -- starting launch-mode scenario');

    const scenario = require('./launch-mode-scenario');
    await scenario.run({ config, report, group, laravelEnv, root: ROOT });

    exitCode = report.failed.length > 0 ? 1 : 0;
  } catch (err) {
    console.error('\nFATAL (setup/teardown, not a scenario step):', err);
    exitCode = 1;
  } finally {
    await group.teardown();
    report.print(`${profile} (launch-mode)`);
  }

  process.exit(exitCode);
}

main();
