#!/usr/bin/env sh
set -e

if [ -n "${CRM_ENV_FILE:-}" ] && [ ! -f .env ]; then
    cp "$CRM_ENV_FILE" .env
fi

if [ "${CREATE_ENV_FILE:-false}" = "true" ] && [ ! -f .env ] && [ -f .env.example ]; then
    cp .env.example .env
fi

mkdir -p storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

php artisan storage:link >/dev/null 2>&1 || true

if [ "${WAIT_FOR_DB:-false}" = "true" ] && [ "${DB_CONNECTION:-}" = "pgsql" ]; then
    until pg_isready -h "${DB_HOST:-postgres}" -p "${DB_PORT:-5432}" -U "${DB_USERNAME:-ai_crm}" >/dev/null 2>&1; do
        echo "Waiting for PostgreSQL at ${DB_HOST:-postgres}:${DB_PORT:-5432}..."
        sleep 2
    done
fi

if [ -z "${APP_KEY:-}" ] && [ -f .env ] && ! grep -q '^APP_KEY=base64:' .env; then
    php artisan key:generate --force --no-interaction
fi

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force
fi

if [ "${RUN_SEEDERS:-false}" = "true" ]; then
    php artisan db:seed --force
fi

if [ "${CACHE_CONFIG:-false}" = "true" ]; then
    php artisan optimize
fi

exec "$@"
