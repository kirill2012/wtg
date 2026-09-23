# wtg — housing offers API

REST API that imports housing offers from suppliers asynchronously, exposes the cheapest
current offer per property, and books an offer safely under concurrency.

Repository: <https://github.com/kirill2012/wtg>

PHP 8.5 · Laravel 12 · MySQL 8.4 (data, queue and cache) · nginx · Docker. API-only.

## Deviations from the task

Places where the API answers differently from the letter of the task, on purpose. The
details are in the sections linked.

| The task says | The API does | Why |
|---|---|---|
| `POST /api/imports` answers `202` | a resend of an import already recorded answers `200` with the existing row and its current status, and queues nothing | the import was accepted by the earlier request; a second `202` would claim a new one ([API](#post-apiimports--accept-an-import)) |
| `supplier + external_import_id` is unique | the same `external_import_id` with a different `sent_at` or different offers answers `409` | the supplier reused an id; silently keeping either version would lose data |
| an offer that exists in another import is updated | it is updated only when the new import's `sent_at` is not older than the one that last wrote it | a stale import processed late must not overwrite newer data ([Import processing](#import-processing)) |
| the response contains `next`, `prev`, `per_page` | they are in Laravel's paginator envelope: `links.next`, `links.prev`, `meta.per_page` | the standard shape of an API Resource collection, followed by any Laravel client |
| after an error the import is `failed` | it is `failed`, and the batches of 100 offers committed before the error stay | a batch is one transaction, short enough that bookings barely wait on its locks; a retry finishes the rest |
| `completed_at` | also set for a `failed` import, as the moment processing ended | one field for "finished", whatever the outcome |
| `POST /api/offers/{offer}/reservations` answers `201` | a resend of the same `client_reference` for the same offer answers `200` with the first reservation | the unit was booked by the earlier request |

## Installation

```bash
git clone https://github.com/kirill2012/wtg.git && cd wtg
cp .env.example .env
cp docker/.env.example docker/.env    # then set UID/GID to your `id -u` / `id -g`
docker compose -f docker/docker-compose.yml up -d --build --wait
make -C docker artisan c="db:seed"    # the two suppliers: supplier-a, supplier-b
```

The root `.env` is the application's; `docker/.env` feeds the `${...}` substitutions in
`docker-compose.yml`. Keep `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` identical in both.

`--wait` returns only once `app` is healthy, which happens after the migrations, so the seed
cannot outrun them.

On first boot the `app` container generates `APP_KEY`, waits for MySQL and runs
the migrations; the `queue` container starts a worker. nginx answers on
<http://localhost> (`APP_URL` is `http://wtg.loc` — add it to `/etc/hosts` to use that
name); `/up` is the health check.

## Commands

`docker/Makefile` drives the stack from any directory: the artisan and composer targets
run inside the `app` container, the rest talk to Compose.

| Command | What it does |
| --- | --- |
| `make -C docker up` / `down` | start / stop the stack (`down v=1` drops the volumes too) |
| `make -C docker migrate` | `php artisan migrate` |
| `make -C docker artisan c="db:seed"` | seed the suppliers |
| `make -C docker fresh` | `php artisan migrate:fresh --seed` |
| `make -C docker queue` | restart the queue worker — it runs the code loaded at start, so restart it after changing job or service code |
| `make -C docker artisan c="queue:work database"` | an extra worker in the foreground |
| `make -C docker test` | the test suite (`make -C docker artisan c="test --filter=Import"` for a subset) |
| `make -C docker artisan c="..."` | any artisan command |
| `vendor/bin/pint` | code style; runs on the host, needs no database |

Tests run against MySQL, not SQLite: the search and booking depend on window functions,
`SELECT ... FOR UPDATE` and unique index semantics. `phpunit.xml` points at `wtg_test`,
which MySQL creates on its first boot.

## Without Docker

Requires PHP 8.5+ with `pdo_mysql`, Composer and MySQL 8. Create the
`wtg` and `wtg_test` databases and point `.env` at them, then:

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate && php artisan db:seed
php artisan serve
php artisan queue:work database    # in a second terminal
php artisan test
```

## API

Requests and responses are JSON. Validation errors come back as `422` with Laravel's
standard `{"message": ..., "errors": {...}}`, a missing record or route as `404
{"message": "Not Found."}`, a state conflict (a sold-out or expired offer, a reused import or booking reference) as
`409 {"message": "..."}`. Moments are
serialised as `2026-09-01T10:00:00Z` (UTC, no microseconds); calendar dates stay
`2026-10-10` in both directions. Prices are integers in minor units:
`72500` is 725.00.

`wtg.postman_collection.json` in the repository root walks the whole of it — import,
status, search, booking — and asserts every response against the contract described here.
Import it into Postman, or run it headless with `npx newman run
wtg.postman_collection.json`; it can be rerun as is, since its identifiers are derived from
the clock.

### `POST /api/imports` — accept an import

Body: `supplier` (slug), `external_import_id`, `sent_at`, `offers` (a list of 1 to 1000
items, each with `external_id`, `property {code, name, City}`, `check_in`, `check_out`,
`max_guests`, `price`, `currency`, `available_units`, `expires_at`). The request validates
the structure and the supplier, stores the import together with its payload, queues
`ProcessImportJob` and answers `202` with `{"data": {"id": 15, "status": "pending"}}` and a
`Location` header pointing at the status endpoint.

`supplier + external_import_id` identifies an import. Resending it with the same content
answers `200` with the existing row and its *current* status (`completed` a minute later,
not `pending`) and queues nothing. Resending it with a different `sent_at` or different
offers answers `409`: the supplier has reused an id, and neither version is silently
dropped.

The import row and its job are written by **one transaction**: `ProcessImportJob` is
pinned to the `database` queue on the application's own connection, whatever
`QUEUE_CONNECTION` says. No import is left without a job; on failure the client gets a
`500` and can safely resend.

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
`customer_email`, `property_code`, `check_in`, `check_out`, `price`, `currency`,
`created_at`, where everything from `property_code` on is a snapshot of the offer at
booking time and stays put when a later import changes it. Resending the same
`client_reference` for the same offer answers `200` with the reservation made the first
time, without taking another unit. `409` when the offer has expired, is sold out, or the
reference already belongs to a reservation of another offer.

## Data model

Imports and offers belong to a supplier; an offer belongs to a property and to the import
that last wrote it; a reservation belongs to an offer.

- `imports` — `supplier_id` + `external_import_id` (unique together), `sent_at`, `status`,
  `payload` (JSON, the offers as validated), `total_offers`, `processed_offers`, `error`,
  `completed_at`, `claimed_by` (the uuid of the job processing it).
- `properties` — `code` (unique), `name`, `city` (indexed).
- `offers` — `supplier_id` + `external_id` (unique together), `property_id`, `import_id`
  and `sent_at` (which import last wrote the row and when the supplier produced it),
  `check_in`, `check_out`, `max_guests`, `price`, `currency`, `available_units`,
  `reserved_units`, `expires_at`.
- `reservations` — `offer_id`, `client_reference` (unique), the customer fields, and the
  snapshot of what was booked: `property_id`, `check_in`, `check_out`, `price`, `currency`.

Availability is split in two columns: `available_units` is written by imports only,
`reserved_units` by bookings (an import only recounts it when it moves the offer, see
below). The API publishes their difference, clamped at zero, under the key
`available_units`.

One composite index serves the search, `offers (check_in, check_out, property_id, price)`:
the dates alone, or the dates plus `property_id` when a `city` filter makes the optimizer
start from `properties (city)`. A mirrored `(property_id, ...)` index was dropped:
`EXPLAIN` never chose it.

The index is not covering, on purpose. The dates narrow the scan, but `max_guests`,
`expires_at`, `available_units` and `reserved_units` are read from the row, and the ranking
subquery is materialised for the count and again for the page. Adding those four columns
would make the subquery index-only at the cost of a wider index to maintain on every
import write; at the volumes of this task the lookup is cheap, so the narrower index wins.

## Import processing

The HTTP request validates, stores and queues; nothing else. `ProcessImportJob` reads the
offers from `imports.payload`, sorts them by `external_id` and applies them in batches of
100 (`ImportService::OFFERS_PER_TRANSACTION`), one transaction per batch. The sort gives two
jobs writing the same offers one lock order, so they wait on each other instead of
deadlocking. For each offer:

1. the property is found or created by `code` — before the batch's transaction, because
   under `REPEATABLE READ` its snapshot would hide a property another worker has just
   committed;
2. a plain lookup by `supplier + external_id`; a new offer is inserted, an existing one is
   re-read with `SELECT ... FOR UPDATE`;
3. if the row was last written by an import with a later `sent_at`, it is left alone;
   on equal timestamps the import recorded later (the higher id) wins, whatever order the
   jobs run in. `reserved_units` is kept, unless the update moves the offer
   to another property or other dates: then it is recounted from the reservations booked
   for the new stay (usually zero), so units booked for October do not sell out November.

Before the first offer the job **claims** the import with one conditional `UPDATE`
that sets `status = 'processing'` and `claimed_by = <job uuid>`: allowed from `pending` or
`failed`, and from `processing` only for the job already holding it (its retries keep the
uuid). A second job for the same import finds it taken or `completed` and does nothing.
A `ShouldBeUnique` cache lock would sit outside the transaction that queues the job.

On success the import becomes `completed`. The job makes three attempts (backoff 10 s,
60 s), then marks the import `failed` with the error text. A failure part-way rolls back
its batch and leaves the batches already committed; a re-run is idempotent and catches up
the rest.

On the local Docker stack an import of 1000 new offers takes about 4 s, and a re-import
that updates them about 2.5 s; with a commit per offer it was 21 s and 34 s. The 1000-offer cap, the job's `$timeout = 60` and the queue's
`retry_after=90` are related: raise them together.

## Search query

Cheapest-per-property, ordering and pagination all happen in SQL; nothing is grouped in
PHP. A ranking subquery numbers each property's live offers with `ROW_NUMBER() OVER
(PARTITION BY property_id ORDER BY price, id)`; the outer query joins `offers` to rank 1,
orders by `price, property_id` (the tiebreaker keeps pages from overlapping) and
paginates. Four queries per request whatever the page size: count, page, and eager-loaded
`property` and `supplier`.

## Booking the last unit

Two simultaneous bookings of the last unit are settled by **one mechanism: the row lock on
the offer**. `ReservationService::reserve()` runs in a transaction that opens with
`SELECT ... FOR UPDATE` on the offer row and holds the lock until commit. The second
request waits on it, then reads `reserved_units` already incremented by the first and gets
`409 The offer is sold out.` The unique key on `client_reference` serves idempotency only:
two concurrent requests for the last unit carry different references.

The steps inside the transaction:

1. lock the offer row;
2. look up an existing reservation by `client_reference`: found for the same offer, return
   it (`200`); for another offer, `409`;
3. `409` if the offer has expired or `available_units - reserved_units < 1`;
4. insert the reservation, with the property, the stay, the price and the currency copied
   off the locked offer;
5. increment `reserved_units`.

The order matters. The lock comes before the lookup because under `REPEATABLE READ` the
snapshot is fixed by the first plain read, so a resent request that lost the race finds
the winner's reservation instead of a spurious "sold out". The insert comes before the
increment because MySQL rolls back only the failed statement on a duplicate key, so a
caught conflict takes no unit.

`ReservationConcurrencyTest` checks the lock on two real database connections.

## Assumptions

Decisions the task leaves open, and shortcuts taken on purpose, written down so they are
not mistaken for oversights.

- The task sets PHP 8.2+ as the lower bound, so the project targets the latest release,
  PHP 8.5: `composer.json` requires `^8.5`, and the Docker image runs it. Without Docker
  the host needs PHP 8.5 as well.
- Every supplier prices in one currency, EUR, as in the task's example. Search picks the
  cheapest offer and sorts the page by raw minor units, which is only meaningful within
  one currency, and conversion is out of scope. The import enforces it: an offer in any
  other currency (or a lower-case `eur`) is a `422`, not a silently mis-sorted result.
- Search matches `check_in` and `check_out` exactly, as the task states; no overlap logic.
- `available_units` is the quota the supplier gives this application for the offer, not
  its live stock: bookings made here are subtracted from it (`reserved_units`), and a
  supplier that already counted them in would have them subtracted twice.
- A supplier may publish `available_units` below what is already reserved. The column is
  stored as sent, existing reservations stay, the published remainder is clamped at zero
  and the offer leaves the search.
- Resending a `client_reference` with different customer data returns the original
  reservation; the reference identifies the request, not the customer fields.
- An import resent with the same id compares by content: the same `sent_at` moment (in
  any offset) and the same offers (in any key order) is a resend, anything else a `409`.
- There is no authentication: the task does not ask for it. In a real system the supplier
  would come from its API token rather than from the request body, and the booking
  endpoint would sit behind the client's own auth.
- A reservation snapshots the property, the stay, the price and the currency: a later
  import may change the offer, and the booking must not follow it.
- External ids, property codes, cities and client references are compared without regard
  to case or diacritics (`utf8mb4_unicode_ci`): `BCN-0001` and `bcn-0001` are one
  property, `Barcelona` and `barcelona` match. `distinct:ignore_case` rejects duplicates
  that differ only in case within one payload; a pair differing only in diacritics
  collapses in the job. Leading and trailing whitespace is trimmed.
- `sent_at` and `expires_at` must be ISO 8601 with whole seconds and an explicit offset
  (`2026-09-01T10:00:00Z` or `2026-09-01T12:00:00+02:00`) and are converted to UTC on
  write. Words like `now` would change on every resend and break resend detection, and
  the columns keep no fractions, so a fractional resend would never compare equal.
- `imports.payload` stores the validated request offers, so an import can be re-run
  without the supplier.
- `City` keeps its capital letter in the API, as in the task; the column is `city`.
- A property is not updated after creation: two suppliers describe one object differently,
  and last-writer-wins would make its name flicker between imports.
- State conflicts are raised as `abort(409)` from the service layer, with no exception
  hierarchy for two cases.

## Known limitations

- **A `failed` import is never retried by resending it.** Its job ran and exhausted its
  three attempts; a repeated `POST` returns the existing row and queues nothing, because a
  repeated import must never re-run processing on its own. Recovery is `queue:retry` for the
  job in `failed_jobs`, or a re-dispatch; the claim makes either safe to repeat:
  `php artisan tinker --execute 'App\Jobs\ProcessImportJob::dispatch(App\Models\Import::findOrFail(15));'`.
- **The queue shares the database.** Workers poll the `jobs` table (`SELECT ... FOR UPDATE
  SKIP LOCKED`), which adds load to MySQL. At a much higher rate the transport would move
  to Redis or SQS behind an outbox table.
- **Offset pagination over live data.** An offer that expires or sells out between two page
  requests shifts the rows after it by one; a cursor would fix that and is out of scope.
