'use strict';

const { spawn, execSync } = require('child_process');

function log(name, ...args) {
  console.log(`[${name}]`, ...args);
}

/** Poll a URL until it responds (any status < 500 counts as "up and routing"). */
function waitForHttp(name, url, { timeoutMs = 30000, intervalMs = 300, headers = {} } = {}) {
  const deadline = Date.now() + timeoutMs;
  return new Promise((resolve, reject) => {
    const attempt = async () => {
      try {
        const res = await fetch(url, { headers });
        if (res.status < 500) {
          log(name, `healthy (${url} -> HTTP ${res.status})`);
          return resolve(true);
        }
      } catch {
        // not up yet
      }
      if (Date.now() > deadline) {
        return reject(new Error(`${name}: timed out waiting for ${url} to become healthy`));
      }
      setTimeout(attempt, intervalMs);
    };
    attempt();
  });
}

/** Poll a raw TCP port (for servers with no simple HTTP health endpoint). */
function waitForPort(name, host, port, { timeoutMs = 30000, intervalMs = 300 } = {}) {
  const net = require('net');
  const deadline = Date.now() + timeoutMs;
  return new Promise((resolve, reject) => {
    const attempt = () => {
      const socket = net.createConnection({ host, port }, () => {
        socket.end();
        log(name, `port ${port} open`);
        resolve(true);
      });
      socket.on('error', () => {
        socket.destroy();
        if (Date.now() > deadline) {
          return reject(new Error(`${name}: timed out waiting for ${host}:${port} to open`));
        }
        setTimeout(attempt, intervalMs);
      });
    };
    attempt();
  });
}

class ProcessGroup {
  constructor() {
    this.entries = [];
  }

  /**
   * Spawn a long-running process, streaming its output with a name prefix.
   * Runs detached (its own process group) so teardown can signal the whole
   * tree at once -- `nuxt dev` in particular forks its own child process,
   * and a plain SIGTERM to the top-level PID alone (or to `npm run dev`'s
   * PID, which never forwards signals to its own child) leaves the real
   * server running, holding the port and its dev-lock file, and confusing
   * the NEXT run's health check into passing against a stale process.
   */
  spawnProcess(name, command, args, opts = {}) {
    const proc = spawn(command, args, {
      stdio: ['ignore', 'pipe', 'pipe'],
      detached: true,
      ...opts,
    });
    proc.stdout.on('data', (d) => process.stdout.write(`[${name}] ${d}`));
    proc.stderr.on('data', (d) => process.stderr.write(`[${name}] ${d}`));
    proc.on('exit', (code, signal) => {
      if (code !== null && code !== 0) log(name, `exited with code ${code}`);
      if (signal) log(name, `killed by ${signal}`);
    });
    this.entries.push({ name, proc });
    return proc;
  }

  /** Run a one-shot command to completion (e.g. migrate:fresh --seed). */
  runOnce(name, command, args, opts = {}) {
    log(name, `${command} ${args.join(' ')}`);
    execSync(`${command} ${args.map((a) => `'${a.replace(/'/g, "'\\''")}'`).join(' ')}`, {
      stdio: 'inherit',
      ...opts,
    });
  }

  /**
   * Kill one named process and respawn it fresh with new args/env, leaving
   * every other process untouched. Used for the "core unreachable" failure
   * path (step 31), which needs the Laravel process restarted with a
   * deliberately-dead CORE_BASE_URL and then restarted again afterward with
   * the real one -- core connectivity is a whole-process config value, not
   * something any single request can be made to see differently.
   */
  async restartProcess(name, command, args, opts = {}) {
    const index = this.entries.findIndex((e) => e.name === name);
    if (index === -1) throw new Error(`restartProcess: no running process named "${name}"`);
    const { proc: oldProc } = this.entries[index];

    const exited = new Promise((resolve) => oldProc.once('exit', resolve));
    try {
      process.kill(-oldProc.pid, 'SIGTERM');
    } catch (e) {
      log(name, 'SIGTERM failed:', e.message);
    }
    await Promise.race([exited, new Promise((r) => setTimeout(r, 1500))]);
    if (oldProc.exitCode === null && oldProc.signalCode === null) {
      try {
        process.kill(-oldProc.pid, 'SIGKILL');
      } catch (e) {
        log(name, 'SIGKILL failed:', e.message);
      }
      await exited;
    }
    this.entries.splice(index, 1);

    log(name, 'restarting...');
    return this.spawnProcess(name, command, args, opts);
  }

  async teardown() {
    log('teardown', `stopping ${this.entries.length} process(es)`);
    for (const { name, proc } of [...this.entries].reverse()) {
      try {
        // Negative PID = signal the whole detached process group, not just
        // the top-level PID -- see spawnProcess()'s comment on why a plain
        // proc.kill() alone leaves nuxt's forked child (and the port it
        // holds) running.
        process.kill(-proc.pid, 'SIGTERM');
      } catch (e) {
        log(name, 'SIGTERM failed:', e.message);
      }
    }
    await new Promise((r) => setTimeout(r, 1500));
    for (const { name, proc } of this.entries) {
      try {
        if (proc.exitCode === null && proc.signalCode === null) process.kill(-proc.pid, 'SIGKILL');
      } catch (e) {
        log(name, 'SIGKILL failed:', e.message);
      }
    }
  }
}

module.exports = { waitForHttp, waitForPort, ProcessGroup, log };
