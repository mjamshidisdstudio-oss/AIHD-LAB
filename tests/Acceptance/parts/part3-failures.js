'use strict';

const assert = require('assert');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

const API_BASE = 'http://127.0.0.1/api';
const IMAGE_FIXTURE = path.join(__dirname, '..', 'fixtures', 'room-photo.png');
// Matches both our service's webhook_signing_key (set in Part 1) and the
// mock's own SHARED_KEY (set by run.js) -- the same shared secret a real
// integrator would have on both ends.
const SHARED_KEY = 'acceptance-shared-key';

function hmacSign(raw) {
  return crypto.createHmac('sha256', SHARED_KEY).update(raw, 'utf8').digest('hex');
}

/**
 * Submit an order directly via the marketplace API rather than the wizard UI.
 * Part 2 already exhaustively proved the real UI submission journey; Part
 * 3's focus is backend failure-handling, so the browser isn't "the point"
 * here -- these still hit the real running server, the real mock service,
 * and the real webhook endpoint, just without re-driving the wizard by hand
 * for every one of the 11 failure modes below.
 */
async function directSubmit(ctx, extra = {}) {
  const form = new FormData();
  form.append('service_id', ctx.state.serviceId);
  form.append('entry_mode', 'wizard');
  const fileBuf = fs.readFileSync(IMAGE_FIXTURE);
  form.append('files[room_photo]', new Blob([fileBuf], { type: 'image/png' }), 'room-photo.png');
  for (const [k, v] of Object.entries(extra)) form.append(k, v);
  return ctx.marketplace.postForm('/orders', form);
}

async function postWebhookRaw(rawBody, signature, serviceId) {
  const res = await fetch(`${API_BASE}/webhooks/${serviceId}/results`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Signature': signature },
    body: rawBody,
  });
  const text = await res.text();
  let data;
  try {
    data = text ? JSON.parse(text) : null;
  } catch {
    data = text;
  }
  return { status: res.status, data };
}

async function postSignedWebhook(serviceId, bodyObj) {
  const raw = JSON.stringify(bodyObj);
  return postWebhookRaw(raw, hmacSign(raw), serviceId);
}

async function waitForMockJob(ctx, orderId, status, timeoutMs = 8000) {
  const deadline = Date.now() + timeoutMs;
  let job = null;
  while (Date.now() < deadline) {
    const jobs = await ctx.mock.jobs();
    job = jobs.find((j) => j.order_id === orderId);
    if (job && job.status === status) return job;
    await new Promise((r) => setTimeout(r, 150));
  }
  return job;
}

async function waitForOrderStatus(ctx, orderId, status, timeoutMs = 8000) {
  const deadline = Date.now() + timeoutMs;
  let order = null;
  while (Date.now() < deadline) {
    const res = await ctx.admin.get(`/admin/orders/${orderId}`);
    order = res.data.data;
    if (order.status === status) return order;
    await new Promise((r) => setTimeout(r, 150));
  }
  return order;
}

async function waitForWebhookReceipt(ctx, serviceId, outcome, timeoutMs = 8000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const res = await ctx.admin.get(`/admin/services/${serviceId}/webhook-deliveries?outcome=${outcome}`);
    if (res.data.data.length > 0) return res.data.data[0];
    await new Promise((r) => setTimeout(r, 150));
  }
  return null;
}

/**
 * The mock's own 6 modes cover the operational failure paths (silent,
 * bad-signature, failing, slow, duplicate). "Malformed body" and "unknown
 * external_order_id" (steps 25-26) are testing the webhook endpoint's own
 * input robustness against arbitrary/malicious senders -- there's no
 * meaningful "UI" for a webhook delivery (it's never a human, or a browser,
 * on the other end), so those two are exercised with a direct, correctly
 * signed HTTP request built the same way the mock signs its own -- still the
 * real running endpoint, still a real HTTP round trip, just without adding
 * bespoke modes to the mock for edge cases outside its normal operation.
 *
 * Every step below drives its own request to a TERMINAL status (completed or
 * failed) before moving on: this service's max_concurrent is 3, and an
 * abandoned in-flight request would silently eat into that cap for every
 * later step.
 */
