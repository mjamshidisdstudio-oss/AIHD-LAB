'use strict';

const assert = require('assert');
const fs = require('fs');
const os = require('os');
const path = require('path');

const SERVICE_NAME = 'Acceptance Twilight Views';
// Deliberately contains neither "free" nor "before"/"after" as a substring --
// Playwright's unquoted text= matching (and the hasText option) is a
// case-insensitive SUBSTRING test, so a name/tagline containing the same
// word as a badge we're asserting on would double-count against it.
const FREE_NAME = 'Acceptance Bonus Filter';
const EXTERNAL_NAME = 'Acceptance Partner Tool';
const EXTERNAL_URL = 'https://partner.example.com/tool';
const IMAGE_FIXTURE = path.join(__dirname, '..', 'fixtures', 'room-photo.png');

// marketplace/stores/order.ts's own constants -- kept in lockstep here so
// step 13/14's timing assertions reason about the SAME numbers the app uses,
// not guesses.
const POLL_FALLBACK_MS = 4000;
const WAITING_ROTATE_MS = 2600;

/**
 * Four interpretive decisions worth documenting up front (same spirit as
 * part1-admin.js's header):
 *
 *  - The "Free" and "external-kind" fixture services (needed for steps 9 and
 *    21) are created via the admin API, not a second full admin-UI journey.
 *    Part 1 already proved the real admin UI can create/publish a service
 *    end to end; these two are pure fixture data for Part 2's actual focus
 *    (the marketplace/user surface), exactly like Part 1 reusing the
 *    already-seeded season-gen service as a fixture rather than rebuilding it.
 *  - Step 15 ("each visibly different") is proven at the reference layer:
 *    each output's file_id is asserted distinct and cross-checked against the
 *    mock service's OWN record of the distinct media_ids it uploaded (whose
 *    bytes are genuinely tinted per result_number -- verified by hand during
 *    Part 0's build). ResultsPanel.vue does not actually render the real
 *    image bytes for non-text outputs -- image/video results show a generic
 *    gradient placeholder icon, never an <img> of the real content. That is a
 *    pre-existing Phase 6 UI decision; changing it would be a frontend
 *    product change out of this suite's scope, so it is flagged for the
 *    final report rather than silently patched or silently ignored.
 *  - Step 13's waiting-text rotation is asserted conditionally on real
 *    elapsed time: the fast profile's mock processing delay (500ms) can
 *    legitimately finish before the first WAITING_ROTATE_MS (2600ms) tick
 *    ever fires, so a fast completion is not a rotation failure. Whenever
 *    real elapsed time crosses that boundary (always true under the
 *    realistic profile), rotation IS hard-asserted.
 *  - A couple of assertions (service_votes row count, external_click
 *    interaction count) have no API surface at all -- not even the admin
 *    one -- so lib/db.js's direct read-only query is used for exactly those,
 *    and nothing else.
 */
