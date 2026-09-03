# wtg — housing offers API

REST API that imports housing offers from suppliers asynchronously, exposes the cheapest
current offer per property, and books an offer safely under concurrency.

Repository: <https://github.com/kirill2012/wtg>

PHP 8.5 · Laravel 12 · MySQL 8.4 · Redis 7 (queue, cache) · nginx · Docker. API-only.

## Installation

```bash
git clone https://github.com/kirill2012/wtg.git && cd wtg
cp .env.example .env
cp docker/.env.example docker/.env    # then set UID/GID to your `id -u` / `id -g`
docker compose -f docker/docker-compose.yml up -d --build
make -C docker artisan c="db:seed"    # the two suppliers: supplier-a, supplier-b
```

Two env files on purpose: the root `.env` is the application's, `docker/.env` feeds the
`${...}` substitutions in `docker-compose.yml` — Compose loads it from the compose file's
directory, not from the project root. Keep `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD`
identical in both. Without `docker/.env` every value falls back to the default baked into
`docker-compose.yml`, but the containers write into the bind mount as uid 1000.

On first boot the `app` container generates `APP_KEY`, waits for MySQL and Redis and runs
the migrations; the `queue` container starts a worker. nginx answers on
<http://localhost> (`APP_URL` is `http://wtg.loc` — add it to `/etc/hosts` to use that
name); `/up` is the health check.

## Commands

`docker/Makefile` runs everything inside the `app` container, from any directory.

| Command | What it does |
| --- | --- |
| `make -C docker up` / `down` | start / stop the stack (`down v=1` drops the volumes too) |
| `make -C docker migrate` | `php artisan migrate` |
| `make -C docker artisan c="db:seed"` | seed the suppliers |
| `make -C docker fresh` | `php artisan migrate:fresh --seed` |
| `make -C docker queue` | restart the queue worker — it runs the code loaded at start, so restart it after changing job or service code |
| `make -C docker artisan c="queue:work"` | an extra worker in the foreground |
| `make -C docker test` | the test suite (`c="test --filter=Import"` for a subset) |
| `make -C docker artisan c="..."` | any artisan command |
| `vendor/bin/pint` | code style; runs on the host, needs no database |

Tests run against MySQL rather than SQLite, so they exercise the same engine as the
application — the search and booking queries depend on window functions,
`SELECT ... FOR UPDATE` and unique index semantics. `phpunit.xml` points at `wtg_test`,
which MySQL creates on its first boot. Two test classes open a second database connection
to reproduce real races; they run in the same suite.

## Without Docker

