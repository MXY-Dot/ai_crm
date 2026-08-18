# WERO — AI CRM

Current app version: **1.75.1**

Multi-tenant, AI-assisted omnichannel CRM. Operators and an AI assistant share one inbox across Telegram, an embeddable website chat widget, and Chatwoot-mediated WhatsApp/Instagram/Facebook, with a Super Admin panel for managing tenants, billing, and LLM providers across the whole platform.

## Tech Stack

| Layer | Stack |
|---|---|
| Backend | PHP 8.5, Laravel ^13.8 |
| Frontend | Vue 3.5, Inertia.js 3, Pinia 3, TypeScript |
| UI | Tailwind CSS 4, shadcn-vue (reka-ui), lucide icons |
| Database | PostgreSQL 18 with pgvector (semantic search) |
| Realtime | Laravel Reverb (WebSockets) + laravel-echo/pusher-js |
| Auth | Laravel Sanctum, 2FA (TOTP + recovery codes) |
| Queue / Cache | Database driver (queue workers `ai-crm-queue@1`/`@2`) |
| AI | Direct LLM (OpenAI, Anthropic, Google Gemini, DeepSeek, Groq — per-tenant BYOK) + Dify workflow engine, with automatic provider failover and a local keyword-based fallback when no LLM is reachable |

## Core Features

**Omnichannel inbox**
- Telegram bot channel (text, photos, documents, voice with real transcription, video) — direct integration, no Chatwoot in the path
- Embeddable website chat widget (`public/widget.js`) — customizable branding, dynamic header showing the real operator or an AI status
- WhatsApp, Instagram, and Facebook via Chatwoot mediation (WERO doesn't call Meta's Graph API directly — see Known Gaps)
- Real-time per-channel health monitoring (Telegram) with automatic incident alerts

**Real-time**
- Instant message delivery over Reverb WebSockets (no polling)
- Typing indicator and "operator is viewing" presence, used to decide whether AI should auto-reply
- Live unread-message badges

**AI orchestration**
- Real semantic Knowledge Base search (pgvector embeddings, not fixed-chunk context-stuffing), with PDF/DOCX/XLSX parsing and URL training
- Cross-conversation customer memory, configurable persona/goal per AI agent, Tajik/Russian/English language detection
- Structured business rules (max discount %, forbidden topics) with real code-level enforcement, not just prompt text
- Prompt-injection detection, sentiment detection, VIP-aware priority handling
- Circuit breaker + tenant-level emergency failover when a provider goes down, with a Super Admin incident dashboard
- Message-burst debounce, "AI stays silent while an operator is actively viewing"
- Voice message transcription (Groq Whisper)

**CRM core**
- Customers (with VIP scoring/segmentation, city/language, feedback history), leads, tasks, conversations
- Freeform conversation labels (AI-suggested + manual) with filtering
- Conversation pinning (personal) separate from assignment (shared/team-visible), reply/edit/delete on messages
- Customer 360° profile unifying VIP data, AI summary, and feedback in one page
- Post-service follow-up and abandoned-conversation nudges (task-only — see Known Gaps on outbound messaging)
- Marketing campaigns: AI-drafted offer text + audience segmentation, sent manually by a human operator

**Multi-tenant SaaS**
- Per-tenant settings, branding, and channel configuration
- Plans/billing, audit log (extended across all major resource controllers + Super Admin's sensitive actions)
- Nightly database backups (`db:backup`, pg_dump custom format, 14-day retention) — see Ops below

**Super Admin platform panel**
- Overview, analytics (AI/LLM/Sales dashboards, per-call cost/token tracking), companies, users, billing, support tickets
- LLM provider management, platform-wide key configuration

**Security**
- Two-factor authentication (TOTP + recovery codes)
- Tenant isolation via a shared `BelongsToTenant` scope, audited clean

## Known Gaps

Honest, not aspirational — these are structural, not bugs:

- **No Product/Order/Cart schema anywhere.** AI can chat, qualify, and quote from the Knowledge Base, but cannot look up real stock/pricing or complete an actual sale. This blocks several spec-level features: tool-calling, sale lifecycle completion, resale-opportunity detection, repeat-purchase tracking.
- **No live Chatwoot instance is deployed anywhere in this environment.** All Chatwoot integration code (webhooks, API client, provider mapping) is written to Chatwoot's documented behavior but has never been exercised against real Chatwoot traffic.
- **Instagram/Facebook comment moderation (public comments, not DMs) was not built.** No ingestion, no classifier, no moderation actions — needs a live Chatwoot instance to even confirm the webhook shape, plus likely direct Meta permissions beyond what Chatwoot proxies.
- **Direct Meta integration** (WhatsApp Cloud API, Instagram/Facebook Graph API) is out of scope — needs the tenant's own Meta developer account and business verification, not buildable from code alone.
- **No autonomous outbound messaging on WhatsApp/Telegram.** Follow-ups, post-service check-ins, and campaigns all stop at creating a task or a draft for a human to send — a deliberate compliance decision, since no consent-tracking exists anywhere in the schema.
- **No "AI Supervisor" / confidence-tiered routing layer** — the spec names one, it was never built. `forbidden_topics` is enforced via prompt only (not code-checked) for the same reason.
- **PII/GDPR-style data handling** (redaction before sending to external AI providers, data export, right-to-delete) has not been built — needs a real legal decision on target-market jurisdiction first.

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

## Ops

- **Scheduler**: `ai-crm-scheduler.timer` (systemd) runs `php artisan schedule:run` every minute; scheduled jobs themselves are registered in `routes/console.php` (health probes, VIP recalculation, follow-ups, nightly backup).
- **Database backups**: `php artisan db:backup` dumps the whole database via `pg_dump --format=custom` to `storage/app/private/backups/`, prunes anything older than 14 days, runs nightly at 22:30 UTC. Restore with `pg_restore`, not `psql`.
- **Emergency/failover**: `App\Support\Emergency\*` — circuit breaker on LLM providers, tenant-level emergency mode with per-language fallback messages, Super Admin incident dashboard.

## Project Notes

- Vue UI is split into small, focused components; Pinia stores dashboard/chat state.
- All env-derived config is read through Laravel's `config()` helper, never `env()` directly outside `config/*.php`.
