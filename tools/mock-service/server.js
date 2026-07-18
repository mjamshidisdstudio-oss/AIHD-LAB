#!/usr/bin/env node
'use strict';

/**
 * Standalone reference implementation of "contract v1" for an AIHD-LAB
 * external AI provider. Runs as its own process, on its own port, and knows
 * nothing about our internals beyond this HTTP contract -- it authenticates
 * and calls back into us exactly like a real third-party dev's server would.
 *
 * See README.md in this directory for the full contract and the 6 modes.
 * Zero npm dependencies on purpose: this file is meant to be read end to end
 * and copied as a starting point by a real integrator, not treated as a
 * black box.
 */

const http = require('http');
const crypto = require('crypto');

const PORT = parseInt(process.env.PORT || '4100', 10);
const OUR_BASE_URL = (process.env.OUR_BASE_URL || 'http://127.0.0.1').replace(/\/$/, '');
const SHARED_KEY = process.env.SHARED_KEY;
const PROCESSING_DELAY_MS = parseInt(process.env.PROCESSING_DELAY_MS || '500', 10);
const SLOW_DELAY_MS = parseInt(process.env.SLOW_DELAY_MS || '15000', 10);

if (!SHARED_KEY) {
  console.error('[mock-service] SHARED_KEY env var is required -- set it to the ' +
    "service's webhook_signing_key (the shared secret configured in the admin UI).");
  process.exit(1);
}

// --- Mutable runtime state, controlled by the acceptance suite -----------

/** @type {'normal'|'silent'|'bad-signature'|'failing'|'slow'|'duplicate'} */
let currentMode = process.env.DEFAULT_MODE || 'normal';

/** Set once the acceptance suite has created the service and knows its id. */
let webhookUrl = process.env.WEBHOOK_URL || null;

/** externalOrderId -> job record. In-memory only; this process IS the store. */
const jobs = new Map();

function log(...args) {
  console.log('[mock-service]', ...args);
}

// --- Tiny helpers, no framework ------------------------------------------

function readRawBody(req) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    req.on('data', (c) => chunks.push(c));
    req.on('end', () => resolve(Buffer.concat(chunks)));
    req.on('error', reject);
  });
}

function sendJson(res, status, body) {
  const payload = JSON.stringify(body);
  res.writeHead(status, {
    'Content-Type': 'application/json',
    'Content-Length': Buffer.byteLength(payload),
  });
  res.end(payload);
}

function bearerFrom(req) {
  const header = req.headers['authorization'] || '';
  return header.startsWith('Bearer ') ? header.slice('Bearer '.length) : null;
}

