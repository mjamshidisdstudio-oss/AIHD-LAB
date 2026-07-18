'use strict';

/**
 * Tracks pass/fail per numbered acceptance-spec step. Every step runs inside
 * its own try/catch so one failure never stops the rest of the run --
 * "Continue past a fixed failure to find the NEXT one."
 */
class Report {
  constructor() {
    this.steps = [];
    this.startedAt = Date.now();
  }

  async step(number, title, fn) {
    const start = Date.now();
    process.stdout.write(`\n--- Step ${number}: ${title} ---\n`);
    try {
      await fn();
      this.steps.push({ number, title, status: 'PASS', ms: Date.now() - start });
      console.log(`  [PASS] (${Date.now() - start}ms)`);
    } catch (err) {
      this.steps.push({
        number,
        title,
        status: 'FAIL',
        ms: Date.now() - start,
        error: err && err.message ? err.message : String(err),
        stack: err && err.stack,
      });
      console.log(`  [FAIL] ${err && err.message ? err.message : err}`);
    }
  }

  get failed() {
    return this.steps.filter((s) => s.status === 'FAIL');
  }

  print(profileName) {
    const totalMs = Date.now() - this.startedAt;
    console.log('\n' + '='.repeat(70));
    console.log(`ACCEPTANCE REPORT -- profile: ${profileName}`);
    console.log('='.repeat(70));
    for (const s of this.steps) {
      console.log(`  ${s.status.padEnd(5)} Step ${String(s.number).padStart(2)}: ${s.title}`);
      if (s.status === 'FAIL') console.log(`         -> ${s.error}`);
    }
    console.log('-'.repeat(70));
    console.log(`  ${this.steps.length} steps, ${this.steps.length - this.failed.length} passed, ${this.failed.length} failed`);
    console.log(`  wall-clock: ${(totalMs / 1000).toFixed(1)}s`);
    console.log('='.repeat(70) + '\n');
  }
}

module.exports = { Report };
