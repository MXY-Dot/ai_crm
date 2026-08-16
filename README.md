# WERO — AI CRM

Current app version: **1.54.1**

Multi-tenant, AI-assisted omnichannel CRM. Operators handle conversations from Telegram and a customer's own website (via an embeddable chat widget) in one inbox, with an AI assistant that can draft or auto-send replies, and a Super Admin panel for managing tenants, billing, and LLM providers across the whole platform.

## Tech Stack

| Layer | Stack |
|---|---|
| Backend | PHP 8.3+, Laravel ^13.8 |
| Frontend | Vue 3.5, Inertia.js 3, Pinia 3, TypeScript |
| UI | Tailwind CSS 4, shadcn-vue (reka-ui), lucide icons |
| Realtime | Laravel Reverb (WebSockets) + laravel-echo/pusher-js |
| Auth | Laravel Sanctum, 2FA (TOTP + recovery codes via `vue-input-otp`) |
| Queue / Cache | Database driver (queue workers `ai-crm-queue@1`/`@2`) |
| AI | Direct LLM (OpenAI, Anthropic, Google Gemini, DeepSeek — per-tenant BYOK) + Dify workflow engine, with automatic fallback when neither is configured |

## Core Features

**Omnichannel inbox**
- Telegram bot channel (text, photos, documents, voice, video)
- Embeddable website chat widget (`public/widget.js`) — customizable bubble color/position/launcher icon, dynamic header that shows the real operator's name+avatar while they're viewing the conversation, or an AI status otherwise
- Legacy Chatwoot channel integration

**Real-time**
- Instant message delivery over Reverb WebSockets (no polling)
- Typing indicator and "operator is viewing" presence, used to decide whether AI should auto-reply
- Live unread-message badges

**AI**
- Auto-reply and AI drafts, per-tenant plan-gated provider access
- Message-burst debounce so rapid follow-up messages don't trigger duplicate AI replies
- AI stays silent while an operator has the conversation open
- Voice message transcription pipeline (Groq Whisper)
- Knowledge Base with PDF/DOCX viewing for grounding AI answers

**CRM core**
- Customers, leads, tasks, conversations, notifications
- Conversation pinning (personal) separate from assignment (shared/team-visible)

**Multi-tenant SaaS**
- Per-tenant settings, branding, and channel configuration
- Plans/billing, audit log

**Super Admin platform panel**
- Overview, analytics, companies, users, billing, support tickets
- LLM provider management

**Security**
- Two-factor authentication (TOTP setup/confirm + login challenge) using segmented OTP input boxes, and 8-character recovery codes with a letters-and-digits pattern

## Local Setup

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

Open the CRM at `http://localhost:8000`.

Demo tenant header in API/UI:

```text
X-Tenant-Id: demo
```

Realtime requires Reverb running locally as well:

```bash
php artisan reverb:start
```

## External Services

Set credentials either in `.env` or per-tenant in CRM `Settings → Integrations`:

```env
CHATWOOT_URL=
CHATWOOT_ACCOUNT_ID=
CHATWOOT_API_TOKEN=
CHATWOOT_WEBHOOK_SECRET=

DIFY_API_URL=
DIFY_API_KEY=
DIFY_TIMEOUT=12

TELEGRAM_BOT_TOKEN=

REVERB_APP_ID=
REVERB_APP_KEY=
REVERB_APP_SECRET=
```

Direct LLM provider keys (OpenAI/Anthropic/Google/DeepSeek/Groq) are entered per tenant from the CRM UI rather than `.env`, except where a platform-level fallback key is configured for Super Admin-managed providers.

## Quality Checks

```bash
npm run build
php artisan test
php artisan optimize:clear
```

## Deploying a Change

1. `npm run build`
2. Bump `APP_VERSION` in `.env`
3. `php artisan config:clear`
4. `systemctl restart php8.5-fpm`
5. Restart queue workers (`ai-crm-queue@1`, `ai-crm-queue@2`) if job-handling code changed
6. Restart Reverb if broadcasting/channel config changed

## Project Notes

- Vue UI is split into small, focused components; Pinia stores dashboard/chat state.
- All env-derived config is read through Laravel's `config()` helper, never `env()` directly outside `config/*.php`.
- `tasks/implementation_log.md` contains historical implementation notes from earlier milestones.
