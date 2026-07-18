'use strict';

const assert = require('assert');

/**
 * Part 4 proves the operator surfaces built in P7/P8 actually let a human
 * find and understand what Parts 1-3 just did -- the admin order log's
 * filters, the webhook delivery log's raw-body inspection, and the
 * analytics command's interest ladder.
 *
 * Every count-based assertion below is cross-checked against an
 * independently-written SQL query (via ctx.db), not a hand-derived literal:
 * re-deriving "how many orders did Parts 2-3 actually create" by re-reading
 * every earlier step is exactly the kind of arithmetic a typo turns into a
 * false pass. Agreement between two independent code paths (the admin API /
 * AnalyticsRepository on one side, raw SQL on the other) is real evidence;
 * a hardcoded guess is not. The three counts the spec states outright
 * (1 download, 3 regenerates, the step-19 vote landing on "down") are still
 * asserted exactly, since those are genuinely known constants of the
 * scenario, not something to derive.
 */
async function run(ctx, report) {
  const serviceId = ctx.state.serviceId;

  await report.step(33, 'Admin order log: find the orders from Parts 2-3; source/entry_mode filters return the right sets; attempts, statuses, failure_stage, poll counts are visible', async () => {
    const expectedAdminPreview = ctx.db.queryColumn(`SELECT id FROM orders WHERE service_id='${serviceId}' AND source='admin_preview'`).sort();
    const expectedSite = ctx.db.queryColumn(`SELECT id FROM orders WHERE service_id='${serviceId}' AND source='site'`).sort();
    const expectedChat = ctx.db.queryColumn(`SELECT id FROM orders WHERE service_id='${serviceId}' AND entry_mode='chat'`).sort();
    const expectedWizard = ctx.db.queryColumn(`SELECT id FROM orders WHERE service_id='${serviceId}' AND entry_mode='wizard'`).sort();
    const expectedFailed = ctx.db.queryColumn(`SELECT id FROM orders WHERE service_id='${serviceId}' AND status='failed'`).sort();

    assert.deepStrictEqual(expectedAdminPreview, [ctx.state.adminPreviewOrderId], 'sanity: expected exactly the step-8 order to have source=admin_preview');
    assert.ok(expectedChat.length === 1, `expected exactly the step-20 chat order, got ${expectedChat.length}`);
    assert.ok(expectedFailed.length >= 2, `expected at least the Part 3 FAILING and SLOW orders to be in a failed state, got ${expectedFailed.length}`);

    const fetchIds = async (query) => {
      const res = await ctx.admin.get(`/admin/services/${serviceId}/orders?${query}`);
      return res.data.data.map((o) => o.id).sort();
    };

    assert.deepStrictEqual(await fetchIds('source=admin_preview'), expectedAdminPreview, 'source=admin_preview filter returned the wrong set');
    assert.deepStrictEqual(await fetchIds('source=site'), expectedSite, 'source=site filter returned the wrong set');
    assert.deepStrictEqual(await fetchIds('entry_mode=chat'), expectedChat, 'entry_mode=chat filter returned the wrong set');
    assert.deepStrictEqual(await fetchIds('entry_mode=wizard'), expectedWizard, 'entry_mode=wizard filter returned the wrong set');
    assert.deepStrictEqual(await fetchIds('status=failed'), expectedFailed, 'status=failed filter returned the wrong set');

    // Drill into every failed order: attempts, failure_stage, and poll
    // counts must be visible on the real per-order log, not just inferable.
    const seenFailureStages = new Set();
    for (const id of expectedFailed) {
      const order = (await ctx.admin.get(`/admin/orders/${id}`)).data.data;
      assert.strictEqual(order.status, 'failed');
      assert.ok(Array.isArray(order.requests) && order.requests.length > 0, `expected order ${id} to expose its request attempts`);
      for (const req of order.requests) {
        assert.ok(Number.isInteger(req.attempt_no), `expected attempt_no to be visible on order ${id}`);
        assert.ok(req.failure_stage, `expected failure_stage to be visible on the failed attempt for order ${id}`);
        assert.ok(Number.isInteger(req.get_poll_count), `expected get_poll_count to be visible on order ${id}`);
        seenFailureStages.add(req.failure_stage);
      }
    }
    assert.ok(seenFailureStages.has('service'), "expected the FAILING-mode order's failure_stage=service to be visible in the log");
    assert.ok(seenFailureStages.has('timeout'), "expected the SLOW-mode order's failure_stage=timeout to be visible in the log");
  });

  await report.step(34, 'Webhook delivery log: find the bad-signature and malformed-body deliveries from Part 3; the raw body is inspectable', async () => {
    // The mock sends one webhook per declared output (4, matching this
    // version) in bad-signature mode, each with the same wrong signature --
    // so this service genuinely has 4 invalid_signature receipts, not 1.
    // Match the specific one Part 3 recorded rather than assuming a count.
    const invalidSig = (await ctx.admin.get(`/admin/services/${serviceId}/webhook-deliveries?outcome=invalid_signature`)).data.data;
    assert.ok(invalidSig.length >= 1, 'expected at least one invalid_signature delivery (Part 3 step 24)');
    const matchedSig = invalidSig.find((d) => d.id === ctx.state.part3InvalidSignatureDeliveryId);
    assert.ok(matchedSig, 'expected the log to surface the exact delivery Part 3 recorded');
    assert.ok(matchedSig.raw_body && matchedSig.raw_body.length > 0, 'expected the invalid_signature raw body to be inspectable from the log');
    const parsedSig = JSON.parse(matchedSig.raw_body);
    assert.ok(parsedSig.result_number, 'expected the stored raw_body to be the real rejected payload, not a placeholder');

    const malformed = (await ctx.admin.get(`/admin/services/${serviceId}/webhook-deliveries?outcome=validation_error`)).data.data;
    assert.strictEqual(malformed.length, 1, 'expected exactly one validation_error delivery (Part 3 step 25)');
    assert.strictEqual(malformed[0].id, ctx.state.part3MalformedDeliveryId, 'expected the log to surface the exact delivery Part 3 recorded');
    assert.strictEqual(malformed[0].raw_body, ctx.state.part3MalformedBody, 'expected the malformed raw body to be inspectable verbatim from the log');

    // Also reachable via the dedicated per-delivery show endpoint, not just
    // buried in a list -- the "your webhook didn't fire" lookup a dev would
    // use after being handed one delivery id.
    const shown = (await ctx.admin.get(`/admin/webhook-deliveries/${malformed[0].id}`)).data.data;
    assert.strictEqual(shown.raw_body, ctx.state.part3MalformedBody, 'expected the show endpoint to expose the same raw body as the list');
  });

  await report.step(35, 'Analytics: the interest ladder counts match what this test actually did, and the admin_preview order is excluded from all of them', async () => {
    const expected = {
      generate: Number(ctx.db.queryScalar(`SELECT COUNT(*) FROM orders WHERE service_id='${serviceId}' AND source='site'`)),
      complete: Number(ctx.db.queryScalar(`SELECT COUNT(*) FROM orders WHERE service_id='${serviceId}' AND source='site' AND status='completed'`)),
      download: Number(ctx.db.queryScalar(`SELECT COUNT(*) FROM interactions i JOIN orders o ON o.id = i.order_id WHERE i.kind='download' AND o.service_id='${serviceId}' AND o.source='site'`)),
      regenerate: Number(ctx.db.queryScalar(`SELECT COUNT(*) FROM orders WHERE service_id='${serviceId}' AND source='site' AND regenerated_from_order_id IS NOT NULL`)),
      vote_up: Number(ctx.db.queryScalar(`SELECT COUNT(*) FROM service_votes WHERE service_id='${serviceId}' AND value=1`)),
      vote_down: Number(ctx.db.queryScalar(`SELECT COUNT(*) FROM service_votes WHERE service_id='${serviceId}' AND value=-1`)),
    };

    // Sanity floor: prove this is exercising a realistic, non-empty
    // scenario, not a report that would pass equally well against a fresh
    // database. The exact generate/complete numbers depend on every order
    // Parts 2-3 created -- proven correct below via the DB cross-check,
    // not by re-deriving them here.
    assert.ok(expected.generate >= 10, `expected a realistic generate count from Parts 2-3, got ${expected.generate}`);
    assert.ok(expected.complete >= 8, `expected a realistic complete count from Parts 2-3, got ${expected.complete}`);
    // These three are genuine known constants of the scenario (stated
    // directly in the spec), not derived: Part 2 step 16 downloads exactly
    // once, step 18 regenerates exactly 3 times before hitting the cap, and
    // step 19 flips its vote to "down" and leaves it there.
    assert.strictEqual(expected.download, 1, 'expected exactly the one download from Part 2 step 16');
    assert.strictEqual(expected.regenerate, 3, 'expected exactly the 3 successful regenerates from Part 2 step 18');
    assert.strictEqual(expected.vote_up, 0, 'expected the step-19 vote to have ended flipped to down, not up');
    assert.strictEqual(expected.vote_down, 1, 'expected exactly the one (flipped-to-down) vote from Part 2 step 19');

    const out = ctx.runArtisan(`analytics:report ${serviceId} --json`);
    const [serviceReport] = JSON.parse(out);
    const ladder = serviceReport.interest_ladder.overall;

    assert.deepStrictEqual(
      {
        generate: ladder.generate,
        complete: ladder.complete,
        download: ladder.download,
        regenerate: ladder.regenerate,
        vote_up: ladder.vote_up,
        vote_down: ladder.vote_down,
      },
      expected,
      'expected the interest ladder (analytics:report) to match independently-derived ground truth from the database',
    );

    // admin_preview exclusion, proven via a real differential rather than
    // trusting the repository's own WHERE clause: the ladder's site-only
    // generate count must be exactly one less than every order regardless
    // of source, and that missing one must genuinely be the step-8 preview.
    const totalAllSources = Number(ctx.db.queryScalar(`SELECT COUNT(*) FROM orders WHERE service_id='${serviceId}'`));
    assert.strictEqual(totalAllSources - expected.generate, 1, 'expected exactly the one admin_preview order (step 8) to be excluded from the site-only generate count');
    const previewSource = ctx.db.queryScalar(`SELECT source FROM orders WHERE id='${ctx.state.adminPreviewOrderId}'`);
    assert.strictEqual(previewSource, 'admin_preview', 'sanity: the step-8 order should genuinely be source=admin_preview');
  });
}

module.exports = { run };
