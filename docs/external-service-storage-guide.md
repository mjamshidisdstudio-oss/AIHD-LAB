# External service storage guide

How a service hosted on AIHD-LAB's platform reads a user's uploaded input and
stores its generated results. We host this storage ourselves — it is not a
core-team or third-party dependency, and it's the same API your service and
our own platform both use for every media flow. `tools/mock-service/` is a
runnable reference implementation of everything below; copy `server.js`'s
`/storage` calls directly if you're integrating a real service.

## Auth

Every storage call is authenticated by your service's `webhook_signing_key`
(shown once, at onboarding, by the operator who registers your service — it
is never re-displayed and never recoverable if lost, only reset). Send it as
a bearer token:

```
Authorization: Bearer <webhook_signing_key>
```

This is **not** a user session or Sanctum token — your service has no user
identity of its own. A real logged-in platform user's token, or any other
service's key, is rejected the same way a missing one is: `401`.

## `media_id` is opaque

Every file — a user's input, one of your results — is identified by an
opaque `media_id`. You will never see a disk path, a bucket name, or a URL to
fetch a file directly from storage; `media_id` is the only handle you get,
and the only thing you ever pass back to us. This is deliberate: it means our
storage backend (local disk today, S3/R2 in production) can change without
your integration ever needing to change.

## Fetching a user's input: `GET /api/storage/{media_id}`

Call this once your service accepts a job (`POST {your post_url}` — see the
order/webhook contract) — the job payload you receive includes the
`media_id` for each uploaded input.

```
GET /api/storage/{media_id}
Authorization: Bearer <webhook_signing_key>
```

**Success** — `200`, the raw file bytes, with a `Content-Type` matching the
original upload (e.g. `image/png`).

**Errors:**

| Status | Meaning |
|---|---|
| `401` | Missing, wrong, or non-service (e.g. a user's) bearer token. |
| `404` | Unknown `media_id`, or the underlying file is genuinely gone. |

Never a `500` — an unknown or wrongly-authed request always resolves to one
of the above, not a crash.

## Storing a result: `POST /api/storage`

Call this once per output your job produces (matching the `expected_outputs`
your job was given), before sending the corresponding webhook delivery.

```
POST /api/storage
Authorization: Bearer <webhook_signing_key>
Content-Type: multipart/form-data

order_id=<the order id from your job payload>
file=<the result's raw bytes>
```

**Success** — `201`:

```json
{"media_id": "9f2b3c4d-..."}
```

Put this `media_id` straight into your webhook delivery for that result
(`{"external_order_id": "...", "result_number": N, "type": "image", "media_id": "9f2b3c4d-..."}`)
— you never construct or guess a `media_id` yourself; it always comes from
this response.

**Errors:**

| Status | Meaning |
|---|---|
| `401` | Missing or wrong bearer token. |
| `422` | Unknown `order_id`, or no `file` present. |
| `413` | File exceeds the upload ceiling (10 MiB). |

Never a `500`.

## End-to-end example

1. We `POST {your post_url}` with the job, including each input's `media_id`.
2. You `GET /api/storage/{that media_id}` (this guide, above) to fetch the
   actual input bytes.
3. You generate your result(s).
4. For each result, you `POST /api/storage` (this guide, above) and get back
   a `media_id`.
5. You deliver your webhook per result, each carrying the `media_id` from
   step 4 — see the order/webhook contract for the full delivery shape and
   HMAC signing.

`tools/mock-service/server.js` implements exactly this sequence end to end,
including the byte-for-byte fetch → transform → store round trip, and is
exercised by the acceptance suite (`tests/Acceptance/`) as a stand-in for a
real integrator.

## What you should never do

- Never expect a raw storage URL, disk path, or bucket name — you will never
  receive one, from this endpoint or any other.
- Never persist a `media_id` you didn't get directly from either a job
  payload (for inputs) or a `POST /api/storage` response (for your own
  results) — there is no other valid source for one.
- Never retry a `POST /api/storage` call on a `401`/`422`/`413` expecting a
  different result — those are the service's stated, stable answer for that
  request; the fix is on your side (a rotated key, an order you don't
  recognize, a file that's too large), not a transient condition.
