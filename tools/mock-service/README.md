# AIHD-LAB mock external service (contract v1)

A standalone, dependency-free reference implementation of the HTTP contract a
real third-party AI provider integrates against. It runs as its own process,
on its own port, and knows nothing about AIHD-LAB's internals beyond this
contract — it authenticates and calls back into the platform exactly like a
real integrator's server would.

This is the acceptance test's stand-in for a real provider (see
`docs/acceptance-test.md` and `tests/Acceptance/`), but it's written to double
as documentation: if you're integrating a real AI service with AIHD-LAB,
`server.js` is the reference implementation to copy and adapt.

## Running it

```bash
cd tools/mock-service
SHARED_KEY=<the service's webhook_signing_key> node server.js
```

Environment variables:

| Variable | Default | Meaning |
|---|---|---|
| `PORT` | `4100` | Port to listen on. Set the version's `post_url`/`get_url` to point here. |
| `OUR_BASE_URL` | `http://127.0.0.1` | The AIHD-LAB API base this instance calls back into (storage, webhook). |
| `SHARED_KEY` | *(required)* | The service's `webhook_signing_key`. Used to verify inbound Bearer auth on `/run`/`/jobs`, to authenticate this service's own calls to `/storage`, and as the HMAC secret for outbound webhook signatures. |
| `PROCESSING_DELAY_MS` | `500` | How long after accepting a job before it's "processed" — the real wait a waiting-state UI has to render. Set larger for the "realistic" timing profile. |
| `SLOW_DELAY_MS` | `15000` | How long the `slow` mode's poll handler deliberately takes to respond — must exceed the version's `response_timeout_s` to actually trigger a client-side timeout. |
| `DEFAULT_MODE` | `normal` | Starting mode; can be changed at runtime (see below). |

## The contract

**`POST {post_url}`** (mounted here as `/run`) — submit a job.
Requires `Authorization: Bearer {webhook_signing_key}`. Body:

```json
{
  "order_id": "...",
  "inputs": [{"input_id": "...", "slug": "room_photo", "value_text": null, "value_bool": null}],
  "media_ids": [{"input_id": "...", "slug": "room_photo", "media_id": "...", "position": 0}],
  "expected_outputs": [{"result_number": 1, "type": "image"}]
}
```

Responds immediately (never synchronously with results):

```json
{"external_order_id": "...", "status": "accepted"}
```

Then, asynchronously (after `PROCESSING_DELAY_MS`, unless `mode=slow`):

1. Downloads the first input's media via `GET {OUR_BASE_URL}/api/storage/{media_id}` with its Bearer key.
2. "Processes" it — XORs every byte with a constant derived from the result
   number (a dependency-free stand-in for "resize/tint/whatever" mentioned
   in the acceptance spec) — so each output is a genuine, different transform
   of the real input, never a copy of it or of another result.
3. Uploads each result via `POST {OUR_BASE_URL}/api/storage` (multipart
   `order_id` + `file`) → collects the returned `media_id`.
4. Records the job's results in its own in-memory store.
5. POSTs the configured webhook URL with one delivery per result, each
   individually HMAC-signed (`X-Signature: hex(hmac_sha256(raw_body,
   webhook_signing_key))`):
   ```json
   {"external_order_id": "...", "result_number": 1, "type": "image", "media_id": "..."}
   ```

**`GET {get_url}`** (mounted here as `/jobs`, `external_order_id` as a query
param — matches how the platform's poll client calls it) — same Bearer auth.
Works independently of the webhook:

- Pending: `{"status": "pending"}`
- Completed: `{"status": "completed", "latency_ms": N, "results": [...]}`
- Failed: `{"status": "failed", "reason": "..."}`

**`GET /health`** — `{"status": "ok", "mode": "...", "jobs": N}`. Used by the
acceptance runner to wait for the process to be ready.

## Runtime control (used by the acceptance suite, not part of contract v1)

- `POST /admin/mode {"mode": "..."}` — switch behavior for every job
  submitted from this point on (see modes below). Each job snapshots the
  mode active at submission time, so changing it mid-run never affects a
  job already in flight.
- `POST /admin/configure {"webhook_url": "..."}` — set the fixed webhook
  endpoint to call back to (`http://.../api/webhooks/{service_id}/results`
  — real integrators are told this URL once, during onboarding; it isn't
  sent per-request).
- `POST /admin/reset` — clear all in-memory jobs and reset mode to `normal`.

## Modes

| Mode | Behavior |
|---|---|
| `normal` | Full happy path: download, transform, upload, webhook per result. |
| `silent` | Same processing, but the webhook is never called. Only discoverable via poll. |
| `bad-signature` | Webhook fires with a deliberately wrong `X-Signature`. |
| `failing` | Instead of results, reports an explicit failure: webhook `{"status":"failed","reason":"..."}`, and poll responds `{"status":"failed","reason":"..."}` too. |
| `slow` | Poll responses deliberately take `SLOW_DELAY_MS` to answer — long enough to exceed the version's `response_timeout_s`, so the platform's own HTTP client times out first. Never fires a webhook. |
| `duplicate` | Fires the webhook for each result TWICE (150ms apart), so the same result is delivered redundantly. |

## What it deliberately does not do

- Never receives S3/storage credentials — `media_id` is an opaque token to
  it, resolved only through the platform's own `/storage` endpoints.
- Never sees a disk path or bucket name.
- Holds no state beyond a single in-memory `Map` — restarting it (or
  calling `/admin/reset`) forgets every job.
