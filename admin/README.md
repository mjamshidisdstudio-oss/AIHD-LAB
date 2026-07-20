# AIHD Lab Admin

The operator-facing Nuxt 3 client: service catalog CRUD, version lifecycle
(draft/duplicate/publish/retire), the input/dependency builder, order and
webhook-delivery logs, and community moderation. Talks to the Laravel API in
the parent repo — it does not run any backend of its own.

## Setup

```bash
npm install
```

## Development

The API must be reachable at the URL in `NUXT_PUBLIC_API_BASE` (defaults to
`http://localhost/api`). Run this app on a port outside Sanctum's default
`SANCTUM_STATEFUL_DOMAINS` list (it includes `localhost:3000`) — otherwise
plain bearer-token requests get treated as needing CSRF, which they don't.

```bash
NUXT_PUBLIC_API_BASE=http://localhost/api npm run dev -- --port 3200
```

Unlike the marketplace client, there is no core-identity bearer token to fall
back on: sign in at `/login` with a seeded admin account (locally,
`admin@aihd.lab` / `password` — see `database/seeders/AdminUserSeeder.php`).
Production: https://admin.revivoto.ai/login
The token comes from `POST /api/admin/login` (Sanctum personal access token)
and is stored in `localStorage`.

## Build

```bash
npm run build
```
