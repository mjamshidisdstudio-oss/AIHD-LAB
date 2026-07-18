'use strict';

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const ROOT = path.resolve(__dirname, '..', '..', '..');
const DB_NAME = 'aihd_lab_acceptance';

/** Same .env fallback resolution run.js uses for the Laravel connection. */
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
 * Direct read-only DB access, for the handful of assertions no API surface
 * answers (e.g. "exactly one service_votes row", which only an aggregate
 * vote_up/vote_down counter is otherwise visible through). Never used to
 * drive application behavior -- only to verify it after a real UI action.
 *
 * @returns {string|null} the first column of the first row, or null.
 */
function queryScalar(sql) {
  const passwordArgs = PASSWORD ? [`-p${PASSWORD}`] : [];
  const args = ['-h', HOST, '-P', String(PORT), '-u', USER, ...passwordArgs, '-N', '-B', DB_NAME, '-e', sql];
  const out = execFileSync('mysql', args, { encoding: 'utf8' });
  const trimmed = out.trim();
  return trimmed === '' ? null : trimmed;
}

/**
 * Like queryScalar, but for a single-column, multi-row query -- returns the
 * first column of every returned row. Used to independently derive expected
 * ID sets (e.g. "every order for this service with source=site") so a
 * filtered admin API response can be compared against real ground truth
 * rather than a hand-counted total.
 *
 * @returns {string[]}
 */
function queryColumn(sql) {
  const passwordArgs = PASSWORD ? [`-p${PASSWORD}`] : [];
  const args = ['-h', HOST, '-P', String(PORT), '-u', USER, ...passwordArgs, '-N', '-B', DB_NAME, '-e', sql];
  const out = execFileSync('mysql', args, { encoding: 'utf8' });
  return out.split('\n').map((line) => line.trim()).filter((line) => line !== '');
}

module.exports = { queryScalar, queryColumn };
