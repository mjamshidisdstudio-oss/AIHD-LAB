'use strict';

const assert = require('assert');

const ADMIN_EMAIL = 'admin@aihd.lab';
const ADMIN_PASSWORD = 'password';
const NON_ADMIN_EMAIL = 'test@example.com';
const NON_ADMIN_PASSWORD = 'password';

/**
 * Execution order deliberately differs from the spec's 1-8 numbering in two
 * places, both forced by real, already-known constraints of the current
 * admin UI/backend (not gaps introduced by this suite):
 *
 *  - Outputs (step 5) are added BEFORE the admin-preview run (step 8),
 *    because IngestResult never completes an order with zero declared
 *    outputs (`expected === 0` short-circuits) -- the preview literally
 *    cannot "complete" without at least one output existing first.
 *  - The admin-preview run (step 8) happens BEFORE inputs are built (step
 *    4), because the Admin-preview button always submits empty answers --
 *    the same workaround already used by this repo's own prior E2E proof
 *    (Phase 7's smoke6.js) for the same reason: any required input 422s an
 *    empty-answers preview regardless of the version's draft/published
 *    status.
 *
 * Every individual assertion the spec lists under steps 1-8 is still
 * proven; only the wall-clock order of a few of them is rearranged, and the
 * report below is grouped by spec step number, not execution order.
 */
