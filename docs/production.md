# Production deploy: zoortech.tj

Dev stays on `docker-compose.yml`. Production uses only:

- `docker-compose.prod.yml`
- `.env.production`
- `docker/prod/Caddyfile`
- `deploy/first-run.sh`
- `deploy/update.sh`

## DNS

Point these A records to the server IP:

- `zoortech.tj`
- `www.zoortech.tj`
- `crm.zoortech.tj`
- `chatwoot.zoortech.tj`
- `dify.zoortech.tj`

Caddy will create HTTPS certificates automatically.

## First start

On the server:

```bash
sh deploy/first-run.sh
```

First run creates `.env.production` and `APP_KEY`, then stops. Open `.env.production` and fill:

```env
DB_PASSWORD=
DB_ROOT_PASSWORD=
CHATWOOT_ACCOUNT_ID=
CHATWOOT_API_TOKEN=
CHATWOOT_WEBHOOK_SECRET=
DIFY_API_KEY=
ACME_EMAIL=
```

Then start everything:

```bash
sh deploy/first-run.sh
```

## Dify and Chatwoot

Keep Dify and Chatwoot in their own official Docker projects. This CRM production stack exposes them through the same Caddy proxy.

Simple mode, if Chatwoot and Dify ports are published on the same server:

```env
CHATWOOT_UPSTREAM=host.docker.internal:3000
DIFY_UPSTREAM=host.docker.internal:8080
```

Shared Docker network mode:

```bash
docker network connect zoortech chatwoot
docker network connect zoortech dify-nginx
```

Then use container names:

```env
CHATWOOT_UPSTREAM=chatwoot:3000
DIFY_UPSTREAM=dify-nginx:80
```

Public URLs for CRM settings:

```env
CHATWOOT_URL=https://chatwoot.zoortech.tj
DIFY_API_URL=https://dify.zoortech.tj/v1
```

## Update

```bash
sh deploy/update.sh
```

## Checks

```bash
docker compose --env-file .env.production -f docker-compose.prod.yml ps
docker compose --env-file .env.production -f docker-compose.prod.yml exec crm php artisan migrate:status
curl -I https://zoortech.tj
curl -I https://chatwoot.zoortech.tj
curl -I https://dify.zoortech.tj
```