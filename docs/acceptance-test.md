# AIHD-LAB Full Acceptance Test

This is the final proof that the whole system works as a real product — not
unit tests, not mocks of our own code, but a complete journey through admin
and user surfaces against a genuinely external service.

## Ground rules

- Real browser (Playwright) for every UI step. Never assert on the API when
  the point is that a human can do it.
- The external service is a **separate process** on its own port, speaking
  contract v1 over real HTTP. Not a Laravel route, not a fake, not a mock
  class. It is the stand-in for a real dev's server and must know nothing
  about our internals.
- Every artifact is real: actual image files uploaded, actual text, actual
  badges rendered, actual versions published.
- Run it end to end. When something breaks: if the cause is unambiguous and
  the fix is within the spec, fix it (Never-Again test first, red then
  green) and continue. If the cause is ambiguous, or the fix would change a
  design decision, STOP and report — what broke, the evidence, your
  diagnosis, and 2-3 options with a recommendation. Don't guess at intent.

---

## Part 0 — the external service (build this first)

Write a standalone mock AI service (Node or a separate PHP process) that
runs on its own port and implements contract v1 exactly as a real
third-party dev would:

- `POST {post_url}` (the version's configured "job submission" endpoint —
  there is no fixed `/run` path; the admin sets `post_url` per version) —
  authenticates with `Authorization: Bearer {webhook_signing_key}` (the
  same shared secret used for the other two legs below), responds
  immediately with its own job id. Does NOT return results synchronously.
- Then, asynchronously (delay configurable per timing profile so the
  waiting state is real):
  1. Downloads the input image via `GET {our_base}/storage/{media_id}` with
     its Bearer key — the `media_id` for each image/video input arrives in
     the submission payload's `media_ids` array (see wire contract below).
  2. "Processes" it — actually transform the file (resize/tint/whatever) so
     the outputs are genuinely different bytes per `result_number`, not
     copies.
  3. Uploads each of the 4 results via `POST {our_base}/storage` → collects
     the returned media_ids.
  4. Records them in its own tiny store.
  5. POSTs our webhook endpoint (`POST /api/webhooks/{service}/results`,
     fixed and predictable from `service_id` — not a per-request
     `callback_url`) with `{ external_order_id, result_number, type,
     media_id }` per result, each individually HMAC-signed over the raw
     body with the shared key.
