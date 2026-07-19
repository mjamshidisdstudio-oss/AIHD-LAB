'use strict';

const assert = require('assert');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { chromium } = require('playwright');
const { AdminApi, MockControl } = require('./lib/api');
const db = require('./lib/db');

const MARKETPLACE_URL = 'http://127.0.0.1:3100';
const API_BASE = 'http://127.0.0.1/api';
const SANDBOX_CHROMIUM_PATH = '/opt/pw-browsers/chromium';

const ADMIN_EMAIL = 'admin@aihd.lab';
const ADMIN_PASSWORD = 'password';
const SERVICE_NAME = 'Seasonal Views';
const IMAGE_FIXTURE = path.join(__dirname, 'fixtures', 'room-photo.png');

const POLL_FALLBACK_MS = 4000;

/**
 * Phase L3: the opposite corner of the same flag space the existing 35-step
 * suite exercises. LAB_BILLING_ENABLED=false / LAB_AUTH_MODE=anonymous are
 * already set on the Laravel process this scenario runs against (see
 * run-launch-mode.js) -- this file only drives the browser and asserts.
 *
 * season-gen (Seasonal Views) is the seeded, already-published, coin_cost=0
 * service -- exactly the "Free badge" fixture the full-path suite's own step
 * 9 checks against, reused here as the ONE thing a no-login launch-mode
 * visitor actually runs, rather than building a fresh fixture service (there
 * is no admin journey in this scenario to build one with).
 */
