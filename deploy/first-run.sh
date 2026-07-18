#!/usr/bin/env sh
set -eu

cd "$(dirname "$0")/.."

if [ ! -f .env.production ]; then
    cp .env.production.example .env.production
    key="base64:$(docker run --rm php:8.4-cli php -r 'echo base64_encode(random_bytes(32));')"
    sed -i "s|^APP_KEY=.*|APP_KEY=$key|" .env.production
    echo "Created .env.production. Fill DB passwords, Chatwoot token, Dify key, then rerun this script."
    exit 0
fi

docker compose --env-file .env.production -f docker-compose.prod.yml up -d --build

docker compose --env-file .env.production -f docker-compose.prod.yml ps