async function run(ctx, report) {
  const page = await ctx.browser.newPage({ viewport: { width: 1440, height: 960 } });
  ctx.marketplacePage = page;

  // Attached once, for this page's whole lifetime, so later steps can see
  // every websocket frame and every GET /orders/{id} poll-fallback request
  // that ever happens -- including ones that occurred before we started
  // looking for a specific order.
  const wsFrames = [];
  const orderGetRequests = [];
  page.on('websocket', (ws) => {
    ws.on('framereceived', (evt) => {
      const payload = typeof evt.payload === 'string' ? evt.payload : evt.payload.toString('utf8');
      wsFrames.push({ t: Date.now(), payload });
    });
  });
  page.on('request', (req) => {
    const pathname = new URL(req.url()).pathname;
    if (req.method() === 'GET' && /\/orders\/[0-9a-f-]{36}$/.test(pathname)) {
      orderGetRequests.push(Date.now());
    }
  });
  page.on('console', (msg) => {
    if (msg.type() === 'error') console.log('  [marketplace console error]', msg.text());
  });
  page.on('pageerror', (err) => {
    console.log('  [marketplace page error]', err.message);
  });

  const order1 = {};
  const order2 = {};
  let externalServiceId = null;

  // --- Fixtures (not a numbered spec step -- see header) --------------------
  const freeSvc = await ctx.admin.post('/admin/services', {
    slug: 'acceptance-bonus-filter',
    name: FREE_NAME,
    kind: 'internal',
    category: 'interior',
    tagline: 'This one never charges credits',
    image_url: 'https://picsum.photos/seed/acceptance-bonus/800/600',
  });
  assert.strictEqual(freeSvc.status, 201, `create free fixture service failed: ${JSON.stringify(freeSvc.data)}`);
  const freeVersions = await ctx.admin.get(`/admin/services/${freeSvc.data.data.id}/versions`);
  const freeDraft = freeVersions.data.data.find((v) => v.status === 'draft');
  await ctx.admin.post(`/admin/versions/${freeDraft.id}/publish`);

  const externalSvc = await ctx.admin.post('/admin/services', {
    slug: 'acceptance-partner-tool',
    name: EXTERNAL_NAME,
    kind: 'external',
    category: 'interior',
    external_url: EXTERNAL_URL,
    tagline: 'Opens on our partner site',
    image_url: 'https://picsum.photos/seed/acceptance-partner/800/600',
  });
  assert.strictEqual(externalSvc.status, 201, `create external fixture service failed: ${JSON.stringify(externalSvc.data)}`);
  externalServiceId = externalSvc.data.data.id;

  async function fillWizardAndSubmit(accentLabel) {
    await page.waitForSelector('text=Step 1 of', { timeout: 10000 });
    await page.setInputFiles('input[type="file"]', IMAGE_FIXTURE);
    await page.waitForTimeout(200);
    await page.locator('button', { hasText: /^\s*Next\s*$/ }).click();
    await page.waitForSelector('text=Step 2 of', { timeout: 5000 });
    await page.locator('button', { hasText: /^\s*Matte\s*$/ }).click();
    await page.waitForSelector('text=Step 2 of 4', { timeout: 5000 });
    await page.locator('button', { hasText: /^\s*Next\s*$/ }).click();
    await page.waitForSelector('text=Step 3 of 4', { timeout: 5000 });
    await page.locator('button', { hasText: new RegExp(`^\\s*${accentLabel}\\s*$`) }).click();
    await page.waitForTimeout(150);
    await page.locator('button', { hasText: /^\s*Next\s*$/ }).click();
    await page.waitForSelector('text=Step 4 of 4', { timeout: 5000 });

    const [resp] = await Promise.all([
      page.waitForResponse(
        (r) => r.request().method() === 'POST' && /\/orders$/.test(new URL(r.url()).pathname),
        { timeout: 10000 },
      ),
      page.locator('button', { hasText: /Generate Result/ }).click(),
    ]);
    return resp;
  }

  await report.step(9, 'Marketplace grid: real image/tagline/category/cost badge; Free badge for coin_cost=0', async () => {
    await page.goto(`${ctx.marketplaceUrl}/`, { waitUntil: 'load' });
    await page.waitForSelector(`text=${SERVICE_NAME}`, { timeout: 20000 });

    const card = page.locator('span', { hasText: SERVICE_NAME }).locator('xpath=ancestor::div[contains(@class,"cursor-pointer")][1]');
    await card.locator('text=Interior').waitFor({ timeout: 5000 });
    await card.locator('text=2 credits').waitFor({ timeout: 5000 });
    assert.strictEqual(
      await card.locator('text=Turn dusk photos into golden-hour listings').count(),
      1,
      'expected the real tagline to render on the grid card',
    );
    const imageStyle = await card.locator('div[style*="background-image"]').first().getAttribute('style');
    assert.ok(
      imageStyle && imageStyle.includes('acceptance-cover'),
      `expected the card image to use the real cover image url, got: ${imageStyle}`,
    );

    await page.waitForSelector(`text=${FREE_NAME}`, { timeout: 10000 });
    const freeCard = page.locator('span', { hasText: FREE_NAME }).locator('xpath=ancestor::div[contains(@class,"cursor-pointer")][1]');
    // Exact (quoted) match -- an unquoted text=Free would also match the
    // service's own name/tagline if either happened to contain "free".
    assert.strictEqual(await freeCard.locator('text="Free"').count(), 1, 'expected the Free badge on a coin_cost=0 service');
  });

  await report.step(10, 'Service detail: gallery and before/after render for the published version', async () => {
    await page.locator('span', { hasText: SERVICE_NAME }).click();
    await page.waitForSelector(`h1:has-text("${SERVICE_NAME}")`, { timeout: 15000 });

    const galleryCount = await page.locator('#d-gallery > div').count();
    assert.strictEqual(galleryCount, 2, `expected 2 gallery images, got ${galleryCount}`);

    await page.waitForSelector('#d-beforeafter', { timeout: 5000 });
    // Exact (quoted) match -- the section's own "Before & after" <h2> also
    // contains "Before" as a substring, which an unquoted text= would count.
    const beforeCount = await page.locator('#d-beforeafter >> text="Before"').count();
    const afterCount = await page.locator('#d-beforeafter >> text="After"').count();
    assert.strictEqual(beforeCount, 1, `expected exactly one "Before" badge, got ${beforeCount}`);
    assert.strictEqual(afterCount, 1, `expected exactly one "After" badge, got ${afterCount}`);
  });

  await report.step(11, 'Wizard mode: complete the form with a REAL uploaded image; the dependent select is hidden until its parent is chosen, then shows exactly the right options', async () => {
    await page.locator('button', { hasText: /Use this service/ }).click();
    await page.waitForSelector('text=Step 1 of', { timeout: 15000 });

    // Required-image gate: blocked with no toast-satisfying answer yet.
    await page.locator('button', { hasText: /^\s*Next\s*$/ }).click();
    await page.waitForSelector('text=This field is required', { timeout: 5000 });
    await page.setInputFiles('input[type="file"]', IMAGE_FIXTURE);
    await page.waitForTimeout(200);
    await page.locator('button', { hasText: /^\s*Next\s*$/ }).click();

    // Finish (3 options); Accent not yet a step at all (total=3).
    await page.waitForSelector('text=Step 2 of 3', { timeout: 5000 });
    const finishOptionCount = await page.locator('button', { hasText: /^\s*(Matte|Glossy|Satin)\s*$/ }).count();
    assert.strictEqual(finishOptionCount, 3, `expected 3 Finish options, got ${finishOptionCount}`);
    await page.locator('button', { hasText: /^\s*Matte\s*$/ }).click();

    // Gating: choosing Matte reveals Accent -- total jumps to 4 immediately.
    await page.waitForSelector('text=Step 2 of 4', { timeout: 5000 });
    await page.locator('button', { hasText: /^\s*Next\s*$/ }).click();
    await page.waitForSelector('text=Step 3 of 4', { timeout: 5000 });
    const accentOptionCount = await page.locator('button', { hasText: /^\s*(Warm|Cool)\s*$/ }).count();
    if (accentOptionCount !== 2) {
      console.log('  [debug] Accent step visible buttons:', JSON.stringify(await page.locator('button').allTextContents()));
    }
    assert.strictEqual(accentOptionCount, 2, `expected exactly the 2 Accent options once gated open, got ${accentOptionCount}`);
    await page.locator('button', { hasText: /^\s*Warm\s*$/ }).click();
    await page.locator('button', { hasText: /^\s*Next\s*$/ }).click();

    // HD (toggle) -- optional, exercised but never blocking.
    await page.waitForSelector('text=Step 4 of 4', { timeout: 5000 });
    await page.locator('button[title="Toggle"]').click();

    order1.balanceBeforeSubmit = await ctx.core.balance('dev-user');
    const [resp] = await Promise.all([
      page.waitForResponse(
        (r) => r.request().method() === 'POST' && /\/orders$/.test(new URL(r.url()).pathname),
        { timeout: 10000 },
      ),
      page.locator('button', { hasText: /Generate Result/ }).click(),
    ]);
    order1.submittedAt = Date.now();
    order1.status = resp.status();
    const body = await resp.json();
    order1.id = body.data.id;
  });

  await report.step(12, 'Submit: coins deducted exactly, order fields correct, the external service actually received the POST and fetched the input', async () => {
    assert.strictEqual(order1.status, 202, `expected 202 Accepted from POST /orders, got ${order1.status}`);

    const order = await ctx.admin.get(`/admin/orders/${order1.id}`);
    assert.strictEqual(order.data.data.entry_mode, 'wizard');
    assert.strictEqual(order.data.data.source, 'site');
    assert.strictEqual(order.data.data.service_version_id, ctx.state.versionId);
    assert.ok(order.data.data.coin_txn_ref, 'expected a coin_txn_ref stored on the order');
    assert.strictEqual(order.data.data.coins_charged, 2);

    const balanceAfterSubmit = await ctx.core.balance('dev-user');
    assert.strictEqual(order1.balanceBeforeSubmit - balanceAfterSubmit, 2, 'expected exactly 2 coins deducted');
    order1.balanceAfterSubmit = balanceAfterSubmit;

    const roomPhotoInput = order.data.data.inputs.find((i) => i.input_slug === 'room_photo');
    assert.ok(roomPhotoInput && roomPhotoInput.files && roomPhotoInput.files[0], 'expected the uploaded room_photo file to be recorded on the order');
    const mediaId = roomPhotoInput.files[0].id;

    let job = null;
    for (let i = 0; i < 30 && !job; i++) {
      const jobs = await ctx.mock.jobs();
      job = jobs.find((j) => j.order_id === order1.id);
      if (!job) await new Promise((r) => setTimeout(r, 150));
    }
    assert.ok(job, "the external mock service never received this order's POST /run");
    order1.externalOrderId = job.external_order_id;

    for (let i = 0; i < 30 && !job.downloaded_media_ids.includes(mediaId); i++) {
      await new Promise((r) => setTimeout(r, 150));
      const jobs = await ctx.mock.jobs();
      job = jobs.find((j) => j.order_id === order1.id) || job;
    }
    assert.ok(
      job.downloaded_media_ids.includes(mediaId),
      "the external mock service never fetched the room_photo input via GET /storage/{media_id}",
    );
  });

  await report.step(13, 'Waiting state: displayed texts are genuinely declared waiting_texts, and rotate whenever real elapsed time allows it', async () => {
    const versionData = await ctx.admin.get(`/admin/versions/${ctx.state.versionId}`);
    const waitingTexts = versionData.data.data.waiting_texts.slice().sort((a, b) => a.sort_order - b.sort_order).map((w) => w.text);

    const seenTexts = [];
    let completed = false;
    const deadline = Date.now() + 30000;
    while (Date.now() < deadline && !completed) {
      let current = null;
      for (const t of waitingTexts) {
        if (await page.locator(`text=${t}`).count()) {
          current = t;
          break;
        }
      }
      if (current && seenTexts[seenTexts.length - 1] !== current) seenTexts.push(current);
      completed = (await page.locator('text=Your result is ready!').count()) > 0;
      if (!completed) await page.waitForTimeout(150);
    }
    order1.completedAt = Date.now();
    assert.ok(completed, 'order never reached the results panel within 30s');
    assert.ok(seenTexts.length > 0, 'never observed a waiting-panel text at all');
    for (const t of seenTexts) {
      assert.ok(waitingTexts.includes(t), `waiting panel showed a text not in the version's declared waiting_texts: "${t}"`);
    }

    const elapsed = order1.completedAt - order1.submittedAt;
    if (elapsed >= WAITING_ROTATE_MS) {
      assert.ok(seenTexts.length >= 2, `expected the waiting text to rotate given ${elapsed}ms elapsed, but only ever saw: ${JSON.stringify(seenTexts)}`);
    }
    order1.waitingTextsSeen = seenTexts;

    const orderRow = await ctx.admin.get(`/admin/orders/${order1.id}`);
    assert.strictEqual(orderRow.data.data.status, 'completed');
    assert.notStrictEqual(orderRow.data.data.requests[0].status, 'queued', 'the request should have progressed well past "queued" by completion');
  });

  await report.step(14, 'Completion arrives via Echo (socket), not by polling', async () => {
    const relevantFrames = wsFrames.filter(
      (f) => f.t >= order1.submittedAt && f.t <= order1.completedAt + 300 && f.payload.includes('order.completed'),
    );
    assert.ok(relevantFrames.length > 0, 'never observed an order.completed websocket frame for this order');

    const elapsed = order1.completedAt - order1.submittedAt;
    assert.ok(
      elapsed < POLL_FALLBACK_MS,
      `completion took ${elapsed}ms -- not clearly faster than the ${POLL_FALLBACK_MS}ms poll fallback, so push cannot be distinguished from poll`,
    );

    const prematurePolls = orderGetRequests.filter((t) => t > order1.submittedAt && t < order1.completedAt);
    assert.strictEqual(
      prematurePolls.length,
      0,
      'a GET /orders/{id} poll-fallback request fired before completion -- push cannot be distinguished from poll',
    );
  });

  await report.step(15, 'All 4 results render, each genuinely different, each linked by media_id', async () => {
    const outputCards = await page.locator('text=/^\\s*Output [0-9]\\s*$/').count();
    assert.strictEqual(outputCards, 4, `expected 4 output cards, got ${outputCards}`);

    const orderRow = await ctx.admin.get(`/admin/orders/${order1.id}`);
    const outputs = orderRow.data.data.outputs;
    assert.strictEqual(outputs.length, 4);
    const fileIds = outputs.map((o) => o.file_id);
    assert.ok(fileIds.every(Boolean), 'every output should have a linked file_id');
    assert.strictEqual(new Set(fileIds).size, 4, 'expected 4 DISTINCT file_ids, not the same file reused');

    const jobs = await ctx.mock.jobs();
    const job = jobs.find((j) => j.external_order_id === order1.externalOrderId);
    assert.ok(job, 'lost track of the mock job for this order');
    const uploadedMediaIds = job.results.map((r) => r.media_id);
    assert.strictEqual(new Set(uploadedMediaIds).size, 4, 'the mock reported fewer than 4 distinct uploaded media_ids');
    for (const id of fileIds) {
      assert.ok(uploadedMediaIds.includes(id), `stored file_id ${id} does not match any media_id the mock actually reported uploading`);
    }
  });

  await report.step(16, 'Download routes through the logging endpoint (never a raw storage URL) and writes an interaction of kind=download', async () => {
    const before = await ctx.admin.get(`/admin/orders/${order1.id}`);
    const target = before.data.data.outputs.find((o) => o.result_number === 1);
    assert.ok(target && target.result_id, 'expected output #1 to have a result to download');
    assert.strictEqual(target.download_count, 0);

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
      savedPath = path.join(os.tmpdir(), `acceptance-result-${Date.now()}`);
      await download.saveAs(savedPath);
    } finally {
      page.off('request', onReq);
    }
    const bytes = fs.readFileSync(savedPath);
    assert.ok(bytes.length > 0, 'downloaded result file was empty');
    fs.unlinkSync(savedPath);
    assert.strictEqual(storageRequests.length, 0, `the browser made a direct request to a raw storage URL: ${storageRequests[0]}`);

    const after = await ctx.admin.get(`/admin/orders/${order1.id}`);
    const targetAfter = after.data.data.outputs.find((o) => o.result_number === 1);
    assert.strictEqual(targetAfter.download_count, 1, 'expected exactly one download interaction to be logged');
  });

  await report.step(17, "Coins settled exactly once: balance unchanged from step 12's post-deduct value", async () => {
    const balanceNow = await ctx.core.balance('dev-user');
    assert.strictEqual(balanceNow, order1.balanceAfterSubmit, 'balance changed after settle -- coins were charged more than once');
  });

  await report.step(18, 'Regenerate creates a SIBLING order (root_order_id/regenerated_from_order_id chain); further regenerates are capped by regenerate_limit', async () => {
    const chain = [order1.id];
    for (let i = 0; i < 3; i++) {
      await page.locator('button', { hasText: /^\s*Run again\s*$/ }).click();
      const resp = await fillWizardAndSubmit('Warm');
      assert.strictEqual(resp.status(), 202, `regenerate #${i + 1} was rejected unexpectedly: ${JSON.stringify(await resp.json())}`);
      const body = await resp.json();
      chain.push(body.data.id);
      await page.waitForSelector('text=Your result is ready!', { timeout: 20000 });
    }

    const rows = await Promise.all(chain.map((id) => ctx.admin.get(`/admin/orders/${id}`)));
    assert.strictEqual(rows[0].data.data.root_order_id, null, 'the original order must not have a root_order_id of its own');
    for (let i = 1; i < rows.length; i++) {
      assert.strictEqual(rows[i].data.data.root_order_id, order1.id, `regenerate #${i} should chain to the original order as its root`);
      assert.strictEqual(rows[i].data.data.regenerated_from_order_id, chain[i - 1], `regenerate #${i} should point at the immediately preceding order`);
    }
    assert.strictEqual(new Set(chain).size, 4, 'expected 4 distinct sibling orders (original + 3 regenerates)');

    const balanceBeforeCap = await ctx.core.balance('dev-user');
    const ordersBeforeCap = await ctx.admin.get(`/admin/services/${ctx.state.serviceId}/orders`);
    const countBeforeCap = ordersBeforeCap.data.meta_stats.total;

    await page.locator('button', { hasText: /^\s*Run again\s*$/ }).click();
    const cappedResp = await fillWizardAndSubmit('Warm');
    assert.strictEqual(cappedResp.status(), 422, `expected the 4th regenerate to be capped by regenerate_limit, got HTTP ${cappedResp.status()}`);

    const balanceAfterCap = await ctx.core.balance('dev-user');
    assert.strictEqual(balanceAfterCap, balanceBeforeCap, 'a capped regenerate must never deduct coins');
    const ordersAfterCap = await ctx.admin.get(`/admin/services/${ctx.state.serviceId}/orders`);
    assert.strictEqual(ordersAfterCap.data.meta_stats.total, countBeforeCap, 'a capped regenerate must never create an order row');

    // Leave the wizard cleanly: the capped attempt's 422 means the UI never
    // transitions away from the form phase on its own.
    await page.locator('button[title="Close"]').click();
    order1.chain = chain;
  });

  await report.step(19, 'Vote up then flip to down (one row, flipped, not two); bookmark + Saved filter; post a comment', async () => {
    await page.waitForSelector(`h1:has-text("${SERVICE_NAME}")`, { timeout: 15000 });

    await page.locator('button[title="Upvote"]').click();
    await page.waitForTimeout(400);
    let rowCount = ctx.db.queryScalar(`SELECT COUNT(*) FROM service_votes WHERE service_id='${ctx.state.serviceId}' AND user_ref='dev-user'`);
    assert.strictEqual(rowCount, '1', `expected exactly one service_votes row after upvoting, got ${rowCount}`);
    let value = ctx.db.queryScalar(`SELECT value FROM service_votes WHERE service_id='${ctx.state.serviceId}' AND user_ref='dev-user'`);
    assert.strictEqual(value, '1');

    await page.locator('button[title="Downvote"]').click();
    await page.waitForTimeout(400);
    rowCount = ctx.db.queryScalar(`SELECT COUNT(*) FROM service_votes WHERE service_id='${ctx.state.serviceId}' AND user_ref='dev-user'`);
    assert.strictEqual(rowCount, '1', 'flipping the vote must update the SAME row, not add a second one');
    value = ctx.db.queryScalar(`SELECT value FROM service_votes WHERE service_id='${ctx.state.serviceId}' AND user_ref='dev-user'`);
    assert.strictEqual(value, '-1', "expected the single row's value to have flipped to -1");

    await page.locator('button', { hasText: /Bookmark this service/ }).click();
    await page.waitForSelector('text=Bookmarked', { timeout: 5000 });

    await page.locator('button', { hasText: /Back to marketplace/ }).click();
    await page.waitForSelector(`text=${SERVICE_NAME}`, { timeout: 15000 });
    await page.locator('button', { hasText: /^\s*Saved/ }).click();
    await page.waitForTimeout(300);
    assert.strictEqual(
      await page.locator('span', { hasText: SERVICE_NAME }).count(),
      1,
      'expected the bookmarked service to appear under the Saved filter',
    );
    assert.strictEqual(
      await page.locator('span', { hasText: FREE_NAME }).count(),
      0,
      'expected a non-bookmarked service to be hidden by the Saved filter',
    );
    await page.locator('button', { hasText: /^\s*Saved/ }).click();

    await page.locator('span', { hasText: SERVICE_NAME }).click();
    await page.waitForSelector(`h1:has-text("${SERVICE_NAME}")`, { timeout: 15000 });
    const commentBody = `Acceptance test comment ${Date.now()}`;
    await page.locator('textarea[placeholder="Share your experience with this service…"]').fill(commentBody);
    await page.locator('button', { hasText: /^\s*Post comment\s*$/ }).click();
    await page.waitForSelector(`text=${commentBody}`, { timeout: 8000 });
  });

  await report.step(20, 'Chat mode: a second complete order via the conversational flow, entry_mode=chat, same gating', async () => {
    await page.locator('button', { hasText: /Use this service/ }).click();
    await page.waitForSelector('text=Wizard', { timeout: 10000 });
    await page.locator('button', { hasText: /^\s*Chat\s*$/ }).click();
    await page.waitForSelector('text=Room photo', { timeout: 10000 });

    await page.setInputFiles('input[type="file"]', IMAGE_FIXTURE);
    await page.waitForTimeout(300);

    await page.waitForSelector('text=Finish', { timeout: 5000 });
    await page.locator('button', { hasText: /^\s*Matte\s*$/ }).click();
    await page.waitForTimeout(300);

    await page.waitForSelector('text=Accent', { timeout: 5000 });
    await page.locator('button', { hasText: /^\s*Warm\s*$/ }).click();
    await page.waitForTimeout(300);

    await page.waitForSelector('text=HD output', { timeout: 5000 });
    await page.locator('button', { hasText: /^\s*Yes\s*$/ }).click();
    await page.waitForTimeout(300);

    const [resp] = await Promise.all([
      page.waitForResponse(
        (r) => r.request().method() === 'POST' && /\/orders$/.test(new URL(r.url()).pathname),
        { timeout: 10000 },
      ),
      page.locator('button', { hasText: /Generate Result/ }).click(),
    ]);
    assert.strictEqual(resp.status(), 202, `expected 202 Accepted, got ${resp.status()}`);
    const body = await resp.json();
    order2.id = body.data.id;

    await page.waitForSelector('text=Your result is ready!', { timeout: 20000 });
    const orderRow = await ctx.admin.get(`/admin/orders/${order2.id}`);
    assert.strictEqual(orderRow.data.data.entry_mode, 'chat');
    assert.strictEqual(orderRow.data.data.source, 'site');
    assert.strictEqual(orderRow.data.data.regenerated_from_order_id, null, 'expected a fresh order, not chained to the earlier regenerate lineage');
  });

  await report.step(21, 'External-kind service renders as a link-out card with no run flow; clicking logs an interaction of kind=external_click', async () => {
    const anotherBtn = page.locator('button', { hasText: /Another service/ });
    if (await anotherBtn.count()) {
      await anotherBtn.click();
    } else {
      await page.goto(`${ctx.marketplaceUrl}/`, { waitUntil: 'load' });
    }
    await page.waitForSelector(`text=${EXTERNAL_NAME}`, { timeout: 15000 });

    const before = Number(ctx.db.queryScalar(`SELECT COUNT(*) FROM interactions WHERE kind='external_click' AND service_id='${externalServiceId}'`) ?? 0);

    const context = page.context();
    const [popup] = await Promise.all([
      context.waitForEvent('page', { timeout: 8000 }),
      page.locator('span', { hasText: EXTERNAL_NAME }).click(),
    ]);
    await popup.waitForTimeout(100);
    assert.ok(
      popup.url() === 'about:blank' || popup.url().includes('partner.example.com'),
      `expected the click to open the external URL in a new tab, got: ${popup.url()}`,
    );
    await popup.close();

    let after = before;
    for (let i = 0; i < 20 && after === before; i++) {
      after = Number(ctx.db.queryScalar(`SELECT COUNT(*) FROM interactions WHERE kind='external_click' AND service_id='${externalServiceId}'`) ?? 0);
      if (after === before) await new Promise((r) => setTimeout(r, 150));
    }
    assert.strictEqual(after, before + 1, 'expected exactly one new external_click interaction to be logged');
  });
}

module.exports = { run };
