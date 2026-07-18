#!/usr/bin/env sh
set -eu

cd "$(dirname "$0")/.."

docker compose --env-file .env.production -f docker-compose.prod.yml up -d --build