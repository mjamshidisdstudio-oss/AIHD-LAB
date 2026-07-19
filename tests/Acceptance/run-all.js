#!/usr/bin/env node
'use strict';

/**
 * Runs BOTH acceptance scenarios back to back -- the existing 35-step
 * full-path suite (run.js, untouched by Phase L3) and the launch-mode
 * scenario (run-launch-mode.js) -- and prints one combined tally at the end.
 * Each runs as its own child process (each owns a full DB/port lifecycle via
 * its own ProcessGroup, and calling either's main() in-process would exit
 * this process early via process.exit()), sequentially, since both hard-code
 * the same ports/DB name and were never designed to run concurrently.
 *
 * Usage: node run-all.js [--profile=fast|realistic]
 */

const path = require('path');
const { spawnSync } = require('child_process');

function parseArgs() {
  const profileArg = process.argv.find((a) => a.startsWith('--profile='));
  const profile = profileArg ? profileArg.split('=')[1] : (process.env.ACCEPTANCE_PROFILE || 'fast');
  return { profile };
}

function runScenario(label, scriptName, profile) {
  console.log(`\n${'#'.repeat(70)}\n# Running: ${label} (profile: ${profile})\n${'#'.repeat(70)}\n`);
  const result = spawnSync('node', [path.join(__dirname, scriptName), `--profile=${profile}`], {
    stdio: 'inherit',
    env: process.env,
  });
  return { label, status: result.status === 0 ? 'PASS' : 'FAIL', exitCode: result.status };
}

function main() {
  const { profile } = parseArgs();

  const results = [
    runScenario('full-path (existing 35-step suite)', 'run.js', profile),
    runScenario('launch-mode', 'run-launch-mode.js', profile),
  ];

  console.log('\n' + '='.repeat(70));
  console.log(`COMBINED ACCEPTANCE TALLY -- profile: ${profile}`);
  console.log('='.repeat(70));
  for (const r of results) {
    console.log(`  ${r.status.padEnd(5)} ${r.label} (exit ${r.exitCode})`);
  }
  const overall = results.every((r) => r.status === 'PASS') ? 'PASS' : 'FAIL';
  console.log('-'.repeat(70));
  console.log(`  Overall: ${overall}`);
  console.log('='.repeat(70) + '\n');

  process.exit(overall === 'PASS' ? 0 : 1);
}

main();
