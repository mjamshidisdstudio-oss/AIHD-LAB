# Acceptance Test — Final Report

Full end-to-end acceptance suite for AIHD-LAB, per `docs/acceptance-test.md`.
Real browser (Playwright), real Laravel/Reverb/Nuxt processes, a genuinely
separate external mock service (`tools/mock-service/`) — no mocked HTTP, no
faked timers.

## Result

**35/35 steps pass on both timing profiles.**

| Profile | Steps | Wall-clock |
|---|---|---|
| fast (nightly/manual default) | 35/35 | 185.5s |
| realistic (production-like timeouts, pre-release) | 35/35 | 378.1s |

Every numbered step below is PASS. None were stopped on — every failure
encountered during development had an unambiguous root cause traceable to
exact application or test code, and none touched another phase's contract or
required a design decision, so nothing needed to be escalated rather than
fixed.

## Every step

**Part 1 — the operator builds a service (steps 1-8)**
1. PASS — Admin login (real Sanctum session); non-admin rejected
2. PASS — Create a brand-new service from scratch; secrets never displayed
3. PASS — Create a draft version pointed at the external mock service
4. PASS — Build inputs: image (required), gated selects, boolean; cycle validation rejected
5. PASS — Add 4 image outputs and 3 waiting texts
6. PASS — Editing a published version's inputs offers duplicate-to-draft, not an edit form
7. PASS — Publish v1: status/published_at/current_version_id/consecutive_failures
8. PASS — Admin preview completes: source=admin_preview, ZERO coins, no strike, cap-free

**Part 2 — the user journey (steps 9-21)**
9. PASS — Marketplace grid: real image/tagline/category/cost badge; Free badge for coin_cost=0
10. PASS — Service detail: gallery and before/after render for the published version
11. PASS — Wizard mode: real uploaded image; dependent select gating
12. PASS — Submit: coins deducted exactly, order fields correct, external service actually received the POST and fetched the input
13. PASS — Waiting state: genuine waiting_texts, rotate whenever real elapsed time allows it
14. PASS — Completion arrives via Echo (socket), not by polling
15. PASS — All 4 results render, each genuinely different, each linked by media_id
16. PASS — Download routes through the logging endpoint, writes an interaction of kind=download
17. PASS — Coins settled exactly once
18. PASS — Regenerate creates a SIBLING order; further regenerates capped by regenerate_limit
19. PASS — Vote up then flip to down (one row, flipped); bookmark + Saved filter; post a comment
20. PASS — Chat mode: a second complete order, entry_mode=chat, same gating
21. PASS — External-kind service renders as a link-out card; clicking logs kind=external_click

**Part 3 — failure paths (steps 22-32)**
22. PASS — SILENT: the poll sweep picks it up through the same ingest door, settles exactly once
23. PASS — DUPLICATE: same result via webhook AND poll (both orderings) — exactly one row, one settle
24. PASS — BAD SIGNATURE: rejected, receipt recorded with outcome=invalid_signature, raw body stored verbatim
25. PASS — MALFORMED BODY: non-JSON body still gets a receipt with the body stored verbatim
26. PASS — UNKNOWN external_order_id: receipt with outcome=unknown_order, rejected
27. PASS — FAILING: failure_stage set, order fails, coins refunded exactly once, consecutive_failures increments
28. PASS — SLOW: exceeds response_timeout_s then max_get_attempts; failure_stage=timeout, refund once
29. PASS — THREE CONSECUTIVE FAILURES: service auto-disables and disappears from the grid; a success resets the counter
30. PASS — INSUFFICIENT BALANCE: 402, no order written, no external call
31. PASS — CORE UNREACHABLE: submit fails safely, no coins charged without a confirmed deduct
32. PASS — STORAGE AUTH: real Sanctum token rejected, unknown media_id 404s, wrong key 401s

**Part 4 — operator visibility (steps 33-35)**
33. PASS — Admin order log: source/entry_mode/status filters return the right sets; attempts, statuses, failure_stage, poll counts visible
34. PASS — Webhook delivery log: bad-signature and malformed-body deliveries findable, raw body inspectable
35. PASS — Analytics: interest ladder counts match ground truth; admin_preview order excluded from all of them

## Bugs found and fixed

Every fix below lives in the acceptance suite itself (or, in one case, a
harness-only test-support command) — none touched production application
code. The acceptance suite's own steps are the regression guard: any of
these bugs recurring would immediately fail the corresponding step on the
next run, which is why none needed a separate PHPUnit "Never-Again" test on
top of it.

1. **Playwright substring-collision assertions** — a fixture named
   "Acceptance Free Filter" with "free" in its tagline collided with the
   Free badge's own `text=Free` (case-insensitive substring match); the
   "Before & after" section heading collided with the "Before" overlay
   badge the same way. *Fix:* renamed the fixture, switched both checks to
   exact quoted matches (`text="Free"`, `text="Before"`).
2. **Playwright anchored-regex whitespace hang** — Vue's whitespace
   condensing left real leading spaces around several button labels (e.g.
   `" Next"`), which an anchored regex like `/^Next$/` never matches;
   `.click()` then silently polled out its full 30s timeout looking for an
   element that was actually on the page the whole time. *Fix:* relaxed
   every anchored regex site to tolerate surrounding whitespace
   (`/^\s*Next\s*$/`).