function hmacSign(rawBody) {
  return crypto.createHmac('sha256', SHARED_KEY).update(rawBody, 'utf8').digest('hex');
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

// --- Calling back into our system -----------------------------------------

async function downloadMedia(mediaId, job) {
  const res = await fetch(`${OUR_BASE_URL}/api/storage/${mediaId}`, {
    headers: { Authorization: `Bearer ${SHARED_KEY}` },
  });
  if (!res.ok) {
    throw new Error(`download ${mediaId} failed: HTTP ${res.status}`);
  }
  const buf = Buffer.from(await res.arrayBuffer());
  const mime = res.headers.get('content-type') || 'application/octet-stream';
  if (job) job.downloadedMediaIds.push(mediaId);
  return { buf, mime };
}

async function uploadResult(orderId, buf, mime, filename) {
  const form = new FormData();
  form.append('order_id', orderId);
  form.append('file', new Blob([buf], { type: mime }), filename);

  const res = await fetch(`${OUR_BASE_URL}/api/storage`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${SHARED_KEY}` },
    body: form,
  });
  if (!res.ok) {
    throw new Error(`upload failed: HTTP ${res.status} ${await res.text()}`);
  }
  const data = await res.json();
  return data.media_id;
}

async function postWebhook(body, { badSignature = false } = {}) {
  if (!webhookUrl) {
    log('WARNING: no webhook_url configured (POST /admin/configure) -- skipping webhook.');
    return;
  }
  const raw = JSON.stringify(body);
  const signature = badSignature ? '0'.repeat(64) : hmacSign(raw);

  const res = await fetch(webhookUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Signature': signature },
    body: raw,
  });
  log(`webhook -> ${webhookUrl}: HTTP ${res.status}`, body.result_number ?? body.status);
}

// --- "Processing" a result -------------------------------------------------

/**
 * A deterministic, dependency-free stand-in for "resize/tint/whatever": XOR
 * every byte with a constant derived from the result_number, so each output
 * is a genuine, different transform of the real input bytes -- never a copy
 * of the input, and never identical across result_numbers.
 */
function tint(buf, resultNumber) {
  const key = ((resultNumber * 37 + 11) % 251) || 1;
  const out = Buffer.alloc(buf.length);
  for (let i = 0; i < buf.length; i++) out[i] = buf[i] ^ key;
  return out;
}

async function processJob(externalOrderId) {
  const job = jobs.get(externalOrderId);
  if (!job) return;

  if (job.mode === 'slow') {
    // Never completes -- GET /jobs deliberately outlasts the client's own
    // response_timeout_s on every poll, so our side times out at the
    // transport layer itself instead of ever seeing a status.
    return;
  }

  try {
    let inputImage = null;
    const firstMedia = job.mediaIds[0];
    if (firstMedia) {
      inputImage = await downloadMedia(firstMedia.media_id, job);
    }

    const results = [];
    for (const output of job.expectedOutputs) {
      if (output.type === 'text') {
        results.push({ result_number: output.result_number, type: 'text', text: `Mock result #${output.result_number} for order ${job.orderId}` });
        continue;
      }

      const base = inputImage ? inputImage.buf : Buffer.from(`AIHD-MOCK-${output.type}-${output.result_number}`);
      const mime = inputImage ? inputImage.mime : (output.type === 'video' ? 'video/mp4' : 'image/png');
      const transformed = tint(base, output.result_number);
      const mediaId = await uploadResult(job.orderId, transformed, mime, `result-${output.result_number}`);
      results.push({ result_number: output.result_number, type: output.type, media_id: mediaId });
    }

    if (job.mode === 'failing') {
      job.status = 'failed';
      job.reason = 'mock: provider reported a processing failure';
      await postWebhook({ external_order_id: externalOrderId, status: 'failed', reason: job.reason });
      return;
    }

    job.status = 'completed';
    job.results = results;
    job.latencyMs = Date.now() - job.createdAt;

    if (job.mode === 'silent') {
      log(`job ${externalOrderId}: completed silently (no webhook) -- pollable via GET /jobs`);
      return;
    }

    for (const r of results) {
      await postWebhook({ external_order_id: externalOrderId, ...r }, { badSignature: job.mode === 'bad-signature' });
      if (job.mode === 'duplicate') {
        await sleep(150);
        await postWebhook({ external_order_id: externalOrderId, ...r });
      }
    }
  } catch (err) {
    log(`job ${externalOrderId} processing error:`, err.message);
    job.status = 'failed';
    job.reason = `mock: processing error -- ${err.message}`;
  }
}

// --- Routes -----------------------------------------------------------------

async function handleRun(req, res) {
  if (bearerFrom(req) !== SHARED_KEY) {
    return sendJson(res, 401, { message: 'Unauthorized.' });
  }

  const raw = await readRawBody(req);
  let payload;
  try {
    payload = JSON.parse(raw.toString('utf8'));
  } catch {
    return sendJson(res, 422, { message: 'Invalid JSON.' });
  }

  const externalOrderId = crypto.randomUUID();
  jobs.set(externalOrderId, {
    orderId: payload.order_id,
    mediaIds: Array.isArray(payload.media_ids) ? payload.media_ids : [],
    expectedOutputs: Array.isArray(payload.expected_outputs) ? payload.expected_outputs : [],
    mode: currentMode, // snapshotted at submission time
    status: 'processing',
    results: [],
    reason: null,
    createdAt: Date.now(),
    // Debug trail only (GET /admin/jobs) -- not part of contract v1. Lets the
    // acceptance suite prove, from the outside, that this process really did
    // fetch the input it was told about rather than just trusting its own
    // internal call succeeded silently.
    downloadedMediaIds: [],
  });

  log(`POST /run: order ${payload.order_id} -> external_order_id ${externalOrderId} (mode=${currentMode})`);
  sendJson(res, 200, { external_order_id: externalOrderId, status: 'accepted' });

  setTimeout(() => {
    processJob(externalOrderId).catch((err) => log('unhandled processJob error:', err));
  }, PROCESSING_DELAY_MS);
}

