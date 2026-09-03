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
| `make -C docker artisan c="queue:work"` | a queue worker in the foreground |
| `make -C docker test` | the test suite (`c="test --filter=Import"` for a subset) |
| `make -C docker artisan c="..."` | any artisan command |

Tests run against MySQL rather than SQLite, so they exercise the same engine as the
application — the search and booking queries depend on window functions,
`SELECT ... FOR UPDATE` and unique index semantics. `phpunit.xml` points at `wtg_test`,
which MySQL creates on its first boot.

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