async function run({ config, report }) {
  const launchOptions = fs.existsSync(SANDBOX_CHROMIUM_PATH) ? { executablePath: SANDBOX_CHROMIUM_PATH } : {};
  const browser = await chromium.launch(launchOptions);

  const admin = new AdminApi(API_BASE);
  await admin.login(ADMIN_EMAIL, ADMIN_PASSWORD);

  const services = await admin.get('/admin/services');
  const seasonGen = services.data.data.find((s) => s.slug === 'season-gen');
  assert.ok(seasonGen, 'expected the seeded season-gen service to exist');
  const serviceId = seasonGen.id;

  const mock = new MockControl(`http://127.0.0.1:${config.mockService.port}`);
  await mock.configure(`http://127.0.0.1/api/webhooks/${serviceId}/results`);

  const page = await browser.newPage({ viewport: { width: 1440, height: 960 } });
  const wsFrames = [];
  page.on('websocket', (ws) => {
    ws.on('framereceived', (evt) => {
      const payload = typeof evt.payload === 'string' ? evt.payload : evt.payload.toString('utf8');
      wsFrames.push({ t: Date.now(), payload });
    });
  });
  page.on('console', (msg) => {
    if (msg.type() === 'error') console.log('  [marketplace console error]', msg.text());
  });
  page.on('pageerror', (err) => {
    console.log('  [marketplace page error]', err.message);
  });

  const state = {};

  try {
    await report.step(1, 'Grid: a no-login visitor sees the seeded Seasonal Views service, with a Free badge for coin_cost=0', async () => {
      await page.goto(`${MARKETPLACE_URL}/`, { waitUntil: 'load' });
      await page.waitForSelector(`text=${SERVICE_NAME}`, { timeout: 20000 });

      const card = page.locator('span', { hasText: SERVICE_NAME }).locator('xpath=ancestor::div[contains(@class,"cursor-pointer")][1]');
      // Exact (quoted) match -- an unquoted text=Free would also match any
      // service name/tagline that happened to contain the substring "free".
      assert.strictEqual(await card.locator('text="Free"').count(), 1, 'expected the Free badge on the coin_cost=0 seeded service');
    });

    await report.step(2, 'Anonymous identity: a signed per-visitor cookie is issued to a browser presenting no credential at all', async () => {
      const cookies = await page.context().cookies();
      const anon = cookies.find((c) => c.name === 'aihd_anon_id');
      assert.ok(anon, 'expected the aihd_anon_id cookie to be set on first contact');
      state.cookieValueAfterFirstContact = anon.value;
    });

    await report.step(3, 'Wizard: complete Seasonal Views with a real uploaded room photo; the Style select is gated on Room Type', async () => {
      await page.locator('span', { hasText: SERVICE_NAME }).click();
      await page.waitForSelector(`h1:has-text("${SERVICE_NAME}")`, { timeout: 15000 });
      await page.locator('button', { hasText: /Use this service/ }).click();
      await page.waitForSelector('text=Step 1 of', { timeout: 15000 });

      // Room Photo -- required image upload.
      await page.setInputFiles('input[type="file"]', IMAGE_FIXTURE);
      await page.waitForTimeout(200);
      await page.locator('button', { hasText: /^\s*Next\s*$/ }).click();

      // Room Type -- ungated select; choosing it reveals the gated Style step.
      await page.waitForSelector('text=Step 2 of 3', { timeout: 5000 });
      await page.locator('button', { hasText: /^\s*Bedroom\s*$/ }).click();
      await page.waitForSelector('text=Step 2 of 4', { timeout: 5000 });
      await page.locator('button', { hasText: /^\s*Next\s*$/ }).click();

      // Style -- gated on Room Type=Bedroom (Cozy/Boho only).
      await page.waitForSelector('text=Step 3 of 4', { timeout: 5000 });
      const styleOptionCount = await page.locator('button', { hasText: /^\s*(Cozy|Boho)\s*$/ }).count();
      assert.strictEqual(styleOptionCount, 2, `expected exactly the 2 Bedroom-gated Style options, got ${styleOptionCount}`);
      await page.locator('button', { hasText: /^\s*Cozy\s*$/ }).click();
      await page.locator('button', { hasText: /^\s*Next\s*$/ }).click();

      // HD Output -- optional boolean toggle, exercised but never blocking.
      await page.waitForSelector('text=Step 4 of 4', { timeout: 5000 });
      await page.locator('button[title="Toggle"]').click();

      const [resp] = await Promise.all([
        page.waitForResponse(
          (r) => r.request().method() === 'POST' && /\/orders$/.test(new URL(r.url()).pathname),
          { timeout: 10000 },
        ),
        page.locator('button', { hasText: /Generate Result/ }).click(),
      ]);
      state.submittedAt = Date.now();
      assert.strictEqual(resp.status(), 202, `expected 202 Accepted from POST /orders, got ${resp.status()}`);
      const body = await resp.json();
      state.orderId = body.data.id;
      assert.ok(String(body.data.user_ref).startsWith('anon-'), `expected an anonymous user_ref on the order response, got: ${body.data.user_ref}`);
      state.userRef = body.data.user_ref;
    });

    await report.step(4, 'Billing off: the order is attributed to the anonymous visitor, and NullCoinService -- not CoreCoinService -- actually ran (never a deduct/settle/refund with real effect)', async () => {
      const order = await admin.get(`/admin/orders/${state.orderId}`);
      assert.strictEqual(order.data.data.service_id, serviceId);
      assert.strictEqual(order.data.data.user_ref, state.userRef);
      assert.strictEqual(order.data.data.coins_charged, 0, 'expected the seeded coin_cost=0 to be recorded as-is');
      // NullCoinService.deduct() returns a deterministic "null-txn:{key}"
      // ref -- CoreCoinService's real refs never take this shape. This is
      // the one externally-observable fingerprint that distinguishes which
      // implementation genuinely ran, rather than just "cost happened to be
      // zero already".
      assert.ok(
        String(order.data.data.coin_txn_ref).startsWith('null-txn:'),
        `expected a NullCoinService-shaped coin_txn_ref, got: ${order.data.data.coin_txn_ref}`,
      );
    });

    await report.step(5, 'The external mock service genuinely fetched the room photo and uploaded results through our storage API (never a direct disk path)', async () => {
      const order = await admin.get(`/admin/orders/${state.orderId}`);
      const roomPhotoInput = order.data.data.inputs.find((i) => i.input_slug === 'room_photo');
      assert.ok(roomPhotoInput && roomPhotoInput.files && roomPhotoInput.files[0], 'expected the uploaded room_photo file to be recorded on the order');
      const mediaId = roomPhotoInput.files[0].id;

      const mockUrl = `http://127.0.0.1:${config.mockService.port}`;
      let job = null;
      for (let i = 0; i < 40 && !job; i++) {
        const res = await fetch(`${mockUrl}/admin/jobs`);
        const jobs = (await res.json()).jobs;
        job = jobs.find((j) => j.order_id === state.orderId);
        if (!job) await new Promise((r) => setTimeout(r, 150));
      }
      assert.ok(job, "the external mock service never received this order's POST /run");

      for (let i = 0; i < 40 && !job.downloaded_media_ids.includes(mediaId); i++) {
        await new Promise((r) => setTimeout(r, 150));
        const res = await fetch(`${mockUrl}/admin/jobs`);
        const jobs = (await res.json()).jobs;
        job = jobs.find((j) => j.order_id === state.orderId) || job;
      }
      assert.ok(job.downloaded_media_ids.includes(mediaId), 'the external mock service never fetched the room_photo input via GET /storage/{media_id}');
      state.externalOrderId = job.external_order_id;
    });

    await report.step(6, 'Completion arrives via Echo (socket), not by polling', async () => {
      const deadline = Date.now() + 30000;
      let completed = false;
      while (Date.now() < deadline && !completed) {
        completed = (await page.locator('text=Your result is ready!').count()) > 0;
        if (!completed) await page.waitForTimeout(150);
      }
      state.completedAt = Date.now();
      if (!completed) {
        console.log('  [debug] wsFrames captured:', wsFrames.length);
        for (const f of wsFrames) console.log('  [debug] frame:', f.payload.slice(0, 200));
      }
      assert.ok(completed, 'order never reached the results panel within 30s');

      const relevantFrames = wsFrames.filter(
        (f) => f.t >= state.submittedAt && f.t <= state.completedAt + 300 && f.payload.includes('order.completed'),
      );
      assert.ok(relevantFrames.length > 0, 'never observed an order.completed websocket frame for this order');
      const pushArrivedAt = relevantFrames[0].t;
      const elapsed = pushArrivedAt - state.submittedAt;
      assert.ok(
        elapsed < POLL_FALLBACK_MS,
        `completion took ${elapsed}ms -- not clearly faster than the ${POLL_FALLBACK_MS}ms poll fallback, so push cannot be distinguished from poll`,
      );
    });

    await report.step(7, 'Download routes through the logging endpoint (never a raw storage URL) and logs an interaction under the anonymous user_ref', async () => {
      const storageRequests = [];
      const onReq = (req) => {
        if (new URL(req.url()).pathname.startsWith('/api/storage')) storageRequests.push(req.url());
      };
      page.on('request', onReq);
      let savedPath;
      try {
        const [download] = await Promise.all([
          page.waitForEvent('download', { timeout: 8000 }),
          page.locator('button', { hasText: /^\s*Download\s*$/ }).first().click(),
        ]);
        savedPath = path.join(os.tmpdir(), `acceptance-launch-mode-result-${Date.now()}`);
        await download.saveAs(savedPath);
      } finally {
        page.off('request', onReq);
      }
      const bytes = fs.readFileSync(savedPath);
      assert.ok(bytes.length > 0, 'downloaded result file was empty');
      fs.unlinkSync(savedPath);
      assert.strictEqual(storageRequests.length, 0, `the browser made a direct request to a raw storage URL: ${storageRequests[0]}`);

      const rowCount = db.queryScalar(
        `SELECT COUNT(*) FROM interactions WHERE kind='download' AND user_ref='${state.userRef}' AND order_id='${state.orderId}'`,
      );
      assert.strictEqual(rowCount, '1', `expected exactly one download interaction for this anonymous visitor's order, got ${rowCount}`);
    });

    await report.step(8, 'Vote and bookmark attribute to the SAME stable anonymous user_ref the order used -- not a fresh identity per action', async () => {
      // Back to the plain detail page -- the vote/bookmark buttons live in
      // its aside, not inside the wizard/results overlay.
      await page.locator('button[title="Close"]').click();
      await page.waitForSelector(`h1:has-text("${SERVICE_NAME}")`, { timeout: 15000 });

      const voteButtons = page.locator('aside button.flex-1.rounded-xl');
      await voteButtons.nth(0).click();
      await page.waitForTimeout(400);

      const voteRow = db.queryScalar(`SELECT user_ref FROM service_votes WHERE service_id='${serviceId}'`);
      assert.strictEqual(voteRow, state.userRef, "expected the vote's user_ref to match the SAME anonymous identity the order used");

      await page.locator('button', { hasText: /Bookmark this service/ }).click();
      await page.waitForSelector('text=Bookmarked', { timeout: 5000 });

      const bookmarkRow = db.queryScalar(`SELECT user_ref FROM bookmarks WHERE service_id='${serviceId}'`);
      assert.strictEqual(bookmarkRow, state.userRef, "expected the bookmark's user_ref to match the SAME anonymous identity the order used");
    });

    await report.step(9, 'Reload as the same browser: the anonymous identity is a persisted cookie, not reissued on every visit', async () => {
      await page.reload({ waitUntil: 'load' });
      await page.waitForSelector(`text=${SERVICE_NAME}`, { timeout: 20000 });
      const cookies = await page.context().cookies();
      const anon = cookies.find((c) => c.name === 'aihd_anon_id');
      assert.ok(anon, 'expected the anonymous cookie to still be present after a reload');
      assert.strictEqual(anon.value, state.cookieValueAfterFirstContact, 'expected the SAME cookie value to persist across a reload, not a freshly reissued one');
    });
  } finally {
    await browser.close();
  }
}

module.exports = { run };