- `GET {get_url}` (the version's configured poll endpoint) — same Bearer
  auth, returning status + any results ready. Must work independently of
  the webhook.
- Modes it can be put into for the failure tests: `normal`, `silent`
  (never calls the webhook), `bad-signature`, `failing` (reports failure),
  `slow` (exceeds `response_timeout_s`), `duplicate` (delivers the same
  result twice).

It must never receive S3 credentials, never see a storage path, and treat
`media_id` as an opaque token.

### Wire contract (as implemented)

This section reflects the actual, connected contract — the two fixes
made while building this suite (see the two commits preceding this one:
the outbound payload gained `slug`/`media_ids`, and outbound calls gained
Bearer auth) plus the media_id-reference ingestion path added alongside
them.

**Outbound, us → provider** (`POST {post_url}`, `Authorization: Bearer
{webhook_signing_key}`):
```json
{
  "order_id": "...",
  "inputs": [
    {"input_id": "...", "slug": "room_photo", "value_text": null, "value_bool": null},
    {"input_id": "...", "slug": "hd", "value_text": null, "value_bool": true}
  ],
  "media_ids": [
    {"input_id": "...", "slug": "room_photo", "media_id": "...", "position": 0}
  ],
  "expected_outputs": [
    {"result_number": 1, "type": "image"}
  ]
}
```
Response: `{"external_order_id": "...", "status": "accepted"}`.

**Poll, us → provider** (`GET {get_url}?external_order_id=...`, same
Bearer auth). Pending: `{"status": "pending"}` (anything other than
`"completed"`). Done:
```json
{
  "status": "completed",
  "latency_ms": 1950,
  "results": [
    {"result_number": 1, "type": "image", "media_id": "..."},
    {"result_number": 2, "type": "text", "text": "..."}
  ]
}
```
`content_base64`/`mime` (inline bytes) remain valid alongside `media_id`
per result — both paths are accepted; `media_id` is the preferred path
for real media.

**Webhook, provider → us** (`POST /api/webhooks/{service}/results`,
`X-Signature: HMAC-SHA256(raw_body, webhook_signing_key)`):
```json
{"external_order_id": "...", "result_number": 1, "type": "image", "media_id": "..."}
```
or, for the inline-bytes path: `{"..., "media": {"mime": "image/png",
"content_base64": "..."}}`.

**Result upload, provider → us** (`POST /storage`, same Bearer auth,
multipart `order_id` + `file`) → `{"media_id": "..."}`, 201. The provider
uploads once per result, then references it by `media_id` in the webhook
or poll payload above — it is never asked to re-send the bytes.

**Security**: a `media_id` is an opaque, not-secret token. Every ingest
(webhook or poll) verifies the referenced file was uploaded for the SAME
order before linking it; a mismatch is rejected outright (webhook outcome
`invalid_media_reference`, HTTP 403) rather than silently dropped or
linked. See `IngestResultTest`/`WebhookControllerTest` for the Never-Again
coverage.

---

## Part 1 — admin journey (real browser, real data)

1. Log into the admin UI with a real Sanctum session. Assert a non-admin
   cannot get in.
2. Create a brand-new service from scratch (NOT the seeder's `season-gen`
   — the seed proves the seeder, this proves the product):
   - name, description, tagline, a real uploaded `image_url`, a gallery /
     before-after pair, category, `kind=internal`
   - Paste the `service_secret`. Assert: the value is never displayed back,
     only `has_secret` + the short preview. Assert no "generate" affordance
     exists.
   - Paste the `webhook_signing_key`. Same assertions.
3. Create a draft version pointing `post_url`/`get_url` at the external
   service's real port. Give it a version label. Set `coin_cost` 2,
   `regenerate_limit` 3, `response_timeout_s`, `get_interval_s`,
   `max_get_attempts`.
4. Build the inputs through the UI:
   - an image input (required)
   - a select with 3 options
   - a second select GATED on the first via `option_dependencies` —
     actually wire the dependency in the UI
   - a boolean
   Assert cycle validation fires: attempt to create a dependency loop and
   confirm the server rejects it and the UI surfaces the error.
5. Add 4 image outputs and 3 waiting texts.
6. Try to edit a published version's inputs — assert the UI offers
   "duplicate to draft" instead of an edit form that would fail.
7. Publish. Assert through the API (not just the UI): `status=published`,
   `published_at` set, `services.current_version_id` points at it,
   `consecutive_failures` reset.
8. Run an `admin_preview` order against the external service. Assert: it
   completes, `source=admin_preview`, ZERO coins charged, no strike
   recorded, not counted against caps.

---

## Part 2 — user journey (real browser, real order)

9. As an end customer (core token auth, NOT Sanctum), open the
   marketplace. Assert the new service appears in the grid with its real
   image, its tagline, its category, and the correct cost badge showing 2
   coins. Assert a `coin_cost=0` service renders the Free badge.
10. Open the service detail. Assert the gallery / before-after renders,
    and the form renders from the published version: every input, in
    `sort_order`, with the right control per type, required flags
    honored.
11. Wizard mode: complete the form with a REAL uploaded image. Assert the
    gating actually works — the dependent select is hidden/empty until
    its parent option is chosen, and shows exactly the right options
    after.
12. Submit. Assert, in order:
    - coins deducted (balance drops by exactly 2), `txn_ref` stored on
      the order
    - the order is written with `entry_mode=wizard`, `source=site`,
      pinned to the published version
    - the external service actually received the POST on its own port
    - the external service actually fetched the input via
      `GET /storage/{media_id}` with its key
13. The waiting state: assert the waiting texts rotate, and the status
    reflects queued → processing.
14. Assert completion arrives via Echo (socket), not by polling — the
    browser gets it pushed.
15. Assert all 4 results render, each visibly different, each fetched by
    `media_id`.
16. Download a result. Assert it routes through the logging endpoint
    (never a raw storage URL) and writes an interaction of
    `kind=download`.
17. Assert coins settled exactly once — balance unchanged from step 12's
    post-deduct value.
18. Regenerate. Assert: a SIBLING order is created (`root_order_id`
    chain, `regenerated_from_order_id` set), not a new attempt on the
    same order. Regenerate twice more and assert the 3rd is capped by
    `regenerate_limit`.
19. Vote the service up, then flip to down — assert one row, flipped,
    not two. Bookmark it and assert it appears in the Saved filter. Post
    a comment.
20. Chat mode: run a second complete order through the conversational
    flow. Assert `entry_mode=chat` is what's stored, and that the same
    dependency gating applies in the chat questions.
21. Open an external-kind service. Assert it renders as a link-out card
    with no run flow, and clicking logs an interaction of
    `kind=external_click`.

---

## Part 3 — the failure paths (where systems actually break)

Put the external service into each mode and assert our behavior:

22. **SILENT** (webhook never arrives): assert the poll sweep picks it
    up, the result ingests through the SAME door, the order completes,
    coins settle once. Assert `get_poll_count` incremented and
    `get_interval_s` was respected.
23. **DUPLICATE** (same result delivered twice, once by webhook and once
    by poll): assert exactly ONE result row, ONE settle, ONE broadcast.
    Then flip the ordering (poll first, webhook second) and assert the
    same.
24. **BAD SIGNATURE**: assert we reject it, write a receipt with
    `outcome=invalid_signature`, store the raw body verbatim, and ingest
    nothing.
25. **MALFORMED BODY** (send raw garbage that isn't JSON): assert a
    receipt is STILL written with the body stored verbatim — this is
    the whole reason `raw_body` is text, not json.
26. **UNKNOWN external_order_id**: receipt with `outcome=unknown_order`,
    rejected.
27. **FAILING**: assert `failure_stage` is set, the order fails, coins
    refund exactly once (balance back to where it started),
    `consecutive_failures` increments.
28. **SLOW** (exceeds `response_timeout_s`, then `max_get_attempts`):
    assert `failure_stage=timeout`, refund fires exactly once, strike
    recorded.
29. **THREE CONSECUTIVE FAILURES**: assert the service auto-disables
    (`status=auto_disabled`) and disappears from the user-facing grid.
    Then a success resets `consecutive_failures`.
30. **INSUFFICIENT BALANCE**: drain the account below `coin_cost`,
    submit, assert 402, NO order written, no external call made.
31. **CORE UNREACHABLE**: point the core at a dead port, submit, assert
    the submit fails safely and NO coins are charged without a confirmed
    deduct.
32. **STORAGE AUTH**: assert `GET /storage/{media_id}` with a real user
    Sanctum token is REJECTED (it needs the service key), an unknown
    `media_id` 404s, and a wrong key 401s.

---

## Part 4 — the operator can see it all

33. In the admin order log: find the orders from Parts 2-3. Assert the
    source filter (`site` vs `admin_preview`) and the `entry_mode` filter
    (`wizard` vs `chat`) return the right sets. Assert attempts,
    statuses, `failure_stage`, and poll counts are visible.
34. In the webhook delivery log: find the bad-signature and
    malformed-body deliveries from Part 3. Assert the raw body is
    inspectable — this is the "your webhook didn't fire" lookup a dev
    will actually need.
35. Run the analytics queries. Assert the interest ladder counts match
    what this test actually did (N generates, N completes, 1 download, 3
    regenerates, 1 vote), and that the `admin_preview` order from step 8
    is EXCLUDED from all of them.

---

## Operational contract

### Where it lives

- Same repo, its own branch, one PR, merged to main like any phase. This
  is permanent infrastructure, not a throwaway script.
- Acceptance suite in `tests/Acceptance/`. The external mock service in
  `tools/mock-service/` with its own README documenting the contract and
  its 6 modes — it is the reference implementation real devs will copy.

### How it runs

- One command: `composer acceptance` (or an artisan equivalent). The
  script must boot the mock service on its port, wait for it to be
  healthy, run the suite, and tear it down on exit — including on
  failure. No manual setup steps, no "first start the mock service in
  another terminal".
- Also expose a GitHub Actions workflow with `workflow_dispatch` so it
  can be triggered from the repo UI with no terminal.

### When it runs

- NOT on every push. It does real HTTP with real waits and drives a real
  browser; putting it in the per-push matrix would take CI from ~1
  minute to 10+ and people would start ignoring CI. That failure mode is
  worse than not having it.
- The existing fast suite stays exactly as it is on every push/PR.
- Acceptance runs on: (a) manual dispatch, (b) a nightly schedule
  against `main`, (c) before any release/deploy.
- Nightly failures must be visible — fail loudly, don't let a red
  nightly sit unnoticed.

### Timing profiles

- Ship two profiles: "fast" (default — small timeouts/intervals, whole
  suite in under 10 minutes, used for nightly + manual runs) and
  "realistic" (production-like `response_timeout_s`/`get_interval_s`/
  `max_get_attempts`, used before a release).
- The timing values must come from config the suite sets per-profile,
  never hardcoded in the test or the mock. Same assertions, same logic,
  both profiles — only the clock changes.
- Report the wall-clock runtime of each profile in the final report so
  we know what we're committing to.

### Determinism

- Control time rather than sleeping blindly. The mock's processing delay
  must be configurable so the suite can run fast in CI and slow enough
  locally to actually watch the waiting state.
- The suite must be re-runnable from a clean state (`migrate:fresh
  --seed`) and produce the same result every time. A flaky acceptance
  test is worse than none — if a step is flaky, fix the race, don't add
  a retry.

### When it breaks

- FIX IT YOURSELF when: the cause is unambiguous from the evidence AND
  the fix is clearly within the existing spec (a real bug, a missed
  guard, a wrong column). Write the Never-Again test first, confirm RED,
  fix, confirm GREEN, continue the run.
- STOP AND REPORT when: the cause is ambiguous, OR the fix would change
  a design decision, OR the spec itself is silent on what should happen,
  OR fixing it would touch another phase's contract. Report: what
  broke, the evidence (actual request/response/DB state, not a
  summary), your diagnosis, and 2-3 options with a recommendation.
  Don't guess at intent.
- Never make a test pass by weakening the assertion. If an assertion is
  wrong, say so and explain why — don't quietly relax it.
- Continue past a fixed failure to find the NEXT one. Don't stop the
  whole run for one repairable bug — one run should surface everything
  it can.

### Deliverable

- The external mock service, committed, with its modes documented — it
  doubles as the reference implementation every real dev copies.
- The acceptance suite, runnable with one command, deterministic enough
  for CI (control timing rather than sleeping blindly).
- A FINAL REPORT: every numbered step PASS / FAIL / FIXED. For each
  FIXED: the bug, the Never-Again test name, red-then-green confirmed.
  For each FAIL you stopped on: what broke, the evidence, your
  diagnosis, and the options with your recommendation. Plus the
  wall-clock runtime of the fast and realistic profiles.
