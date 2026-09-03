#!/bin/sh
set -e

APP_DIR=/var/www/html
cd "$APP_DIR"

log() { echo "[entrypoint] $*"; }

wait_for() {
    host="$1"; port="$2"; label="$3"; tries="${4:-60}"
    log "waiting for ${label} at ${host}:${port} ..."
    i=0
    while [ "$i" -lt "$tries" ]; do
        if php -r '$s=@fsockopen($argv[1],(int)$argv[2],$e,$m,1); if($s){fclose($s); exit(0);} exit(1);' "$host" "$port"; then
            log "${label} is ready"
            return 0
        fi
        i=$((i + 1))
        sleep 1
    done
    log "ERROR: ${label} did not become available in ${tries}s"
    return 1
}

# Only the setup phase runs as root; php-fpm drops privileges to www-data itself.
if [ "$(id -u)" = "0" ]; then
    RUN_AS="www-data"
else
    RUN_AS=""
fi

as_app() {
    if [ -n "$RUN_AS" ]; then
        su -s /bin/sh -c "$*" "$RUN_AS"
    else
        sh -c "$*"
    fi
}

if [ ! -f .env ] && [ -f .env.example ]; then
    log "no .env found, seeding it from .env.example"
    cp .env.example .env
fi

if [ "$(id -u)" = "0" ]; then
    mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
    chown -R www-data:www-data storage bootstrap/cache
    [ -f .env ] && chown www-data:www-data .env
fi

if [ ! -f vendor/autoload.php ]; then
    log "vendor/ is missing, installing composer dependencies"
    as_app "composer install --no-interaction --prefer-dist --no-progress"
fi

if [ -f .env ] && grep -qE '^APP_KEY=[[:space:]]*$' .env; then
    log "APP_KEY is empty, generating one"
    as_app "php artisan key:generate --force --no-interaction"
fi

[ -n "${DB_HOST}" ] && wait_for "${DB_HOST}" "${DB_PORT:-3306}" "MySQL"
[ -n "${REDIS_HOST}" ] && wait_for "${REDIS_HOST}" "${REDIS_PORT:-6379}" "Redis"

if [ "${RUN_MIGRATIONS}" = "true" ]; then
    log "running database migrations"
    as_app "php artisan migrate --force --no-interaction"
fi

if [ "${OPTIMIZE_ON_BOOT}" = "true" ]; then
    log "caching config, routes and views"
    as_app "php artisan optimize"
else
    as_app "php artisan optimize:clear" >/dev/null 2>&1 || true
fi

log "starting: $*"
exec "$@"
