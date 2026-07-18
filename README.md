# Gravity AI CRM

Omnichannel CRM MVP for Chatwoot, Dify, operators, leads, customers, tasks and AI drafts.

## Local Start

```bash
composer install
npm install
copy .env.example .env
php artisan key:generate
php artisan migrate --seed
npm run build
php artisan serve --host=127.0.0.1 --port=8000
npm run dev
```

Open CRM:

```text
http://localhost:8000
```

Demo tenant header in API/UI:

```text
X-Tenant-Id: demo
```

## External Services

Chatwoot local URL:

```text
http://127.0.0.1:3000
```

Dify local URL:

```text
http://127.0.0.1:8080
```

Dify API URL for CRM settings:

```text
http://127.0.0.1:8080/v1
```

Set secrets either in `.env` or in CRM `Settings`:

```env
CHATWOOT_URL=http://127.0.0.1:3000
CHATWOOT_ACCOUNT_ID=2
CHATWOOT_API_TOKEN=
CHATWOOT_WEBHOOK_SECRET=
DIFY_API_URL=http://127.0.0.1:8080/v1
DIFY_API_KEY=
DIFY_TIMEOUT=12
```

## CRM Demo Flow

1. Open Chatwoot and send a website-widget message.
2. Open CRM `Inbox` and click `Sync Chatwoot` if needed.
3. Open the linked conversation.
4. Go to `AI draft` and click `Generate draft`.
5. Review the draft and click `Send to Chatwoot`.
6. Check `Settings -> Operator demo flow` for progress.
7. Check `CRM` to confirm customer, lead and chat links.

## Quality Checks

```bash
npm run build
php artisan test
php artisan optimize:clear
```

Current verified status:

```text
47 tests, 237 assertions
```

## Project Notes

- Vue UI is split into small components.
- Pinia stores dashboard state and navigation state.
- Chatwoot replies are operator-controlled.
- Dify drafts are saved inside CRM before sending.
- `tasks/implementation_log.md` contains the implementation history.