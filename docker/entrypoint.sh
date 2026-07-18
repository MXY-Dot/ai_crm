#!/usr/bin/env sh
set -e

if [ -n "${CRM_ENV_FILE:-}" ] && [ ! -f .env ]; then
    cp "$CRM_ENV_FILE" .env
fi

if [ "${CREATE_ENV_FILE:-false}" = "true" ] && [ ! -f .env ] && [ -f .env.example ]; then
    cp .env.example .env
fi

mkdir -p storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

php artisan storage:link >/dev/null 2>&1 || true

if [ "${DB_CONNECTION:-}" = "sqlite" ] && [ -n "${DB_DATABASE:-}" ]; then
    mkdir -p "$(dirname "$DB_DATABASE")"
    touch "$DB_DATABASE"
fi

if [ "${WAIT_FOR_DB:-false}" = "true" ] && [ "${DB_CONNECTION:-}" = "mysql" ]; then
    until mysqladmin ping -h"${DB_HOST:-mysql}" -P"${DB_PORT:-3306}" -u"${DB_USERNAME:-root}" -p"${DB_PASSWORD:-}" --silent; do
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