async function handleJobs(req, res, url) {
  if (bearerFrom(req) !== SHARED_KEY) {
    return sendJson(res, 401, { message: 'Unauthorized.' });
  }

  const externalOrderId = url.searchParams.get('external_order_id');
  const job = jobs.get(externalOrderId);
  if (!job) {
    return sendJson(res, 404, { status: 'unknown' });
  }

  if (job.mode === 'slow') {
    await sleep(SLOW_DELAY_MS);
    // Falls through and answers eventually; the point is that the CLIENT's
    // own response_timeout_s elapses first and this response is never seen.
  }

  if (job.status === 'failed') {
    return sendJson(res, 200, { status: 'failed', reason: job.reason });
  }
  if (job.status !== 'completed') {
    return sendJson(res, 200, { status: 'pending' });
  }

  sendJson(res, 200, { status: 'completed', latency_ms: job.latencyMs, results: job.results });
}

function handleHealth(res) {
  sendJson(res, 200, { status: 'ok', mode: currentMode, jobs: jobs.size });
}

async function handleAdminMode(req, res) {
  const raw = await readRawBody(req);
  const body = JSON.parse(raw.toString('utf8') || '{}');
  const allowed = ['normal', 'silent', 'bad-signature', 'failing', 'slow', 'duplicate'];
  if (!allowed.includes(body.mode)) {
    return sendJson(res, 422, { message: `mode must be one of: ${allowed.join(', ')}` });
  }
  currentMode = body.mode;
  log(`mode -> ${currentMode}`);
  sendJson(res, 200, { mode: currentMode });
}

async function handleAdminConfigure(req, res) {
  const raw = await readRawBody(req);
  const body = JSON.parse(raw.toString('utf8') || '{}');
  if (typeof body.webhook_url === 'string') {
    webhookUrl = body.webhook_url;
    log(`webhook_url -> ${webhookUrl}`);
  }
  sendJson(res, 200, { webhook_url: webhookUrl });
}

function handleAdminReset(req, res) {
  jobs.clear();
  currentMode = 'normal';
  log('reset: cleared jobs, mode -> normal');
  sendJson(res, 200, { ok: true });
}

/**
 * Debug/introspection surface only -- not part of contract v1 (a real
 * provider has no such endpoint). Lets the acceptance suite verify, from
 * outside this process, things it otherwise couldn't observe: that this
 * service really received a given order_id, really fetched a given media_id
 * via GET /storage, and really reported the media_ids it uploaded back out.
 */
function handleAdminJobs(res) {
  const list = Array.from(jobs.entries()).map(([externalOrderId, job]) => ({
    external_order_id: externalOrderId,
    order_id: job.orderId,
    mode: job.mode,
    status: job.status,
    media_ids_provided: job.mediaIds.map((m) => m.media_id),
    downloaded_media_ids: job.downloadedMediaIds,
    results: job.results.map((r) => ({ result_number: r.result_number, type: r.type, media_id: r.media_id ?? null })),
  }));
  sendJson(res, 200, { jobs: list });
}

const server = http.createServer((req, res) => {
  const url = new URL(req.url, `http://${req.headers.host}`);

  const route = `${req.method} ${url.pathname}`;
  const dispatch = async () => {
    if (route === 'POST /run') return handleRun(req, res);
    if (route === 'GET /jobs') return handleJobs(req, res, url);
    if (route === 'GET /health') return handleHealth(res);
    if (route === 'POST /admin/mode') return handleAdminMode(req, res);
    if (route === 'POST /admin/configure') return handleAdminConfigure(req, res);
    if (route === 'POST /admin/reset') return handleAdminReset(req, res);
    if (route === 'GET /admin/jobs') return handleAdminJobs(res);
    sendJson(res, 404, { message: 'Not found.' });
  };

  dispatch().catch((err) => {
    log('request error:', err);
    if (!res.headersSent) sendJson(res, 500, { message: 'Internal mock-service error.', detail: err.message });
  });
});

server.listen(PORT, () => {
  log(`listening on :${PORT}, OUR_BASE_URL=${OUR_BASE_URL}, mode=${currentMode}, processing_delay_ms=${PROCESSING_DELAY_MS}`);
});

process.on('SIGTERM', () => server.close(() => process.exit(0)));
process.on('SIGINT', () => server.close(() => process.exit(0)));
