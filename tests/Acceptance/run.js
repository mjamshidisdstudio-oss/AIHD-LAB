#!/usr/bin/env node
'use strict';

/**
 * One-command entrypoint for the AIHD-LAB acceptance suite. Boots every
 * process the suite needs (fresh DB, Laravel, Reverb, both Nuxt apps, the
 * mock external service), waits for each to be healthy, runs the 35-step
 * scenario, and tears everything down on exit -- success, failure, or an
 * uncaught error alike.
 *
 * Usage: node run.js [--profile=fast|realistic]
 */

const path = require('path');
const fs = require('fs');
const { execSync } = require('child_process');
const { waitForHttp, waitForPort, ProcessGroup, log } = require('./lib/processes');
const { Report } = require('./lib/report');

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
  console.log(`\nAIHD-LAB acceptance suite -- profile: ${profile} (${config.description})\n`);

  const dbName = 'aihd_lab_acceptance';
  const dbRootUser = process.env.ACCEPTANCE_DB_ROOT_USER || 'root';
  const dbRootPassword = process.env.ACCEPTANCE_DB_ROOT_PASSWORD || '';

  const laravelEnv = {
    ...process.env,
    APP_ENV: 'local',
    DB_DATABASE: dbName,
    // Defaults to whatever .env already has (this sandbox's DB user is
    // pre-granted access to specific database names, not a wildcard) --
    // set ACCEPTANCE_DB_USERNAME/PASSWORD to override, as CI does (root,
    // since a fresh CI MySQL container has no such restriction).
    ...(process.env.ACCEPTANCE_DB_USERNAME ? { DB_USERNAME: process.env.ACCEPTANCE_DB_USERNAME } : {}),
    ...(process.env.ACCEPTANCE_DB_PASSWORD !== undefined ? { DB_PASSWORD: process.env.ACCEPTANCE_DB_PASSWORD } : {}),
    // NOT 'array': LocalCoreStubState (balances, held txns, tokens) is
    // Cache-backed by design, and this suite makes genuinely separate HTTP
    // requests against a real running server -- 'array' cache lives only for
    // the lifetime of a single PHP request, so a deduct made by one request
    // is invisible to a balance check made by the next (this only ever
    // looked fine in PHPUnit feature tests, which share one process for the
    // whole test method). 'file' persists across real requests/workers with
    // no new dependency; cache:clear below gives it the same clean-state
    // guarantee migrate:fresh gives the database.
    CACHE_STORE: 'file',
    SESSION_DRIVER: 'array',
    QUEUE_CONNECTION: 'sync',
    // Real (local) broadcasting -- this environment (and typical CI runners)
    // cannot reach a real Pusher cloud account, and depending on one would
    // make a nightly test fragile on an external paid service. Reverb is
    // open-source and self-hosted, so Echo/websocket completion (step 14)
    // can be genuinely exercised end to end.
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

    log('setup', 'cache:clear (clean-state guarantee for the file-backed CoreStub)');
    execSync('php artisan cache:clear', { stdio: 'inherit', cwd: ROOT, env: laravelEnv });

    // --- Boot every process the suite needs, in parallel -------------------
    group.spawnProcess('laravel', 'php', ['artisan', 'serve', '--port=80', '--no-reload'], { cwd: ROOT, env: laravelEnv });
    group.spawnProcess('reverb', 'php', ['artisan', 'reverb:start', '--port=8080'], { cwd: ROOT, env: laravelEnv });
    group.spawnProcess('mock-service', 'node', ['server.js'], {
      cwd: path.join(ROOT, 'tools', 'mock-service'),
      env: {
        ...process.env,
        PORT: String(config.mockService.port),
        OUR_BASE_URL: 'http://127.0.0.1',
        SHARED_KEY: 'acceptance-shared-key',
        PROCESSING_DELAY_MS: String(config.mockService.processingDelayMs),
        SLOW_DELAY_MS: String(config.mockService.slowDelayMs),
      },
    });
    group.spawnProcess('admin-nuxt', 'npm', ['run', 'dev', '--', '--port=3200'], {
      cwd: path.join(ROOT, 'admin'),
      env: { ...process.env, NUXT_PUBLIC_API_BASE: 'http://127.0.0.1/api' },
    });
    group.spawnProcess('marketplace-nuxt', 'npm', ['run', 'dev', '--', '--port=3100'], {
      cwd: path.join(ROOT, 'marketplace'),
      env: {
        ...process.env,
        NUXT_PUBLIC_API_BASE: 'http://127.0.0.1/api',
        NUXT_PUBLIC_DEV_TOKEN: 'dev-token',
        NUXT_PUBLIC_PUSHER_APP_KEY: 'acceptance-key',
        NUXT_PUBLIC_PUSHER_HOST: '127.0.0.1',
        NUXT_PUBLIC_PUSHER_PORT: '8080',
        NUXT_PUBLIC_PUSHER_SCHEME: 'http',
      },
    });

    log('setup', 'waiting for every process to become healthy...');
    await Promise.all([
      waitForHttp('laravel', 'http://127.0.0.1/api/marketplace/services', { headers: { Authorization: 'Bearer dev-token' }, timeoutMs: 30000 }),
      waitForPort('reverb', '127.0.0.1', 8080, { timeoutMs: 30000 }),
      waitForHttp('mock-service', `http://127.0.0.1:${config.mockService.port}/health`, { timeoutMs: 15000 }),
      waitForHttp('admin-nuxt', 'http://127.0.0.1:3200/login', { timeoutMs: 60000 }),
      waitForHttp('marketplace-nuxt', 'http://127.0.0.1:3100/', { timeoutMs: 60000 }),
    ]);
    log('setup', 'all processes healthy -- starting scenario');

    const scenario = require('./scenario');
    await scenario.run({ config, report });

    exitCode = report.failed.length > 0 ? 1 : 0;
  } catch (err) {
    console.error('\nFATAL (setup/teardown, not a scenario step):', err);
    exitCode = 1;
  } finally {
    await group.teardown();
    report.print(profile);
  }

  process.exit(exitCode);
}

main();
