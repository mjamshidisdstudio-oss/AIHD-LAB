'use strict';

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const ROOT = path.resolve(__dirname, '..', '..', '..');
const DB_NAME = 'aihd_lab_acceptance';

/** Same .env fallback resolution lib/db.js uses for the Laravel connection. */
function readEnvFile() {
  const values = {};
  const raw = fs.readFileSync(path.join(ROOT, '.env'), 'utf8');
  for (const line of raw.split('\n')) {
    const m = line.match(/^([A-Z_][A-Z0-9_]*)=(.*)$/);
    if (m) values[m[1]] = m[2];
  }
  return values;
}

const envFile = readEnvFile();
const HOST = process.env.ACCEPTANCE_DB_HOST || envFile.DB_HOST || '127.0.0.1';
const PORT = process.env.ACCEPTANCE_DB_PORT || envFile.DB_PORT || '3306';
const USER = process.env.ACCEPTANCE_DB_USERNAME || envFile.DB_USERNAME || 'root';
const PASSWORD = process.env.ACCEPTANCE_DB_PASSWORD !== undefined
  ? process.env.ACCEPTANCE_DB_PASSWORD
  : (envFile.DB_PASSWORD || '');

/**
 * Test-arrangement only -- same category as lib/api.js's CoreStubApi.deduct
 * ("drain a user's balance via the real dev-core deduct endpoint... so a
 * later submit can be shown to hit a genuine 402"). Never used to assert
 * application behavior, only to set up a fixture this suite has no other way
 * to reach: season-gen is a SEEDED, already-PUBLISHED service, and a
 * published version's post_url/get_url are frozen by the same invariant that
 * protects any real published version -- there is no admin-API path to
 * redirect it at the standalone mock-service the rest of this suite uses,
 * unlike scenario.js's own fixture services, which are built fresh through
 * the real admin API and can just be pointed at ctx.mockUrl from the start.
 */
function pointSeasonGenAtMockService(mockUrl) {
  const passwordArgs = PASSWORD ? [`-p${PASSWORD}`] : [];
  const sql = `UPDATE service_versions sv JOIN services s ON s.id = sv.service_id `
    + `SET sv.post_url = '${mockUrl}/run', sv.get_url = '${mockUrl}/jobs' `
    + `WHERE s.slug = 'season-gen';`;
  execFileSync('mysql', ['-h', HOST, '-P', String(PORT), '-u', USER, ...passwordArgs, '-B', DB_NAME, '-e', sql]);
}

module.exports = { pointSeasonGenAtMockService };