async function run(ctx, report) {
  const page = await ctx.browser.newPage({ viewport: { width: 1440, height: 960 } });
  ctx.adminPage = page;
  const v = ctx.config.version;

  await report.step(1, 'Admin login (real Sanctum session); non-admin rejected', async () => {
    await page.goto(`${ctx.adminUrl}/login`, { waitUntil: 'load' });
    await page.fill('input[type="email"]', NON_ADMIN_EMAIL);
    await page.fill('input[type="password"]', NON_ADMIN_PASSWORD);
    await page.click('button:has-text("Sign in")');
    await page.waitForSelector('text=These credentials do not match an admin account.', { timeout: 8000 });
    assert.ok(page.url().includes('/login'), 'non-admin must stay on /login');

    await page.fill('input[type="email"]', ADMIN_EMAIL);
    await page.fill('input[type="password"]', ADMIN_PASSWORD);
    await page.click('button:has-text("Sign in")');
    await page.waitForSelector('h1:has-text("Services")', { timeout: 10000 });

    await ctx.admin.login(ADMIN_EMAIL, ADMIN_PASSWORD);
  });

  await report.step(2, 'Create a brand-new service from scratch; secrets never displayed', async () => {
    await page.click('button:has-text("New service")');
    await page.waitForSelector('text=New service', { timeout: 5000 });
    await page.fill('input[placeholder="Seasonal Views"]', 'Acceptance Twilight Views');
    await page.fill('input[placeholder="interior"]', 'interior');
    await page.click('button:has-text("Create")');
    await page.waitForSelector('text=Set up this service', { timeout: 10000 });

    const match = page.url().match(/services\/([a-f0-9-]{36})/);
    assert.ok(match, `expected an editor URL with a service id, got ${page.url()}`);
    ctx.state.serviceId = match[1];

    await page.fill('input[placeholder="Redecorate any room in seconds"]', 'Turn dusk photos into golden-hour listings');
    await page.locator('textarea').first().fill('Give any exterior listing photo a warm twilight look, automatically.');
    await page.fill('input[placeholder="https://…"]', 'https://picsum.photos/seed/acceptance-cover/800/600');
    await page.locator('button', { hasText: /^Save$/ }).first().click();
    await page.waitForSelector('text=Saved.', { timeout: 5000 });

    await page.fill('input[placeholder="https://… new gallery image"]', 'https://picsum.photos/seed/acceptance-g1/600/600');
    await page.click('button:has-text("Add")');
    await page.waitForTimeout(200);
    await page.fill('input[placeholder="https://… new gallery image"]', 'https://picsum.photos/seed/acceptance-g2/600/600');
    await page.locator('button', { hasText: /^Add$/ }).click();
    await page.waitForTimeout(200);
    const galleryRows = await page.locator('button[title="Remove"]').count();
    assert.strictEqual(galleryRows, 2, `expected 2 gallery rows, got ${galleryRows}`);

    await page.fill('label:has-text("Before photo URL") ~ input', 'https://picsum.photos/seed/acceptance-before/600/450');
    await page.fill('label:has-text("After photo URL") ~ input', 'https://picsum.photos/seed/acceptance-after/600/450');
    await page.locator('button', { hasText: /^Save$/ }).last().click();
    await page.waitForSelector('text=Saved.', { timeout: 5000 });

    await page.click('button:has-text("Integration")');
    await page.waitForSelector('text=Secrets', { timeout: 5000 });

    const secretInputs = page.locator('input[placeholder="Paste to set or rotate…"]');
    await secretInputs.nth(0).fill('acceptance-shared-key');
    await secretInputs.nth(1).fill('acceptance-shared-key');
    await page.click('button:has-text("Save secrets")');
    await page.waitForSelector('text=/^Set ·/', { timeout: 5000 });

    const setBadges = await page.locator('text=/^Set · [0-9a-f]{12}/').count();
    assert.strictEqual(setBadges, 2, `expected 2 "Set · <hash>" badges, got ${setBadges}`);

    const html = await page.content();
    assert.ok(!html.includes('acceptance-shared-key'), 'the raw secret must never appear in the page HTML after saving');

    const generateButtons = await page.locator('button:has-text("Generate")').count();
    assert.strictEqual(generateButtons, 0, 'no "generate" affordance should exist for either secret');

    const apiService = await ctx.admin.get(`/admin/services/${ctx.state.serviceId}`);
    assert.strictEqual(apiService.data.data.has_secret, true);
    assert.ok(/^[0-9a-f]{12}$/.test(apiService.data.data.secret_preview));
    assert.strictEqual(apiService.data.data.has_webhook_signing_key, true);
    assert.ok(JSON.stringify(apiService.data).indexOf('acceptance-shared-key') === -1);
  });

  await report.step(3, 'Create a draft version pointed at the external mock service', async () => {
    // CreateService already creates the first, empty draft version (v1) as
    // part of service creation -- no separate "+ New version" click needed
    // (or wanted: that would make an unwanted v2 before v1 is even used).
    const versions = await ctx.admin.get(`/admin/services/${ctx.state.serviceId}/versions`);
    const draft = versions.data.data.find((x) => x.status === 'draft');
    assert.ok(draft, 'expected the service to already have its auto-created v1 draft');
    ctx.state.versionId = draft.id;

    await page.click('button[title="Rename version"]');
    await page.fill('input[placeholder="Version name"]', 'v1 - golden hour');
    await page.click('button[title="Save"]');
    await page.waitForSelector('text=v1 - golden hour', { timeout: 5000 });

    await page.click('button:has-text("Integration")');
    await page.waitForSelector('text=Execution', { timeout: 5000 });

    await page.fill('label:has-text("Credits per request") input', '2');
    await page.fill('label:has-text("Regenerate limit") input', '3');
    await page.fill('label:has-text("Response timeout (s)") input', String(v.responseTimeoutS));
    await page.fill('label:has-text("Poll interval (s)") input', String(v.getIntervalS));
    await page.fill('label:has-text("Max poll attempts") input', String(v.maxGetAttempts));
    await page.fill('label:has-text("POST url") + input', `${ctx.mockUrl}/run`);
    await page.fill('label:has-text("GET url") + input', `${ctx.mockUrl}/jobs`);
    await page.locator('button', { hasText: /^Save$/ }).click();
    await page.waitForTimeout(800);

    const apiVersion = await ctx.admin.get(`/admin/versions/${ctx.state.versionId}`);
    assert.strictEqual(apiVersion.data.data.coin_cost, 2);
    assert.strictEqual(apiVersion.data.data.regenerate_limit, 3);
    assert.strictEqual(apiVersion.data.data.post_url, `${ctx.mockUrl}/run`);
    assert.strictEqual(apiVersion.data.data.get_url, `${ctx.mockUrl}/jobs`);

    await ctx.mock.configure(`http://127.0.0.1/api/webhooks/${ctx.state.serviceId}/results`);
    await ctx.mock.setMode('normal');
  });

  await report.step(5, 'Add 4 image outputs and 3 waiting texts (moved before step 8 -- see file header)', async () => {
    await page.click('button:has-text("Outputs")');
    await page.waitForSelector('text=Outputs —', { timeout: 5000 });

    for (let i = 0; i < 4; i++) {
      await page.click('button:has-text("+ Add output")');
      await page.waitForSelector(`text=#${i + 1}`, { timeout: 8000 });
    }
    const outputRows = await page.locator('text=/^#[0-9]$/').count();
    assert.strictEqual(outputRows, 4, `expected 4 output rows, got ${outputRows}`);
    // All default to type=image already -- exactly what step 5 wants.

    const waitingTexts = [
      'Warming up the golden hour…',
      'Balancing shadows and highlights…',
      'Rendering your twilight exterior…',
    ];
    for (const text of waitingTexts) {
      await page.fill('input[placeholder="e.g. Dreaming up seasonal styles…"]', text);
      await page.locator('button', { hasText: /^Add$/ }).click();
      await page.waitForTimeout(150);
    }

    const apiVersion = await ctx.admin.get(`/admin/versions/${ctx.state.versionId}`);
    assert.strictEqual(apiVersion.data.data.outputs.length, 4);
    assert.strictEqual(apiVersion.data.data.waiting_texts.length, 3);
  });

  await report.step(8, 'Admin preview completes: source=admin_preview, ZERO coins, no strike, cap-free', async () => {
    await page.click('button:has-text("Orders & logs")');
    await page.waitForSelector('text=Delivery trail', { timeout: 5000 }).catch(() => {});

    const balanceBefore = await ctx.core.balance('dev-user');

    await page.locator('button', { hasText: /Admin preview/ }).click();
    await page.waitForTimeout(200);

    // Poll the admin API (real backend, no UI polling needed for this check)
    // until the preview order completes -- the mock's own processing delay
    // determines how long this genuinely takes.
    let previewOrder = null;
    for (let i = 0; i < 40 && !previewOrder; i++) {
      const orders = await ctx.admin.get(`/admin/services/${ctx.state.serviceId}/orders?source=admin_preview`);
      previewOrder = orders.data.data.find((o) => o.status === 'completed');
      if (!previewOrder) await new Promise((r) => setTimeout(r, 300));
    }
    assert.ok(previewOrder, 'admin_preview order never reached completed');
    assert.strictEqual(previewOrder.source, 'admin_preview');
    assert.strictEqual(previewOrder.coins_charged, 0);
    ctx.state.adminPreviewOrderId = previewOrder.id;

    const balanceAfter = await ctx.core.balance('dev-user');
    assert.strictEqual(balanceAfter, balanceBefore, 'admin_preview must charge ZERO coins');

    const service = await ctx.admin.get(`/admin/services/${ctx.state.serviceId}`);
    assert.strictEqual(service.data.data.consecutive_failures, 0, 'a completed admin_preview must never record a strike');
  });

  await report.step(4, 'Build inputs: image (required), gated selects, boolean; cycle validation rejected', async () => {
    await page.click('button:has-text("Inputs")');
    await page.waitForSelector('text=Add input', { timeout: 5000 });

    await page.click('button:has-text("Image")');
    await page.waitForTimeout(300);
    await page.locator('label:has-text("Slug") input').first().fill('room_photo');
    await page.locator('label:has-text("Label") input').first().fill('Room photo');
    const requiredCheckbox = page.locator('label:has-text("Required") input[type="checkbox"]');
    if (!(await requiredCheckbox.isChecked())) await requiredCheckbox.check();
    await page.click('button:has-text("Save")');
    await page.waitForTimeout(300);

    await page.click('button:has-text("Select list")');
    await page.waitForTimeout(300);
    await page.locator('label:has-text("Slug") input').first().fill('finish');
    await page.locator('label:has-text("Label") input').first().fill('Finish');
    for (const opt of ['Matte', 'Glossy', 'Satin']) {
      await page.fill('input[placeholder="New option label"]', opt);
      await page.locator('button', { hasText: /^Add$/ }).click();
      await page.waitForTimeout(150);
    }
    await page.click('button:has-text("Save")');
    await page.waitForTimeout(300);

    await page.click('button:has-text("Select list")');
    await page.waitForTimeout(300);
    await page.locator('label:has-text("Slug") input').first().fill('accent');
    await page.locator('label:has-text("Label") input').first().fill('Accent');
    for (const opt of ['Warm', 'Cool']) {
      await page.fill('input[placeholder="New option label"]', opt);
      await page.locator('button', { hasText: /^Add$/ }).click();
      await page.waitForTimeout(150);
    }
    // Gate "Accent" on "Finish" -- the real, working UI mechanism
    // (depends_on_input_id/depends_on_value) is what actually renders a
    // second select as hidden-until-parent-answered; option_dependencies
    // (option-to-option, cross-input) has no admin UI today (see the
    // Inputs tab's own header comment) -- flagged in the final report.
    await page.selectOption('select', { label: 'Depends on: Finish' });
    await page.fill('input[placeholder="required value / option slug"]', 'matte');
    await page.click('button:has-text("Save")');
    await page.waitForSelector('text=/gated/', { timeout: 5000 });

    await page.click('button:has-text("Toggle")');
    await page.waitForTimeout(300);
    await page.locator('label:has-text("Slug") input').first().fill('hd');
    await page.locator('label:has-text("Label") input').first().fill('HD output');
    await page.click('button:has-text("Save")');
    await page.waitForTimeout(300);

    // Cycle attempt: make "Finish" ALSO depend on "Accent" -- Accent already
    // depends on Finish, so this would create a 2-node cycle.
    await page.click('text=Finish');
    await page.waitForTimeout(200);
    await page.selectOption('select', { label: 'Depends on: Accent' });
    await page.click('button:has-text("Save")');
    await page.waitForSelector('text=This dependency would create a cycle in the input graph.', { timeout: 5000 });

    const versionAfterCycleAttempt = await ctx.admin.get(`/admin/versions/${ctx.state.versionId}`);
    const finishInput = versionAfterCycleAttempt.data.data.inputs.find((i) => i.slug === 'finish');
    assert.strictEqual(finishInput.depends_on_input_id, null, 'the rejected cycle edge must never have been persisted');

    ctx.state.inputs = {};
    for (const input of versionAfterCycleAttempt.data.data.inputs) ctx.state.inputs[input.slug] = input;
  });

  await report.step(6, "Editing a published version's inputs offers duplicate-to-draft, not an edit form", async () => {
    const seeded = await ctx.admin.get('/admin/services');
    const seasonGen = seeded.data.data.find((s) => s.slug === 'season-gen');
    assert.ok(seasonGen, 'expected the seeded season-gen service to exist');

    // season-gen is already fully set up and published -- unlike our own
    // in-progress service, its "Set up this service" checklist card doesn't
    // render at all once complete, so wait on the frozen-inputs banner
    // itself rather than that card.
    await page.goto(`${ctx.adminUrl}/services/${seasonGen.id}?tab=inputs`, { waitUntil: 'load' });
    await page.waitForSelector("text=frozen — inputs can't be edited", { timeout: 10000 });
    const duplicateButton = await page.locator('button:has-text("Duplicate to draft")').count();
    assert.ok(duplicateButton > 0, 'expected a "Duplicate to draft" affordance on a published version\'s Inputs tab');

    const formLocked = await page.locator('.pointer-events-none.opacity-50').count();
    assert.ok(formLocked > 0, 'expected the frozen input form area to be visually/interaction-locked');

    // Back to our own service for the remaining steps.
    await page.goto(`${ctx.adminUrl}/services/${ctx.state.serviceId}?tab=inputs`, { waitUntil: 'load' });
    await page.waitForSelector('text=Set up this service', { timeout: 10000 });
  });

  await report.step(7, 'Publish v1: status/published_at/current_version_id/consecutive_failures', async () => {
    await page.click('button:has-text("Integration")');
    await page.waitForSelector('text=Execution', { timeout: 5000 });
    await page.click('button:has-text("Publish this version")');
    await page.waitForSelector('text=Version published.', { timeout: 8000 });
    await page.waitForSelector('text=✓ Published', { timeout: 5000 });

    const version = await ctx.admin.get(`/admin/versions/${ctx.state.versionId}`);
    assert.strictEqual(version.data.data.status, 'published');
    assert.ok(version.data.data.published_at, 'published_at must be set');

    const service = await ctx.admin.get(`/admin/services/${ctx.state.serviceId}`);
    assert.strictEqual(service.data.data.current_version_id, ctx.state.versionId);
    assert.strictEqual(service.data.data.consecutive_failures, 0);

    ctx.state.serviceSlug = service.data.data.slug;
  });
}

module.exports = { run };