3. **`CACHE_STORE=array` can't persist `LocalCoreStubState` across real
   requests** — the single biggest infrastructure finding of the whole
   effort. `array` cache lives only for one PHP request's lifetime; this
   suite makes genuinely separate HTTP requests against a real running
   server, so a coin deduct made by one request was invisible to a balance
   check made by the next. This only ever looked fine in PHPUnit feature
   tests, which share one process for an entire test method. *Fix:*
   `CACHE_STORE=file` + `php artisan cache:clear` in the suite's setup,
   giving the file cache the same clean-state guarantee `migrate:fresh`
   gives the database. Fixed five cascading Part 2 failures at once.
4. **Step 14's Echo-timing proof anchored on the wrong clock** — anchoring
   "did completion arrive before the poll fallback" on the test's own
   150ms-interval polling loop made the assertion vulnerable to the test's
   own measurement lag. *Fix:* anchor on the websocket frame's own
   timestamp instead.
5. **Step 21's external-link check depended on post-navigation browser
   state** (`popup.url()`), which intermittently read as
   `chrome-error://chromewebdata/` in this sandbox's proxy even for a real
   domain navigated immediately. *Fix:* monkeypatch `window.open` to record
   its actual argument, removing any dependency on the popup's navigation
   ever completing.
6. **Part 3 tripped the real `submit-order` rate limiter (10/min)** — once
   bug 3 was fixed, this rate limiter (a real Phase 8 hardening feature)
   started actually working, and Parts 2+3's combined submissions
   legitimately exceeded 10 within a minute, failing steps 27/28/29/30 with
   genuine 429s unrelated to what those steps test. *Fix:* a test-support-
   only artisan command (`acceptance:reset-rate-limit`) that clears one
   named limiter's bucket for one identity, invoked twice in Part 3 so each
   half of its submissions starts with a full budget.
7. **Step 28 hardcoded exactly 2 manual `poll:sweep` calls** — correct only
   because the fast profile's `max_get_attempts` happens to be 2; the
   realistic profile's `max_get_attempts=3` needs 3. *Fix:* generalized to
   a loop over `ctx.config.version.maxGetAttempts`. Found and fixed by
   reading `PollRequest`'s attempt-budget check before ever running the
   realistic profile, not from a failure.
8. **Four other `poll:sweep` calls had no explicit interval wait** — they
   worked under the fast profile's 1s `get_interval_s` only because
   whatever incidental waiting preceded them (polling the mock for job
   completion) happened to exceed 1s by coincidence; the realistic
   profile's 10s interval isn't guaranteed to be exceeded the same way.
   *Fix:* a shared `sweepAfterInterval()` helper that explicitly waits out
   the interval before every sweep call. Also found via code review before
   running the realistic profile.
9. **Step 34 assumed exactly one `invalid_signature` webhook delivery** —
   the mock actually sends one webhook per declared output (4, matching
   this version) in bad-signature mode, all with the same wrong signature,
   so the service genuinely has 4 such receipts. *Fix:* match the specific
   delivery Part 3 recorded (by id, already stashed in `ctx.state`) instead
   of asserting a count.
10. **Step 5's waiting-text loop raced the frontend's own save cycle** —
    `addWaitingText()` computes `sort_order` from in-memory state, then
    reloads the version before clearing the input; the test's old blind
    150ms wait between clicks wasn't always enough under heavier load (four
    acceptance runs back to back), so the final API check could run before
    the last add had landed. *Fix:* wait for each waiting text to actually
    render (proof the round trip landed) instead of a fixed sleep.
11. **Step 13's rotation check anchored on the wrong clock** — the biggest
    finding from the realistic profile's first-ever run. The frontend's
    rotation timer starts once the loading panel actually appears, not at
    submit time; the test measured elapsed time from submit, which
    included step 12's own job/download-confirmation polling and other
    submission overhead the rotation clock never saw. Under the fast
    profile (`processingDelayMs=500`) this branch of the assertion was dead
    code across 20+ runs — it never got close to the 2600ms rotation
    threshold. The realistic profile's `processingDelayMs=3000` finally
    crossed it and exposed the measurement bug: elapsed time looked large
    enough to demand a rotation the panel never had enough genuinely-
    watched time to produce. *Fix:* track `firstSeenAt` (first observed
    waiting text) and compare against that instead of submit time.
12. **Chromium `executablePath` was hardcoded to a sandbox-specific path**
    (`/opt/pw-browsers/chromium`, a convenience symlink that only exists in
    the environment this suite was built in) — not a bug surfaced by a
    failure, but a portability gap that would have broken the new GitHub
    Actions workflow's `npx playwright install chromium` outright. *Fix:*
    made it conditional on that exact path existing, falling back to
    Playwright's own resolution everywhere else.

## Deliverables

- External mock service: `tools/mock-service/`, contract v1 documented in
  its own README, 6 modes (normal/silent/bad-signature/failing/slow/duplicate).
- Acceptance suite: `tests/Acceptance/`, runnable with one command
  (`composer acceptance` for the fast profile, `composer acceptance:realistic`
  for the realistic one), deterministic (`migrate:fresh --seed` +
  `cache:clear` before every run; every wait is a real signal, never a
  blind sleep).
- GitHub Actions workflow: `.github/workflows/acceptance.yml` —
  `workflow_dispatch` (with a fast/realistic profile choice) plus a nightly
  cron against `main`, deliberately separate from the existing per-push
  `tests.yml`.
- This report.