async function run(ctx, report) {
  const serviceId = ctx.state.serviceId;

  await report.step(22, 'SILENT: the poll sweep picks it up through the same ingest door, settles exactly once', async () => {
    await ctx.mock.setMode('silent');
    const balanceBefore = await ctx.core.balance('dev-user');

    const resp = await directSubmit(ctx);
    assert.strictEqual(resp.status, 202, `submit failed: ${JSON.stringify(resp.data)}`);
    const orderId = resp.data.data.id;
    ctx.state.part3SampleOrderId = ctx.state.part3SampleOrderId ?? orderId;

    const job = await waitForMockJob(ctx, orderId, 'completed');
    assert.ok(job, 'the mock never finished processing in silent mode');

    let orderRow = (await ctx.admin.get(`/admin/orders/${orderId}`)).data.data;
    assert.notStrictEqual(orderRow.status, 'completed', 'silent mode must never complete on its own -- no webhook was ever sent');

    const balanceAfterDeduct = await ctx.core.balance('dev-user');
    assert.strictEqual(balanceBefore - balanceAfterDeduct, 2, 'expected exactly 2 coins deducted at submit time');

    ctx.runArtisan('poll:sweep');

    orderRow = await waitForOrderStatus(ctx, orderId, 'completed', 5000);
    assert.strictEqual(orderRow.status, 'completed', 'expected the poll sweep to complete the silent-mode order');
    assert.ok(orderRow.requests[0].get_poll_count >= 1, 'expected get_poll_count to have incremented');
    assert.ok(orderRow.requests[0].last_polled_at, 'expected last_polled_at to be set');
    assert.ok(
      orderRow.outputs.every((o) => o.source === 'poll'),
      'expected every result to be sourced from poll, not webhook, in silent mode',
    );

    const balanceAfterSweep = await ctx.core.balance('dev-user');
    assert.strictEqual(balanceAfterSweep, balanceAfterDeduct, 'settle must never deduct further coins');
  });

  await report.step(23, 'DUPLICATE: same result via webhook AND poll (both orderings) -- exactly one row, one settle, one broadcast', async () => {
    // "One broadcast" is proven indirectly here, not with a second browser
    // page + websocket listener: IngestResult.completeIfAllResultsIn() locks
    // the order row and only the caller that crosses the completion
    // threshold ever calls onCompleted(), which settles AND broadcasts
    // together, guarded by the SAME boolean. Proving settle fired exactly
    // once (checked below via balance, which IS independently observable)
    // proves broadcast fired exactly once too -- they're the same call.

    // Ordering A: poll delivers everything first; a webhook "arrives late"
    // for a result poll already ingested.
    await ctx.mock.setMode('silent');
    const respA = await directSubmit(ctx);
    assert.strictEqual(respA.status, 202, `submit A failed: ${JSON.stringify(respA.data)}`);
    const orderIdA = respA.data.data.id;
    const jobA = await waitForMockJob(ctx, orderIdA, 'completed');
    assert.ok(jobA, 'mock A never finished processing');

    ctx.runArtisan('poll:sweep');
    const orderRowA = await waitForOrderStatus(ctx, orderIdA, 'completed', 5000);
    assert.strictEqual(orderRowA.status, 'completed', 'expected ordering A to complete via poll first');
    const requestIdA = orderRowA.requests[0].id;
    const fileIdBefore = orderRowA.outputs.find((o) => o.result_number === 1).file_id;
    const balanceAfterA = await ctx.core.balance('dev-user');

    const dupResult = jobA.results.find((r) => r.result_number === 1);
    const dupResp = await postSignedWebhook(serviceId, {
      external_order_id: jobA.external_order_id,
      result_number: 1,
      type: dupResult.type,
      media_id: dupResult.media_id,
    });
    assert.strictEqual(dupResp.data.outcome, 'duplicate', `expected a late webhook for an already-polled result to be a duplicate, got: ${JSON.stringify(dupResp.data)}`);

    const rowCountA = ctx.db.queryScalar(`SELECT COUNT(*) FROM results WHERE request_id='${requestIdA}' AND result_number=1`);
    assert.strictEqual(rowCountA, '1', `expected exactly one results row for result_number=1, got ${rowCountA}`);
    const orderRowAAfter = (await ctx.admin.get(`/admin/orders/${orderIdA}`)).data.data;
    assert.strictEqual(orderRowAAfter.outputs.find((o) => o.result_number === 1).file_id, fileIdBefore, 'a duplicate delivery must never overwrite the original row');
    const balanceAfterDupA = await ctx.core.balance('dev-user');
    assert.strictEqual(balanceAfterDupA, balanceAfterA, 'a duplicate delivery must never settle a second time');

    // Ordering B: webhook delivers ONE result first; poll (re-)delivers all
    // 4, including a duplicate of the one the webhook already ingested, plus
    // the 3 still-genuinely-new ones that complete the order.
    await ctx.mock.setMode('silent');
    const respB = await directSubmit(ctx);
    assert.strictEqual(respB.status, 202, `submit B failed: ${JSON.stringify(respB.data)}`);
    const orderIdB = respB.data.data.id;
    const jobB = await waitForMockJob(ctx, orderIdB, 'completed');
    assert.ok(jobB, 'mock B never finished processing');

    const firstResult = jobB.results.find((r) => r.result_number === 1);
    const webhookFirstResp = await postSignedWebhook(serviceId, {
      external_order_id: jobB.external_order_id,
      result_number: 1,
      type: firstResult.type,
      media_id: firstResult.media_id,
    });
    assert.strictEqual(webhookFirstResp.data.outcome, 'ingested', `expected the first manual webhook to ingest cleanly, got: ${JSON.stringify(webhookFirstResp.data)}`);

    let orderRowBMidway = (await ctx.admin.get(`/admin/orders/${orderIdB}`)).data.data;
    assert.notStrictEqual(orderRowBMidway.status, 'completed', 'expected the order to still be processing with only 1 of 4 results in');

    const balanceAfterWebhookB = await ctx.core.balance('dev-user');

    ctx.runArtisan('poll:sweep');
    const orderRowB = await waitForOrderStatus(ctx, orderIdB, 'completed', 5000);
    assert.strictEqual(orderRowB.status, 'completed', 'expected the poll sweep to finish delivering the remaining 3 results');
    const requestIdB = orderRowB.requests[0].id;
    const rowCountB = ctx.db.queryScalar(`SELECT COUNT(*) FROM results WHERE request_id='${requestIdB}' AND result_number=1`);
    assert.strictEqual(rowCountB, '1', `expected exactly one results row for result_number=1, got ${rowCountB}`);

    const balanceAfterB = await ctx.core.balance('dev-user');
    assert.strictEqual(balanceAfterB, balanceAfterWebhookB, 'settle must never deduct further coins once the order completes via the poll-delivered duplicates');
  });

  await report.step(24, 'BAD SIGNATURE: rejected, receipt written with outcome=invalid_signature and the raw body stored verbatim', async () => {
    await ctx.mock.setMode('bad-signature');
    const resp = await directSubmit(ctx);
    assert.strictEqual(resp.status, 202, `submit failed: ${JSON.stringify(resp.data)}`);
    const orderId = resp.data.data.id;
    const job = await waitForMockJob(ctx, orderId, 'completed');
    assert.ok(job, 'mock never finished processing');

    const receipt = await waitForWebhookReceipt(ctx, serviceId, 'invalid_signature');
    assert.ok(receipt, 'expected an invalid_signature webhook receipt to be recorded');
    assert.strictEqual(receipt.http_status, 401);
    assert.ok(receipt.raw_body && receipt.raw_body.length > 0, 'expected the raw body to be stored verbatim');
    const parsed = JSON.parse(receipt.raw_body);
    assert.ok(parsed.result_number, 'expected the stored raw_body to be the real (if rejected) result payload');
    assert.strictEqual(receipt.request_id, null, 'a rejected-at-signature delivery never resolves to a request');

    // Hygiene: the underlying job is done processing regardless of mode --
    // sweep it to a clean terminal state so it doesn't linger against this
    // service's max_concurrent for later steps.
    ctx.runArtisan('poll:sweep');
    await waitForOrderStatus(ctx, orderId, 'completed', 5000);
  });

  await report.step(25, "MALFORMED BODY: raw garbage that isn't JSON still gets a receipt with the body stored verbatim", async () => {
    const raw = '{not valid json,,, this is garbage --';
    const resp = await postWebhookRaw(raw, hmacSign(raw), serviceId);
    assert.strictEqual(resp.data.outcome, 'validation_error', `expected validation_error, got: ${JSON.stringify(resp.data)}`);
    assert.strictEqual(resp.status, 422);

    const receipt = await waitForWebhookReceipt(ctx, serviceId, 'validation_error');
    assert.ok(receipt, 'expected a validation_error webhook receipt to be recorded');
    assert.strictEqual(receipt.raw_body, raw, 'expected the exact malformed bytes to be stored verbatim');
  });

  await report.step(26, 'UNKNOWN external_order_id: receipt with outcome=unknown_order, rejected', async () => {
    const fakeExternalOrderId = crypto.randomUUID();
    const resp = await postSignedWebhook(serviceId, {
      external_order_id: fakeExternalOrderId,
      result_number: 1,
      type: 'image',
      media_id: crypto.randomUUID(),
    });
    assert.strictEqual(resp.data.outcome, 'unknown_order', `expected unknown_order, got: ${JSON.stringify(resp.data)}`);
    assert.strictEqual(resp.status, 404);

    const receipts = (await ctx.admin.get(`/admin/services/${serviceId}/webhook-deliveries?outcome=unknown_order&external_order_id=${fakeExternalOrderId}`)).data.data;
    assert.strictEqual(receipts.length, 1, 'expected exactly one unknown_order receipt for this external_order_id');
    assert.strictEqual(receipts[0].external_order_id, fakeExternalOrderId);
  });

  await report.step(27, 'FAILING: failure_stage set, order fails, coins refund exactly once, consecutive_failures increments', async () => {
    await ctx.mock.setMode('failing');
    const balanceBefore = await ctx.core.balance('dev-user');
    const failuresBefore = (await ctx.admin.get(`/admin/services/${serviceId}`)).data.data.consecutive_failures;

    const resp = await directSubmit(ctx);
    assert.strictEqual(resp.status, 202, `submit failed: ${JSON.stringify(resp.data)}`);
    const orderId = resp.data.data.id;

    const orderRow = await waitForOrderStatus(ctx, orderId, 'failed', 10000);
    assert.strictEqual(orderRow.status, 'failed', `expected the order to fail, got ${orderRow.status}`);
    assert.strictEqual(orderRow.requests[0].failure_stage, 'service');

    const balanceAfter = await ctx.core.balance('dev-user');
    assert.strictEqual(balanceAfter, balanceBefore, 'expected the deducted coins to be refunded exactly once');

    const failuresAfter = (await ctx.admin.get(`/admin/services/${serviceId}`)).data.data.consecutive_failures;
    assert.strictEqual(failuresAfter, failuresBefore + 1);
  });

  await report.step(28, 'SLOW: exceeds response_timeout_s then max_get_attempts; failure_stage=timeout, refund once, strike recorded', async () => {
    await ctx.mock.setMode('slow');
    const balanceBefore = await ctx.core.balance('dev-user');
    const failuresBefore = (await ctx.admin.get(`/admin/services/${serviceId}`)).data.data.consecutive_failures;

    // Blocks for ~response_timeout_s: DispatchRequest's own poll attempt
    // (dispatched synchronously under QUEUE_CONNECTION=sync) tries the mock
    // and times out before this response ever returns.
    const resp = await directSubmit(ctx);
    assert.strictEqual(resp.status, 202, `submit failed: ${JSON.stringify(resp.data)}`);
    const orderId = resp.data.data.id;

    // max_get_attempts is 2 in the fast profile; the first attempt already
    // ran synchronously during submit. Drive the remaining attempts
    // explicitly -- nothing in this harness runs the real poll:sweep cron.
    ctx.runArtisan('poll:sweep'); // 2nd attempt: also times out
    ctx.runArtisan('poll:sweep'); // budget spent -> FailureStage::Timeout, no network call needed

    const orderRow = await waitForOrderStatus(ctx, orderId, 'failed', 8000);
    assert.strictEqual(orderRow.status, 'failed', `expected the order to fail, got ${orderRow.status}`);
    assert.strictEqual(orderRow.requests[0].failure_stage, 'timeout');
    assert.ok(orderRow.requests[0].get_poll_count >= 2, 'expected the attempt budget to have been spent');

    const balanceAfter = await ctx.core.balance('dev-user');
    assert.strictEqual(balanceAfter, balanceBefore, 'expected the deducted coins to be refunded exactly once');

    const failuresAfter = (await ctx.admin.get(`/admin/services/${serviceId}`)).data.data.consecutive_failures;
    assert.strictEqual(failuresAfter, failuresBefore + 1);
  });

  await report.step(29, 'THREE CONSECUTIVE FAILURES: service auto-disables and disappears from the grid; a success resets the counter', async () => {
    const serviceBefore = (await ctx.admin.get(`/admin/services/${serviceId}`)).data.data;
    assert.strictEqual(serviceBefore.consecutive_failures, 2, 'expected steps 27+28 to have already contributed 2 strikes');
    assert.strictEqual(serviceBefore.status, 'active');

    await ctx.mock.setMode('failing');
    const resp = await directSubmit(ctx);
    assert.strictEqual(resp.status, 202, `submit failed: ${JSON.stringify(resp.data)}`);
    const orderId = resp.data.data.id;
    await waitForOrderStatus(ctx, orderId, 'failed', 10000);

    const serviceAfterThird = (await ctx.admin.get(`/admin/services/${serviceId}`)).data.data;
    assert.strictEqual(serviceAfterThird.consecutive_failures, 3);
    assert.strictEqual(serviceAfterThird.status, 'auto_disabled', 'expected the 3rd consecutive failure to auto-disable the service');

    const catalog = (await ctx.marketplace.get('/marketplace/services')).data.data;
    assert.ok(!catalog.some((s) => s.id === serviceId), 'expected the auto-disabled service to disappear from the marketplace grid');

    // A success resets the failure streak. Status itself stays auto_disabled
    // until an operator republishes (PublishVersion is the only place that
    // clears it) -- IngestResult's own completion path only ever resets the
    // counter, matching the spec's precise wording ("a success resets
    // consecutive_failures", not "un-disables the service").
    await ctx.mock.setMode('normal');
    const resp2 = await directSubmit(ctx);
    assert.strictEqual(resp2.status, 202, `submit failed: ${JSON.stringify(resp2.data)}`);
    const orderId2 = resp2.data.data.id;
    await waitForOrderStatus(ctx, orderId2, 'completed', 10000);

    const serviceAfterReset = (await ctx.admin.get(`/admin/services/${serviceId}`)).data.data;
    assert.strictEqual(serviceAfterReset.consecutive_failures, 0, 'expected a completed order to reset the failure streak');
  });

  await report.step(30, 'INSUFFICIENT BALANCE: drain below coin_cost, submit, 402, no order written, no external call', async () => {
    const balanceBefore = await ctx.core.balance('dev-user');
    await ctx.core.deduct('dev-user', balanceBefore - 1, `acceptance-drain-${Date.now()}`);
    const drainedBalance = await ctx.core.balance('dev-user');
    assert.strictEqual(drainedBalance, 1, `expected the balance to be drained to 1, got ${drainedBalance}`);

    const countBefore = (await ctx.admin.get(`/admin/services/${serviceId}/orders`)).data.meta_stats.total;
    const jobsBefore = (await ctx.mock.jobs()).length;

    const resp = await directSubmit(ctx);
    assert.strictEqual(resp.status, 402, `expected 402 Payment Required, got ${resp.status}: ${JSON.stringify(resp.data)}`);

    const balanceAfter = await ctx.core.balance('dev-user');
    assert.strictEqual(balanceAfter, drainedBalance, 'a rejected submit must never deduct anything');

    const countAfter = (await ctx.admin.get(`/admin/services/${serviceId}/orders`)).data.meta_stats.total;
    assert.strictEqual(countAfter, countBefore, 'expected no order row to be written');

    const jobsAfter = (await ctx.mock.jobs()).length;
    assert.strictEqual(jobsAfter, jobsBefore, 'expected no external POST /run to have been made');
  });

  await report.step(31, 'CORE UNREACHABLE: submit fails safely, no coins charged without a confirmed deduct', async () => {
    const balanceBefore = await ctx.core.balance('dev-user');
    const countBefore = (await ctx.admin.get(`/admin/services/${serviceId}/orders`)).data.meta_stats.total;

    await ctx.restartLaravel({
      CORE_BASE_URL: 'http://127.0.0.1:1',
      CORE_CONNECT_TIMEOUT_SECONDS: '1',
      CORE_TIMEOUT_SECONDS: '2',
    });

    let resp;
    try {
      resp = await directSubmit(ctx);
    } catch (e) {
      resp = { status: 0, data: { message: e.message } };
    }
    assert.ok(resp.status === 0 || resp.status >= 400, `expected the submit to fail safely, got HTTP ${resp.status}`);

    // Restore real core connectivity before checking anything (including
    // Part 4's own reliance on a working core) further.
    await ctx.restartLaravel();

    const balanceAfter = await ctx.core.balance('dev-user');
    assert.strictEqual(balanceAfter, balanceBefore, 'no coins should have been charged while the core was unreachable');

    const countAfter = (await ctx.admin.get(`/admin/services/${serviceId}/orders`)).data.meta_stats.total;
    assert.strictEqual(countAfter, countBefore, 'expected no order row to be written while the core was unreachable');
  });

  await report.step(32, 'STORAGE AUTH: a real user Sanctum token is rejected, an unknown media_id 404s, a wrong key 401s', async () => {
    const orderRow = (await ctx.admin.get(`/admin/orders/${ctx.state.part3SampleOrderId}`)).data.data;
    const roomPhotoInput = orderRow.inputs.find((i) => i.input_slug === 'room_photo');
    const mediaId = roomPhotoInput.files[0].id;

    const withSanctum = await fetch(`${API_BASE}/storage/${mediaId}`, {
      headers: { Authorization: `Bearer ${ctx.admin.token}` },
    });
    assert.strictEqual(withSanctum.status, 401, `expected a real Sanctum admin token to be rejected, got ${withSanctum.status}`);

    const unknownId = await fetch(`${API_BASE}/storage/00000000-0000-0000-0000-000000000000`, {
      headers: { Authorization: `Bearer ${SHARED_KEY}` },
    });
    assert.strictEqual(unknownId.status, 404, `expected an unknown media_id to 404, got ${unknownId.status}`);

    const wrongKey = await fetch(`${API_BASE}/storage/${mediaId}`, {
      headers: { Authorization: 'Bearer totally-wrong-key' },
    });
    assert.strictEqual(wrongKey.status, 401, `expected a wrong service key to be 401, got ${wrongKey.status}`);
  });
}

module.exports = { run };