Requires PHP 8.5+ with `pdo_mysql` and `redis`, Composer, MySQL 8 and Redis. Create the
`wtg` and `wtg_test` databases and point `.env` at them, then:

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate && php artisan db:seed
php artisan serve
php artisan queue:work    # in a second terminal
php artisan test
```

## API

Requests and responses are JSON. Validation errors come back as `422` with Laravel's
standard `{"message": ..., "errors": {...}}`, a missing record or route as `404
{"message": "Not Found."}`, a state conflict as `409 {"message": "..."}`. Moments are
serialised as `2026-09-01T10:00:00Z` (UTC, no microseconds), calendar dates as
`2026-10-10`. Prices are integers in minor units: `72500` is 725.00.

### `POST /api/imports` — accept an import

Body: `supplier` (slug), `external_import_id`, `sent_at`, `offers` (a list of 1 to 1000
items, each with `external_id`, `property {code, name, City}`, `check_in`, `check_out`,
`max_guests`, `price`, `currency`, `available_units`, `expires_at`). The request validates
the structure and the supplier, stores the import together with its payload, queues
`ProcessImportJob` and answers `202` with `{"data": {"id": 15, "status": "pending"}}` and a
`Location` header pointing at the status endpoint.

`supplier + external_import_id` identifies an import. Resending it returns the existing row
with its *current* status (`completed` a minute later, not `pending`) and queues nothing,
even when the payload differs.

### `GET /api/imports/{id}` — import status

`id`, `supplier`, `external_import_id`, `sent_at`, `status`, `total_offers`,
`processed_offers`, `error`, `created_at`, `completed_at`. Statuses: `pending` →
`processing` → `completed` or `failed`. `processed_offers` describes the current attempt;
`error` and `completed_at` are filled on `failed` as well.

### `GET /api/properties` — search

Query: `check_in` and `check_out` (required, `Y-m-d`, check-out after check-in), `guests`
(default 1), `city` (optional), `per_page` (default 15, max 100), `page`.

Returns the properties that have at least one live offer for exactly those dates, each
with its cheapest live offer as `best_offer`, cheapest first. An offer is live when its
dates equal the requested ones, `max_guests >= guests`, it has units left beyond what is
reserved, and `expires_at > now()`. The standard paginator envelope carries `links.next`,
`links.prev` and `meta.per_page`; the links keep the search parameters, so they can be
followed as they are.

### `POST /api/offers/{id}/reservations` — book

Body: `client_reference`, `customer_name`, `customer_email`. Books one unit and answers
`201` with the reservation: `id`, `offer_id`, `client_reference`, `customer_name`,
`customer_email`, `price`, `currency`, `created_at`, where `price` and `currency` are a
snapshot of the offer at booking time. Resending the same `client_reference` for the same
offer answers `200` with the reservation made the first time, without taking another
unit. `409` when the offer has expired, is sold out, or the reference already belongs to a
reservation of another offer.

## Data model

Imports and offers belong to a supplier; an offer belongs to a property and to the import
that last wrote it; a reservation belongs to an offer.

- `imports` — `supplier_id` + `external_import_id` (unique together), `sent_at`, `status`,
  `payload` (JSON, the offers as validated), `total_offers`, `processed_offers`, `error`,
  `completed_at`.
- `properties` — `code` (unique), `name`, `city` (indexed).
- `offers` — `supplier_id` + `external_id` (unique together), `property_id`, `import_id`
  and `sent_at` (which import last wrote the row and when the supplier produced it),
  `check_in`, `check_out`, `max_guests`, `price`, `currency`, `available_units`,
  `reserved_units`, `expires_at`.
- `reservations` — `offer_id`, `client_reference` (unique), the customer fields, `price`,
  `currency`.

Availability is split in two columns on purpose. `available_units` belongs to the
supplier and is written by imports only; `reserved_units` belongs to the application and
is written by bookings only. An offer is bookable while `available_units >
reserved_units`; the API publishes the difference under the key `available_units`, clamped
at zero, never the raw column.

One composite index serves the search, `offers (check_in, check_out, property_id, price)`:
an equality lookup on the dates when the search starts from them, and a three-column
lookup when a `city` filter makes the optimizer start from `properties (city)`. A mirrored
`(property_id, check_in, check_out, price)` index was tried and dropped, `EXPLAIN` never
chose it over this one. MySQL sorts for the window function regardless of index order, but
only the rows that match the dates.

## Import processing

The HTTP request validates, stores and queues; nothing else. `ProcessImportJob` reads the
offers from `imports.payload` and applies them one by one, each in its own transaction:

1. the property is found or created by `code` — outside the offer's transaction, because
   under `REPEATABLE READ` a transaction's snapshot would hide a property another worker
   has just committed; an existing property is never updated;
2. a plain lookup by `supplier + external_id`; a new offer is inserted, an existing one is
   re-read with `SELECT ... FOR UPDATE`;
3. if the row was last written by an import with a later `sent_at`, it is left alone: a
   stale import must not overwrite fresher data. Otherwise every supplier-owned column
   plus `import_id` and `sent_at` are updated; `reserved_units` is never touched.

`processed_offers` grows after each offer; on success the import becomes `completed`. The
job is unique per import (`ShouldBeUnique`, so a manual re-dispatch or `queue:retry`
cannot run one import twice at once), retries three times with backoffs of 10 s and 60 s,
and marks the import `failed` with the error text once the attempts are exhausted; a
timed-out attempt counts as one, and between attempts the status stays `processing`.
Because every offer is its own transaction, a failure part-way leaves the offers already
written; a re-run is idempotent and catches up the rest.

Budget, measured on the local Docker stack (the worker is CLI without opcache; MySQL
flushes the log on every commit): an import of 1000 offers on 250 properties takes about
22 s, a re-run without changes about 15 s. The time is dominated by the commit per offer,
the price of the transaction-per-offer choice above. The 1000-offer cap on the payload,
the worker's `--timeout=60`, Redis `retry_after=90` and the job's `$uniqueFor=3600` are
related knobs: raise them together, after measuring on the target machine.

## Search query

Cheapest-per-property, ordering and pagination all happen in SQL; nothing is grouped in
PHP. A ranking subquery numbers each property's live offers with `ROW_NUMBER() OVER
(PARTITION BY property_id ORDER BY price, id)`; the outer query joins `offers` to rank 1,
orders by `price, property_id` (the tiebreaker keeps pages from overlapping on equal
prices) and paginates. Rank 1 is one offer per property, so a page of offers is a page of
properties, and the rows are real `Offer` models with `property` and `supplier`
eager-loaded: four queries per request whatever the page size. The paginator runs the
ranking subquery twice (count and page) and MySQL materialises it each time; acceptable at
this scale.

## Booking the last unit

Two simultaneous bookings of the last unit are settled by **one mechanism: the row lock on
the offer**. `ReservationService::reserve()` runs in a transaction that opens with
`SELECT ... FOR UPDATE` on the offer row and holds the lock until commit. The second
request waits on it, then reads `reserved_units` already incremented by the first and gets
`409 The offer is sold out.` Nothing else decides this. The unique key on
`client_reference` is about *idempotency* of a resent request, not a second line of
defence: two concurrent requests for the last unit carry different references and both
pass it.

The steps inside the transaction:

1. lock the offer row;
2. look up an existing reservation by `client_reference`: found for the same offer, return
   it (`200`); for another offer, `409`;
3. `409` if the offer has expired or `available_units - reserved_units < 1`;
4. insert the reservation;
5. increment `reserved_units`.

Two details are correctness, not style. The lock comes before the lookup because under
`REPEATABLE READ` the transaction's snapshot is fixed by its first plain read: taken after
the lock, it includes what a competing request for the same offer committed while we
waited, so a resent request that lost that race finds the winner's reservation instead of
a spurious "sold out". The insert comes before the increment because MySQL rolls back
only the failed statement on a duplicate key: when the same reference lands from a
concurrent request for another offer, the conflict is caught, the row is re-read with a
locking read (a plain one would look into the stale snapshot, which is why Laravel's
`createOrFirst` is not used here) and answered with `409`, and no unit has been taken.

`ReservationConcurrencyTest` checks this on two real database connections instead of by
reasoning: one connection holds `FOR UPDATE` on the offer while the real service runs on
the other and must fail with MySQL error 1205 leaving nothing behind, and a reservation
committed by the other connection mid-flight must be found despite the snapshot.

## Assumptions

Decisions the task leaves open, and shortcuts taken on purpose, written down so they are
not mistaken for oversights.

- Prices are compared as raw minor units, so the cheapest offer is correct within one
  currency; currency conversion is out of scope.
- Search matches `check_in` and `check_out` exactly, as the task states; no overlap logic.
- One reservation is one unit; the request has no quantity field.
- A supplier may publish `available_units` below what is already reserved. The column is
  stored as sent, existing reservations stay, the published remainder is clamped at zero
  and the offer leaves the search.
- Order between imports is decided by `sent_at`, not by processing order: an import with
  an older `sent_at` processed later does not overwrite an offer; equal timestamps update.
- Resending an `external_import_id` with a different payload returns the existing import
  and ignores the new payload; a different body under the same id is a supplier error.
- Resending a `client_reference` with different customer data returns the original
  reservation; the reference identifies the request, not the customer fields.
- External ids, property codes, cities and client references are compared without regard
  to case or diacritics (`utf8mb4_unicode_ci`): `BCN-0001` and `bcn-0001` are one
  property, `Barcelona` and `barcelona` match. `distinct:ignore_case` rejects duplicates
  that differ only in case within one payload; a pair differing only in diacritics
  collapses in the job. Leading and trailing whitespace is trimmed.
- `sent_at` and `expires_at` are converted to UTC on write; a value without an offset is
  read as UTC (`APP_TIMEZONE=UTC`).
- `imports.payload` stores the validated subset of the request: values unchanged, unknown
  keys dropped. The data therefore lives twice, the price of knowing `total_offers` at once
  and of re-running an import without the supplier.
- `City` keeps its capital letter in the import body and in the search response, following
  the task's contract literally; the column is `city`.
- A property is not updated after creation: two suppliers describe one object differently,
  and last-writer-wins would make its name flicker between imports.
- State conflicts are raised as `abort(409)` from the service layer: one body shape with
  the default `404` and `422`, and no exception hierarchy for two cases. A deliberate
  shortcut, not an oversight.

## Known limitations

- **A failed queue push leaves the import `pending`.** The import row is committed before
  the job is pushed. If the push fails (Redis unreachable), the client gets a 500 and the row
  stays `pending` with nothing queued; a repeated `POST` returns that row without re-queuing,
  because `supplier + external_import_id` already exists. Recovery is a manual re-dispatch,
  which the job's `ShouldBeUnique` makes safe to do more than once:
  `php artisan tinker --execute 'App\Jobs\ProcessImportJob::dispatch(App\Models\Import::findOrFail(15));'`.
  Closing the gap properly needs an outbox, which is out of scope here. The same recovery
  applies to an import that ended up `failed`: resending it returns `202` with `failed` and
  does not retry, because a repeated import must never re-run processing.
- **Offset pagination over live data.** An offer that expires or sells out between two page
  requests shifts the rows after it by one; a cursor would fix that and is out of scope.
