# AIHD Lab Marketplace

The consumer-facing Nuxt 3 client for the marketplace: browse services, run
one (wizard or chat), watch it complete, and download the results. Talks to
the Laravel API in the parent repo — it does not run any backend of its own.

## Setup

```bash
npm install
```

## Development

The API must be reachable at the URL in `NUXT_PUBLIC_API_BASE` (defaults to
`http://localhost/api`). If your Laravel dev server's origin also happens to
be in `SANCTUM_STATEFUL_DOMAINS` (Sanctum's default list includes
`localhost:3000`), run this app on a different port — that combination makes
Sanctum treat plain bearer-token requests as needing CSRF, which they don't.

```bash
NUXT_PUBLIC_API_BASE=http://localhost/api npm run dev -- --port 3100
```

There is no login screen — identity is a bearer token issued by the core
platform this app is embedded in. Locally, it falls back to the seeded
core-stub dev token (`NUXT_PUBLIC_DEV_TOKEN`, default `dev-token`) so the app
is usable standalone; a real embedding passes a real token via `?token=`.

### Realtime (optional)

`OrderCompleted` broadcasts over a private `orders.{user_ref}` Pusher channel
authenticated through `/api/marketplace/broadcasting/auth`. Without real
Pusher credentials (`NUXT_PUBLIC_PUSHER_APP_KEY` etc.), the socket simply
never connects — the poll fallback (`GET /orders/{id}` every few seconds)
still delivers completion on its own.

## Build

```bash
npm run build
```
