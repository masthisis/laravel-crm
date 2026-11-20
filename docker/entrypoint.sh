#!/bin/sh
set -e

ROLE="${CONTAINER_ROLE:-app}"

# ---------------------------------------------------------------
# 1. Wait for the database
# ---------------------------------------------------------------
if [ "${WAIT_FOR_DB:-false}" = "true" ]; then
    echo "Waiting for database (${DB_CONNECTION:-pgsql})..."
    attempts=0
    until php artisan db:monitor --connection="${DB_CONNECTION:-pgsql}" >/dev/null 2>&1; do
        attempts=$((attempts + 1))
        if [ "$attempts" -ge 30 ]; then
            echo "Database not reachable after 30 attempts. Aborting." >&2
            exit 1
        fi
        sleep 2
    done
    echo "Database is up."
fi

# ---------------------------------------------------------------
# 2. Development conveniences (bind-mounted code, volume vendor)
# ---------------------------------------------------------------
if [ "${APP_ENV}" = "local" ]; then
    if [ ! -f vendor/autoload.php ]; then
        echo "Installing dependencies (first run)..."
        composer install --no-interaction --no-progress --prefer-dist || \
        composer update --no-interaction --no-progress --prefer-dist
        chown -R www-data:www-data vendor storage bootstrap/cache || true
    fi
    if [ -z "${APP_KEY}" ]; then
        php artisan key:generate --force || true
    fi
fi

# ---------------------------------------------------------------
# 3. Migrations (opt-in per environment)
# ---------------------------------------------------------------
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force
fi

# ---------------------------------------------------------------
# 4. Storage link & production caches
# ---------------------------------------------------------------
php artisan storage:link >/dev/null 2>&1 || true

if [ "${APP_ENV}" != "local" ] && [ "$ROLE" = "app" ]; then
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan filament:optimize >/dev/null 2>&1 || true
fi

# ---------------------------------------------------------------
# 5. Role dispatch (single image for web / worker / scheduler)
# ---------------------------------------------------------------
case "$ROLE" in
    queue)
        exec php artisan queue:work --sleep=3 --tries=3 --max-time=3600
        ;;
    scheduler)
        exec php artisan schedule:work
        ;;
    *)
        exec "$@"
        ;;
esac
