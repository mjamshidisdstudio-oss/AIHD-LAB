# AIHD-LAB

Backend scaffold for **aihd-lab**, an experimental AI-services platform. This
phase delivers the data layer only: the schema, domain enums, Eloquent models,
factories and a seeded built-in service. There is no HTTP/UI surface yet beyond
the framework defaults.

## Stack

| Concern        | Choice                                             |
| -------------- | -------------------------------------------------- |
| Framework      | Laravel 11 (PHP 8.2+)                               |
| Database       | MySQL 8 (UUID primary keys everywhere)             |
| Cache / queue / session | Redis (`phpredis`)                        |
| Queue dashboard | Laravel Horizon                                   |
| API auth       | Laravel Sanctum                                    |
| Realtime       | Laravel Echo + Pusher (broadcasting driver)        |
| Object storage | S3-compatible `media` disk                         |

## Data model

Nineteen domain tables across three areas (all with UUID keys):

- **Catalog** — `services`, `service_versions`, `service_inputs`,
  `service_input_options`, `option_dependencies`, `service_outputs`,
  `service_waiting_texts`
- **Execution** — `orders`, `order_inputs`, `order_input_options`,
  `order_input_files`, `requests`, `results`, `files`
- **Event ledgers** — `interactions`, `webhook_deliveries`, `service_votes`,
  `service_comments`, `bookmarks`

Notable schema features:

- `service_versions` carries a `UNIQUE(id, service_id)` key so `orders` can hold
  a **composite foreign key** `(service_version_id, service_id)` — a chosen
  version is guaranteed to belong to the chosen service.
- `services.current_version_id` → `service_versions.id` is added in a follow-up
  migration to resolve the circular dependency between the two tables.
- `order_inputs` has a **MySQL 8 stored generated column** `value_fill_count`
  plus a `CHECK (value_fill_count <= 1)` constraint, so a scalar answer can never
  hold both a text and a boolean value at once.
- Typed columns are backed by PHP enums (`app/Enums`) and MySQL `ENUM` columns.
- `service_secret` is stored hashed (via the `hashed` cast).

## Seeded data

`SeasonalViewsSeeder` creates the built-in **Seasonal Views** service
(`slug: season-gen`) with one published version:

- Inputs: `room_photo` (image, required), `room_type` (select:
  bedroom/living/kitchen), `style` (select whose options are gated on
  `room_type` via `option_dependencies`), `hd` (boolean)
- 4 image outputs, 3 waiting texts
- `coin_cost 2`, `regenerate_limit 3`, `response_timeout_s 120`,
  `get_interval_s 30`, `max_get_attempts 10`

## Getting started

```bash
composer install
cp .env.example .env
php artisan key:generate

# configure DB_* / REDIS_* / PUSHER_* / MEDIA_* in .env, then:
php artisan migrate:fresh --seed
```

Run the queue dashboard with `php artisan horizon`.

## Tests

Tests run against a dedicated MySQL 8 database (`aihd_lab_test`) because the
schema relies on MySQL-only features. Create it once, then:

```bash
php artisan test
```

Coverage includes the schema invariants (composite FK, the generated-column
CHECK, uniqueness, UUID keys, hashed secret), full eager-loadability of every
model relationship, and the seeder output.
