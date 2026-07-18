# Gravity AI CRM - implementation log

Date: 2026-07-05
Scope: MVP 1 foundation from `tasks/full_doc.docx`

## Goal

Build the first clean Laravel foundation for a multi-tenant AI CRM without locking the project into heavy abstractions too early.

The implementation focuses on:

- readable folder structure;
- tenant isolation as a first-class rule;
- small classes with one clear job;
- reusable CRUD flow for simple CRM resources;
- API surface that can be extended later for Chatwoot, Dify and billing.

## Added structure

```text
app/
  Http/
    Controllers/Api/
      TenantController.php
      TenantResourceController.php
      CompanyController.php
      CustomerController.php
      LeadController.php
      TaskController.php
    Middleware/
      ResolveTenant.php
  Models/
    Concerns/
      BelongsToTenant.php
    Tenant.php
    Company.php
    Customer.php
    Lead.php
    CrmTask.php
    AuditLog.php
  Support/
    Tenancy/
      TenantContext.php
routes/
  api.php
database/
  migrations/
    2026_07_05_000000_create_gravity_crm_core.php
```

## Main decisions

### 1. Shared database with `tenant_id`

The document recommends shared PostgreSQL database plus mandatory `tenant_id` for MVP. The new business tables follow that rule.

Tables added:

- `tenants`
- `companies`
- `customers`
- `leads`
- `tasks`
- `audit_logs`

The `users` table was extended with tenant and SaaS profile fields.

### 2. Tenant context

`ResolveTenant` reads tenant context from:

- `X-Tenant-Id` header, preferred for APIs;
- `tenant_id` query parameter, useful during early testing.

`TenantContext` stores the resolved tenant for the current request.

### 3. Tenant-scoped models

`BelongsToTenant` does two things:

- automatically fills `tenant_id` on create;
- applies a global query scope when a tenant is active.

This keeps tenant filtering near the model layer instead of repeating `where tenant_id` everywhere.

### 4. Compact API controllers

`TenantResourceController` contains shared CRUD behavior for simple tenant resources.

Resource controllers only define:

- model class;
- validation rules.

This avoids copy-paste and keeps the API easy to extend.

### 5. API routes

`routes/api.php` exposes the first MVP endpoints:

- `GET|POST /api/tenants`
- `GET|PATCH /api/tenants/{tenant}`
- `GET|POST /api/companies`
- `GET|PATCH /api/companies/{company}`
- `GET|POST /api/customers`
- `GET|PATCH /api/customers/{customer}`
- `GET|POST /api/leads`
- `GET|PATCH /api/leads/{lead}`
- `GET|POST /api/tasks`
- `GET|PATCH /api/tasks/{task}`

Tenant resources require `X-Tenant-Id`.

## Next safe steps

1. Add API authentication before exposing this outside local development.
2. Add policies for Owner, Manager and Operator roles.
3. Add factories and feature tests for tenant isolation.
4. Add Chatwoot webhook tables: `channels`, `conversations`, `messages`.
5. Add Dify layer: `ai_agents`, `ai_runs`, `knowledge_documents`.

## Notes

No external packages were added. The foundation intentionally stays close to native Laravel so future developers can understand and change it quickly.
## Update: MVP access guardrails

Added the first authorization layer for tenant CRM resources.

### User role

`users.role` now stores the simple MVP role:

- `super_admin`
- `owner`
- `manager`
- `operator`

This is intentionally simple for the first milestone. A full roles/permissions table can be added later when the UI needs dynamic permission management.

### Policies

Added shared policy rules in `app/Policies/TenantResourcePolicy.php`.

Concrete policies inherit the same rules:

- `CompanyPolicy`
- `CustomerPolicy`
- `LeadPolicy`
- `CrmTaskPolicy`

Rules are deliberately small:

- `super_admin` bypasses tenant checks;
- tenant users must match the active `TenantContext`;
- `owner` and `manager` can create/update;
- `operator` can read tenant resources but cannot create/update them.

### Tests

Added `tests/Feature/TenantIsolationTest.php`.

The tests verify:

- one tenant does not see another tenant's company records;
- tenant context is required for tenant resources;
- operator role cannot create company records.

### Migration safety note

The local database already had `2026_07_05_000000_create_gravity_crm_core.php` marked as run during this iteration. To keep upgrades safe, `users.role` is also covered by a follow-up migration:

- `2026_07_05_000001_add_role_to_users_table.php`

It only adds the column when it is missing, so both fresh installs and already-migrated local databases stay valid.

## Update: Demo data and first dashboard

Added a browser-visible MVP screen so the project is useful without calling API endpoints manually.

### Demo seed

Added `database/seeders/DemoDataSeeder.php` and connected it from `DatabaseSeeder`.

It creates:

- demo tenant: `demo`;
- owner user: `owner@gravity.test` / `password`;
- one beauty studio company;
- three customers;
- three leads;
- three operator tasks.

### Dashboard

`/` now renders `resources/views/dashboard.blade.php` instead of Laravel's default welcome page.

The dashboard shows:

- KPI counters;
- latest leads;
- operator tasks;
- API tenant header hint.

The view reads real database records and remains intentionally simple until auth and a proper SPA shell are added.

### Verification

Installed frontend dependencies with `npm install` and generated production assets with `npm run build`.

Verified:

- `php artisan migrate` completed with no pending migrations;
- `php artisan db:seed` created demo records;
- `php artisan test` passed;
- `npm run build` created `public/build/manifest.json`;
- local Laravel server returned HTTP 200 on `http://127.0.0.1:8000`.

## Update: Vue application shell

Replaced the Blade-only dashboard with a Vue 3 UI layer.

### Frontend stack

Added:

- `vue`
- `pinia`
- `@vitejs/plugin-vue`
- `@lucide/vue`

The UI follows a shadcn-vue-style local component approach instead of hiding design primitives inside a large framework.

### Vue structure

```text
resources/js/
  App.vue
  app.js
  bootstrap.js
  lib/
    format.ts
  stores/
    crmDashboard.ts
  components/
    ui/
      UiBadge.vue
      UiButton.vue
      UiCard.vue
      UiSegmentedControl.vue
    dashboard/
      AppSidebar.vue
      EmptyState.vue
      KpiGrid.vue
      LeadPipeline.vue
      MobileNav.vue
      ModuleBoard.vue
      TaskList.vue
```

### UX coverage

The new UI includes:

- desktop sidebar navigation;
- mobile bottom navigation;
- overview dashboard;
- lead pipeline filter;
- operator task list;
- inbox preview for Chatwoot flow;
- CRM view;
- AI agent and integration preview.

The layout is responsive and prepared for a future Capacitor/PWA mobile wrapper.

### Blade role

`resources/views/app.blade.php` is now a thin Vue container. Laravel passes safe bootstrap JSON into `window.__GRAVITY_BOOTSTRAP__`, then Vue owns the interface.

## Update: EN/RU translations

Added a lightweight translation system for the Vue UI.

### Files

```text
resources/js/i18n/messages.ts
resources/js/stores/locale.ts
resources/js/components/dashboard/LanguageSwitcher.vue
```

### Behavior

- UI supports English and Russian.
- Language can be switched from the dashboard header.
- Selected language is saved in `localStorage` as `gravity_locale`.
- Browser language is used on first visit when no preference is saved.
- `document.documentElement.lang` updates when the user switches language.

Visible dashboard text now goes through `locale.t(...)`, while backend/demo data stays as stored database content.

### Fix: Russian translation encoding

Fixed a Windows PowerShell encoding issue where Russian strings were saved as unreadable placeholder characters in `resources/js/i18n/messages.ts`.

Actions:

- rewrote `messages.ts` with explicit UTF-8 encoding;
- rebuilt Vite assets;
- verified the production bundle contains real Russian dashboard labels in the production bundle;
- tests still pass.

## Update: Session auth and protected cabinet

Added native Laravel session authentication for the SaaS cabinet.

### Web routes

- `GET /login` renders the Vue login screen.
- `POST /login` authenticates the user and regenerates the session.
- `POST /logout` logs out and invalidates the session.
- `GET /` is now protected by `auth` middleware.

### API routes

Added session-protected endpoints:

- `GET /api/me`
- `GET /api/dashboard`

Existing CRM API resources are now inside `auth:web` middleware.

### Shared dashboard payload

Added `app/Support/Dashboard/DashboardData.php` so the Blade bootstrap payload and `/api/dashboard` use the same data builder.

### Frontend

Added `resources/js/components/auth/LoginScreen.vue`.

The Vue app now supports two modes:

- `login`
- `dashboard`

The dashboard header shows the signed-in user and a logout button.

### Tests

Added `tests/Feature/AuthFlowTest.php` and updated the default feature test for protected dashboard behavior.

Verified:

- guests are redirected from `/` to `/login`;
- demo owner can log in;
- `/api/me` requires authentication;
- full test suite passes.
## Update: Interactive CRM workspace

Extended the Vue cabinet from a read-only dashboard into a small working CRM surface.

### Frontend

Added focused CRM components:

```text
resources/js/components/dashboard/
  CrmQuickForms.vue
  CustomerList.vue
```

The CRM tab now supports:

- creating customers;
- creating leads linked to customers;
- creating operator tasks linked to leads;
- quick lead status changes;
- quick task status changes;
- refreshed dashboard data after each mutation.

### API client

Added `resources/js/lib/apiClient.ts` for small, reusable same-origin API calls with:

- CSRF token support;
- `X-Tenant-Id` header support;
- JSON request/response handling;
- clear error handling for failed requests.

### State

Expanded `resources/js/stores/crmDashboard.ts` so Pinia owns the CRM workflow state and actions instead of spreading API calls across components.

### Translations

Added EN/RU translations for all new CRM controls and labels.

### Verification

Verified:

- `npm run build` passes;
- `php artisan test` passes: 8 tests, 18 assertions;
- `php -l app/Support/Dashboard/DashboardData.php` passes;
- `php -l app/Http/Controllers/Api/TenantResourceController.php` passes.
## Update: Inbox and AI workflow foundation

Added the first real data layer for the omnichannel Inbox and Dify-style AI workflow described in `tasks/full_doc.docx`.

### Backend schema

Added migration:

```text
database/migrations/2026_07_05_000002_create_inbox_ai_tables.php
```

New tables:

- `channels`
- `conversations`
- `messages`
- `ai_agents`
- `ai_runs`

New models:

```text
app/Models/Channel.php
app/Models/Conversation.php
app/Models/Message.php
app/Models/AiAgent.php
app/Models/AiRun.php
```

All tenant-owned records use the existing `BelongsToTenant` rule, so the new layer follows the same tenant isolation pattern as CRM.

### Demo data

Expanded `DemoDataSeeder` with:

- Telegram, WhatsApp and website channels;
- three demo conversations linked to customers and leads;
- customer and AI messages;
- one active Dify-style AI agent;
- AI run records with confidence, intent, summary and next action.

### Dashboard payload

Expanded `DashboardData` and `/api/dashboard` bootstrap payload with:

- channels;
- conversations;
- messages;
- AI agents;
- AI runs.

### Frontend

Added focused Vue components:

```text
resources/js/components/dashboard/InboxWorkspace.vue
resources/js/components/dashboard/AiWorkspace.vue
```

`App.vue` now stays as the shell and delegates Inbox/AI screens to those components.

### Verification

Verified:

- `php artisan migrate --force` passes;
- `php artisan db:seed --class=DemoDataSeeder --force` passes;
- `npm run build` passes;
- `php artisan test` passes: 8 tests, 18 assertions;
- PHP syntax checks pass for the updated dashboard data builder and new models.
## Update: Chatwoot webhook ingestion

Added the first real ingestion endpoint for external omnichannel messages.

### Endpoint

```text
POST /api/chatwoot/webhook
```

The endpoint accepts flexible Chatwoot-like payloads and resolves tenant context from:

- `X-Tenant-Id` header;
- `tenant_id` body field;
- `tenant_slug` body field.

### Security

Added optional secret validation through:

```text
CHATWOOT_WEBHOOK_SECRET=
```

When the value is configured, requests must include `X-Webhook-Secret`.

### Flow

The webhook handler creates or updates:

- channel;
- customer;
- lead;
- conversation;
- message;
- operator task when handoff is requested.

### Files

```text
app/Http/Controllers/Api/ChatwootWebhookController.php
app/Support/Inbox/ChatwootWebhookHandler.php
tests/Feature/ChatwootWebhookTest.php
```

### Verification

Verified:

- `php artisan route:list --path=chatwoot` shows `POST api/chatwoot/webhook`;
- `php artisan test tests/Feature/ChatwootWebhookTest.php` passes;
- `php artisan test` passes: 11 tests, 30 assertions;
- `npm run build` passes.
## Update: Local AI workflow after webhook

Added a deterministic MVP AI workflow that runs after each Chatwoot webhook message.

### AI support layer

```text
app/Support/Ai/AiDecision.php
app/Support/Ai/LocalConversationAnalyzer.php
app/Support/Ai/AiWorkflow.php
```

The local analyzer classifies the latest message into simple intents:

- booking request;
- pricing request;
- payment policy;
- complaint;
- general question.

It then computes confidence, summary, next action and whether an operator handoff is required.

### Webhook integration

`ChatwootWebhookHandler` now calls `AiWorkflow` after creating the message.

The workflow now:

- creates a default Dify-style AI agent when one does not exist;
- creates an `ai_runs` record;
- updates lead score, status and AI summary;
- updates conversation summary, priority and handoff status;
- creates an operator task when confidence is below threshold or policy/complaint intent is detected.

### Tests

Expanded `ChatwootWebhookTest` to verify:

- webhook creates inbox, CRM and AI records;
- clear booking requests become qualified leads;
- payment policy questions create AI handoff tasks;
- tenant context and webhook secret checks still work.

### Verification

Verified:

- `php artisan test tests/Feature/ChatwootWebhookTest.php` passes: 5 tests;
- `php artisan test` passes: 13 tests, 43 assertions;
- `npm run build` passes.
## Update: Knowledge Base foundation

Added the first maintainable Knowledge Base layer for AI agent retrieval.

### Backend schema

Added migration:

```text
database/migrations/2026_07_05_000003_create_knowledge_base_tables.php
```

New tables:

- `knowledge_documents`
- `knowledge_chunks`

New models:

```text
app/Models/KnowledgeDocument.php
app/Models/KnowledgeChunk.php
```

Documents belong to tenant, company and optionally an AI agent. Chunks belong to one document and are ready for future vector indexing.

### API

Added protected tenant-scoped resource:

```text
/api/knowledge-documents
```

The API supports index, create, show and update through the existing tenant resource controller pattern.

### Demo data

Expanded `DemoDataSeeder` with indexed demo documents:

- Beauty services FAQ;
- Booking rules and working hours.

Each demo document has chunks so the UI can show realistic indexed source counts.

### Frontend

Added:

```text
resources/js/components/dashboard/KnowledgeBasePanel.vue
```

The AI workspace now shows indexed knowledge documents, source file/type, status, version and chunk count.

### Translations

Rewrote `resources/js/i18n/messages.ts` as clean UTF-8 and added EN/RU labels for Knowledge Base UI.

### Tests and verification

Added `tests/Feature/KnowledgeDocumentTest.php`.

Verified:

- `php artisan migrate --force` passes;
- `php artisan db:seed --class=DemoDataSeeder --force` passes;
- `php artisan route:list --path=knowledge-documents` shows the resource routes;
- `php artisan test` passes: 14 tests, 46 assertions;
- `npm run build` passes.
## Update: Knowledge Base text indexing

Added the first usable indexing flow for Knowledge Base content.

### Backend

Added:

```text
app/Support/Knowledge/KnowledgeIndexer.php
POST /api/knowledge-documents/index-text
```

The indexer accepts source text, creates a `knowledge_documents` record, splits content into `knowledge_chunks`, calculates token counts and marks the document as indexed.

### Frontend

Expanded `KnowledgeBasePanel.vue` with a small operator form:

- document title;
- source text textarea;
- index action;
- automatic dashboard refresh after indexing.

Pinia now exposes `indexKnowledgeText(...)` for the UI and future import screens.

### Encoding note

Rewrote `messages.ts` in an ASCII-safe format with Unicode escape sequences for Russian strings. This avoids Windows PowerShell encoding corruption while still rendering Russian text correctly in the browser.

### Tests and verification

Expanded `KnowledgeDocumentTest` to verify text indexing creates chunks.

Verified:

- `php artisan route:list --path=knowledge-documents` shows `POST api/knowledge-documents/index-text`;
- `php artisan test tests/Feature/KnowledgeDocumentTest.php` passes: 2 tests;
- `php artisan test` passes: 15 tests, 52 assertions;
- `npm run build` passes;
- source scan shows no mojibake markers in touched code.

## Update: Tenant integration settings

Added a tenant-owned settings layer for external integrations, so production credentials can be managed per workspace instead of being hard-coded into only `.env`.

### Backend

Added:

```text
app/Http/Controllers/Api/IntegrationSettingsController.php
app/Policies/TenantPolicy.php
GET /api/integration-settings
PATCH /api/integration-settings
```

The API stores integration settings under `tenants.settings.integrations` and masks secrets in responses.

Supported settings:

- Dify API URL;
- Dify API key;
- Dify request timeout;
- Dify handoff threshold;
- Chatwoot webhook secret.

### Runtime integration

`DifyClient` now reads tenant settings first and falls back to global `services.dify` config.

`ChatwootWebhookController` now supports tenant-specific webhook secrets and falls back to global `CHATWOOT_WEBHOOK_SECRET` when no tenant secret is configured.

### Authorization

Added `TenantPolicy` so only the tenant owner/manager can update integration settings. Operators remain read-only for tenant resources.

### Tests and verification

Added `tests/Feature/IntegrationSettingsTest.php`.

Verified:

- owner can update integration settings;
- secrets are masked in API responses;
- operator cannot update settings;
- Chatwoot accepts tenant-specific webhook secret;
- Dify uses tenant settings before global config;
- `php artisan test` passes: 21 tests, 78 assertions;
- `npm run build` passes.
## Update: Integration settings UI

Added a Vue settings screen for the tenant integration layer.

### Frontend

Added:

```text
resources/js/components/dashboard/IntegrationSettingsPanel.vue
```

The screen supports:

- viewing Dify and Chatwoot configuration status;
- editing Dify API URL;
- rotating Dify API key without exposing the stored value;
- changing Dify timeout;
- changing AI handoff threshold;
- rotating Chatwoot webhook secret;
- saving through the tenant-scoped `/api/integration-settings` endpoint.

### Navigation

Added a Settings section to:

- desktop sidebar;
- mobile bottom navigation.

The Pinia dashboard store now owns integration settings state and API actions, keeping the component focused on UI only.

### Translations

Added English and Russian labels for the new settings screen in `resources/js/i18n/messages.ts`.

### Verification

Verified:

- `npm run build` passes;
- `php artisan test` passes: 21 tests, 78 assertions.
## Update: Integration connection tests

Added an operator-facing connection test flow for integrations.

### Backend

Added:

```text
POST /api/integration-settings/test
```

Supported providers:

- `dify` - sends a short blocking request to the configured Dify `/chat-messages` endpoint;
- `chatwoot` - verifies that the tenant webhook secret is configured and returns the local webhook URL/header to use in Chatwoot.

The endpoint is tenant-scoped and protected by `TenantPolicy`, so only owner/manager can run connection tests.

### Frontend

Expanded `IntegrationSettingsPanel.vue` with:

- Test Dify button;
- Test Chatwoot button;
- inline success/error result panel;
- EN/RU labels for test actions.

The UI can test current form values before saving new secrets, while saved values remain masked.

### Tests and verification

Expanded `IntegrationSettingsTest` to cover:

- successful Dify connection test;
- missing Dify credentials;
- Chatwoot webhook readiness;
- operator access denial.

Verified:

- `php artisan test tests/Feature/IntegrationSettingsTest.php` passes: 8 tests, 31 assertions;
- `php artisan test` passes: 25 tests, 91 assertions;
- `npm run build` passes.
## Update: Knowledge Base file upload

Added file upload support to the Knowledge Base workflow.

### Backend

Added:

```text
POST /api/knowledge-documents/upload
```

`KnowledgeIndexer` now supports uploaded files:

- TXT, MD, CSV and JSON are read as text and indexed into chunks immediately;
- PDF, DOCX and XLSX are stored and marked as `queued` for a future parser worker;
- uploaded file metadata stores disk, storage path, extension and original size.

This keeps the MVP honest: text files are usable now, while binary document parsing has a clear future boundary.

### Frontend

Expanded `KnowledgeBasePanel.vue` with:

- paste-text indexing form;
- file upload form;
- accepted extensions: `.txt`, `.md`, `.csv`, `.json`, `.pdf`, `.docx`, `.xlsx`;
- EN/RU labels for upload controls.

The shared API client now supports `FormData` requests without forcing JSON headers.

### Tests and verification

Expanded `KnowledgeDocumentTest` to cover:

- text file upload creates indexed chunks;
- PDF upload creates a queued parser document.

Verified:

- `php artisan route:list --path=knowledge-documents` shows upload route;
- `php artisan test tests/Feature/KnowledgeDocumentTest.php` passes: 4 tests, 23 assertions;
- `php artisan test` passes: 27 tests, 105 assertions;
- `npm run build` passes.
## Update: Audit log foundation

Started using the existing `audit_logs` table for real tenant actions.

### Backend

Added:

```text
app/Support/Audit/AuditLogger.php
```

Audit events are now recorded for:

- integration settings updates;
- integration connection tests;
- Knowledge Base text indexing;
- Knowledge Base file uploads.

Secrets are not written to audit logs. Integration settings audit entries store safe configuration state only, such as configured flags, masks, URL, timeout and threshold.

### Dashboard payload

`DashboardData` now includes the latest tenant audit entries with user context, so the UI can show operational history without a separate admin module yet.

### Frontend

The Settings screen now includes a compact Audit Log card showing recent tenant actions, actor email, entity type and timestamp.

### Tests and verification

Expanded feature tests to verify audit entries are created for settings, connection tests and Knowledge Base indexing/upload.

Verified:

- `php artisan test tests/Feature/IntegrationSettingsTest.php` passes: 8 tests, 33 assertions;
- `php artisan test tests/Feature/KnowledgeDocumentTest.php` passes: 4 tests, 24 assertions;
- `php artisan test` passes: 27 tests, 108 assertions;
- `npm run build` passes.
## Update: Tenant users and roles UI

Added the first tenant team management layer.

### Backend

Added:

```text
app/Http/Controllers/Api/TenantUserController.php
GET /api/tenant-users
POST /api/tenant-users
PATCH /api/tenant-users/{tenant_user}
```

Owners and managers can now:

- list users from the active tenant;
- create tenant users;
- assign `owner`, `manager` or `operator` role;
- set user status as `active`, `invited` or `disabled`;
- update role/status later.

Operators cannot manage users. User management is tenant-scoped by `X-Tenant-Id`.

### Audit

User creation and updates now write audit events:

- `tenant_user.created`
- `tenant_user.updated`

Password values are never returned or written to audit payloads.

### Frontend

Added:

```text
resources/js/components/dashboard/TenantUsersPanel.vue
```

The Settings screen now includes a Team and roles panel with:

- create user form;
- role selector;
- activate/disable action;
- tenant user list.

### Tests and verification

Added `tests/Feature/TenantUserManagementTest.php`.

Verified:

- owner can create users;
- owner can update role/status;
- operator receives 403;
- tenant user list does not leak another tenant;
- `php artisan test tests/Feature/TenantUserManagementTest.php` passes: 4 tests, 14 assertions;
- `php artisan test` passes: 31 tests, 122 assertions;
- `npm run build` passes.
## Update: Encrypted tenant integration secrets

Hardened storage for integration credentials.

### Backend

Added:

```text
app/Support/Integrations/TenantIntegrationSettings.php
```

The service centralizes secret handling for tenant integrations:

- encrypts newly saved Dify API keys;
- encrypts newly saved Chatwoot webhook secrets;
- decrypts values for runtime use;
- keeps backward compatibility with existing plain string settings;
- masks decrypted values for API responses and audit snapshots.

### Runtime integration

Updated:

- `IntegrationSettingsController`
- `DifyClient`
- `ChatwootWebhookController`

All three now read secrets through `TenantIntegrationSettings` instead of reading raw JSON values directly.

### Compatibility

Existing plain values still work. New writes through `/api/integration-settings` are stored with the `enc:v1:` prefix.

### Tests and verification

Expanded integration settings tests to verify:

- secrets are no longer stored as plain strings;
- encrypted values can be decrypted by the service;
- old plain settings remain readable;
- webhook and Dify flows still work.

Verified:

- `php artisan test tests/Feature/IntegrationSettingsTest.php` passes: 9 tests, 39 assertions;
- `php artisan test tests/Feature/ChatwootWebhookTest.php` passes: 7 tests, 33 assertions;
- `php artisan test` passes: 32 tests, 128 assertions;
- `npm run build` passes.
## Update: API rate limiting guardrails

Added first production-oriented rate limits to externally triggered endpoints.

### Routes

Added Laravel throttle middleware to:

```text
POST /api/chatwoot/webhook          throttle:30,1
POST /api/integration-settings/test throttle:10,1
```

The webhook limit protects public inbound traffic. The integration test limit prevents repeated manual/API test loops from hammering Dify or local webhook checks.

### Tests and verification

Expanded feature tests to cover HTTP 429 behavior for:

- Chatwoot webhook route;
- integration connection test route.

Verified:

- `php artisan test tests/Feature/ChatwootWebhookTest.php` passes: 8 tests, 64 assertions;
- `php artisan test tests/Feature/IntegrationSettingsTest.php` passes: 10 tests, 50 assertions;
- `php artisan test` passes: 34 tests, 170 assertions;
- `npm run build` passes.
## Update: Chatwoot webhook idempotency

Made inbound Chatwoot message handling safe for duplicate delivery.

### Backend

Updated:

```text
app/Support/Inbox/ChatwootWebhookHandler.php
app/Http/Controllers/Api/ChatwootWebhookController.php
```

Behavior now is:

- first delivery creates/updates inbox, CRM, message, AI run, and optional task;
- repeated delivery with the same message external id returns the existing CRM context;
- duplicate delivery does not run the AI workflow again;
- duplicate delivery returns HTTP 200 with `duplicate: true`;
- new delivery still returns HTTP 201 with `duplicate: false`.

### Tests and verification

Added a feature test that sends the same Chatwoot payload twice and verifies:

- only one `messages` row exists for the external id;
- only one `ai_runs` row exists for the conversation;
- the second response is marked as duplicate.

Verified:

- `php -l app/Support/Inbox/ChatwootWebhookHandler.php` passes;
- `php -l app/Http/Controllers/Api/ChatwootWebhookController.php` passes;
- `php -l tests/Feature/ChatwootWebhookTest.php` passes;
- `php artisan test tests/Feature/ChatwootWebhookTest.php` passes: 9 tests, 71 assertions;
- `php artisan test` passes: 35 tests, 177 assertions;
- `npm run build` passes.
## Update: Chatwoot external instance integration

Added tenant-level configuration for an already running external Chatwoot instance.

### Backend

Updated:

```text
app/Http/Controllers/Api/IntegrationSettingsController.php
app/Support/Integrations/TenantIntegrationSettings.php
config/services.php
.env.example
```

The integration settings now support:

- Chatwoot base URL;
- Chatwoot account ID;
- encrypted Chatwoot API access token;
- optional encrypted webhook secret;
- generated CRM webhook URL with `tenant_slug` query;
- real Chatwoot API connection test through `/api/v1/accounts/{account_id}/inboxes`.

### Frontend

Updated:

```text
resources/js/components/dashboard/IntegrationSettingsPanel.vue
resources/js/stores/crmDashboard.ts
resources/js/i18n/messages.ts
```

The Settings UI now has Chatwoot fields for URL, account ID, API token, webhook secret, and shows the CRM webhook URL that should be pasted into Chatwoot webhooks.

### Tests and verification

Expanded integration settings tests to verify:

- Chatwoot API token is encrypted at rest;
- existing plain Chatwoot token values remain readable;
- connection test calls Chatwoot with the `api_access_token` header;
- webhook settings and audit snapshots do not expose secret values.

Verified:

- `php artisan test tests/Feature/IntegrationSettingsTest.php` passes: 10 tests, 62 assertions;
- `php artisan test` passes: 35 tests, 189 assertions;
- `npm run build` passes.
## Update: Chatwoot sync and reply hardening

Added a production-oriented bridge between the CRM Inbox and an external Chatwoot account.

### Backend

Added:

```text
app/Support/Chatwoot/ChatwootClient.php
app/Support/Chatwoot/ChatwootPayloadMapper.php
app/Support/Chatwoot/ChatwootConversationSync.php
app/Http/Controllers/Api/ChatwootSyncController.php
POST /api/chatwoot/sync
```

The CRM can now:

- fetch conversations from Chatwoot by tenant settings;
- import the latest customer message into channels, customers, leads, conversations and messages;
- keep webhook delivery idempotent;
- ignore outgoing/operator Chatwoot webhooks so CRM replies do not loop back as customer messages;
- send operator replies back to linked Chatwoot conversations.

### Frontend

Updated:

```text
resources/js/components/dashboard/InboxWorkspace.vue
resources/js/stores/crmDashboard.ts
resources/js/i18n/messages.ts
```

The Inbox now includes a Sync Chatwoot action and keeps EN/RU labels in the existing translation system.

### Tests and verification

Added `tests/Feature/ChatwootSyncTest.php` and expanded `ChatwootWebhookTest` for native Chatwoot payloads.

Verified:

- `php artisan test tests/Feature/ChatwootSyncTest.php tests/Feature/ChatwootWebhookTest.php tests/Feature/ConversationReplyTest.php` passes: 15 tests, 98 assertions;
- `php artisan test` passes: 41 tests, 216 assertions;
- `npm run build` passes;
- source scan shows no mojibake markers in touched code.
## Update: Dify AI draft workflow

Made the AI workflow more practical for the Chatwoot-to-CRM path.

### Backend

Updated:

```text
app/Support/Ai/AiDecision.php
app/Support/Ai/DifyClient.php
app/Support/Ai/AiWorkflow.php
```

The Dify request now includes:

- conversation subject;
- lead title;
- agent instructions;
- handoff threshold;
- recent conversation messages;
- indexed Knowledge Base context.

Dify responses can now return `reply`, `reply_text` or `draft_reply`. The CRM stores that value as an internal AI draft message in the conversation with `sender_type = ai`. It does not auto-send the draft to the customer; the operator still decides what to send.

### Tests and verification

Expanded `tests/Feature/ChatwootWebhookTest.php` to cover Dify context and AI draft message creation.

Verified:

- `php artisan test tests/Feature/ChatwootWebhookTest.php` passes: 12 tests, 84 assertions;
- `php artisan test` passes: 42 tests, 220 assertions;
- `npm run build` passes.
## Update: Dashboard page navigation

Refactored the Vue dashboard from tab-like in-memory views into page-oriented navigation.

### Frontend

Added:

```text
resources/js/lib/pages.ts
resources/js/pages/OverviewPage.vue
resources/js/pages/CrmPage.vue
resources/js/pages/SettingsPage.vue
```

Updated:

```text
resources/js/App.vue
resources/js/stores/crmDashboard.ts
resources/js/components/dashboard/AppSidebar.vue
resources/js/components/dashboard/MobileNav.vue
```

Behavior now is:

- `/` opens Overview;
- `/inbox` opens Inbox;
- `/crm` opens CRM;
- `/ai` opens AI Agents;
- `/settings` opens Settings;
- sidebar and mobile navigation use real links with History API navigation;
- browser back/forward changes the active page correctly.

### Backend

Updated `routes/web.php` so authenticated users can open dashboard pages directly. Unknown page paths are not captured.

### Tests and verification

Expanded `tests/Feature/AuthFlowTest.php` for direct page routes.

Verified:

- `php artisan test` passes: 44 tests, 225 assertions;
- `npm run build` passes.

## Update: Dify and Chatwoot operator approval flow

Clarified the AI response lifecycle between Dify, CRM and Chatwoot.

### Behavior

Dify responses are stored as internal CRM draft messages with:

- `sender_type = ai`;
- draft metadata;
- no automatic customer delivery.

The operator can now explicitly decide what to do with an AI draft:

- insert it into the reply box;
- send it to the linked Chatwoot conversation.

This keeps the MVP safe for real operators: AI assists, but the human still controls customer-facing replies.

### Cleanup

Updated `DifyClient` to remove model reasoning blocks such as:

```text
<think>...</think>
```

from generated summaries and draft replies before saving them to CRM.

### Frontend

Updated:

```text
resources/js/components/dashboard/InboxWorkspace.vue
resources/js/i18n/messages.ts
```

AI draft bubbles are now visually distinct from operator messages and include clear actions.

### Verification

Verified:

- old stored AI draft with `<think>` was cleaned locally;
- `php artisan test` passes: 44 tests, 225 assertions;
- `npm run build` passes.

## Update: Editable AI agent profile

Added CRM-side editing for AI agent behavior.

### Backend

Added:

```text
app/Http/Controllers/Api/AiAgentController.php
PATCH /api/ai-agents/{aiAgent}
```

Owners and managers can update:

- agent name;
- status;
- handoff threshold;
- instructions sent into the AI workflow.

Operators cannot update AI agent settings.

### Audit

AI agent changes now create audit events:

```text
ai_agent.updated
```

### Frontend

Updated:

```text
resources/js/components/dashboard/AiWorkspace.vue
resources/js/stores/crmDashboard.ts
resources/js/i18n/messages.ts
```

The AI Agents page now includes a real form for editing the active agent profile.

### Tests and verification

Added:

```text
tests/Feature/AiAgentTest.php
```

Verified:

- owner can update AI agent profile;
- operator receives 403;
- `php artisan test` passes: 46 tests, 230 assertions;
- `npm run build` passes.

## Update: Clearer Inbox UI and component split

Refactored the Inbox screen into small, readable Vue components so the chat workflow is easier to understand and maintain.

### Frontend structure

Added:

```text
resources/js/components/dashboard/inbox/
  AiDraftPanel.vue
  ChatMessageBubble.vue
  ChatThread.vue
  ConversationInfo.vue
  ConversationQueue.vue
  InboxChannels.vue
  ReplyComposer.vue
  inboxUi.ts
```

Updated:

```text
resources/js/components/dashboard/InboxWorkspace.vue
resources/js/i18n/messages.ts
resources/js/stores/crmDashboard.ts
```

### UX changes

The Inbox now separates the operator flow into clear tabs:

- Chat - customer/operator conversation only;
- AI draft - Dify suggestion with explicit actions;
- Details - customer, lead and priority context.

AI drafts are no longer mixed into the main conversation thread. Operators can still insert an AI draft into the reply box or send it to Chatwoot from the AI tab.

### Maintainability

The previous large Inbox component was reduced to a small screen composer. New Inbox components are intentionally short and focused; the largest new Vue component is under 50 lines.

### Verification

Verified:

- `npm run build` passes;
- `php artisan test` passes: 46 tests, 230 assertions.

## Update: Company profile context for Dify

Added a structured company profile layer so the AI workflow can answer with business facts instead of guessing.

Files changed:
- `resources/js/components/dashboard/CompanyProfilePanel.vue`
- `resources/js/components/dashboard/AiWorkspace.vue`
- `resources/js/stores/crmDashboard.ts`
- `resources/js/i18n/messages.ts`
- `app/Support/Dashboard/DashboardData.php`
- `app/Support/Ai/DifyClient.php`
- `database/seeders/DemoDataSeeder.php`
- `tests/Feature/ChatwootWebhookTest.php`

What changed:
- added editable company profile fields for name, industry, phone, address, working hours, services, booking rules and cancellation policy;
- exposed company profile data in the dashboard bootstrap payload;
- sends `business_profile` into Dify inputs together with conversation, history and knowledge context;
- fixed the missing `businessProfile()` method that caused Dify to fall back silently;
- removed duplicated English `company` translation from the Russian locale block;
- demo seed now includes realistic salon working hours, services, booking rules and cancellation policy.

Verification:
- `php -l app\Support\Ai\DifyClient.php` passes;
- `php artisan test --filter=ChatwootWebhookTest` passes: 12 tests, 84 assertions;
- `php artisan test` passes: 46 tests, 230 assertions;
- `npm run build` passes.
## Update: Knowledge Base UI 2.0

Refactored the AI Knowledge Base screen into small, focused Vue components so the section is easier to read and extend.

Files changed:
- `resources/js/components/dashboard/KnowledgeBasePanel.vue`
- `resources/js/components/dashboard/knowledge/KnowledgeTextForm.vue`
- `resources/js/components/dashboard/knowledge/KnowledgeUploadForm.vue`
- `resources/js/components/dashboard/knowledge/KnowledgeDocumentList.vue`
- `resources/js/components/dashboard/knowledge/KnowledgeStats.vue`
- `resources/js/i18n/messages.ts`

What changed:
- split text indexing, file upload, document list and stats into separate components;
- added document counters for total, indexed and queued documents;
- added document search/filter by title, summary, file name, source type and status;
- added an empty state for filtered/empty knowledge lists;
- added English and Russian translations for the new UI labels.

Verification:
- `php artisan test --filter=KnowledgeDocumentTest` passes: 4 tests, 24 assertions;
- `php artisan test` passes: 46 tests, 230 assertions;
- `npm run build` passes.
## Update: Customer Profile 1.0

Added a clearer CRM customer workspace without introducing new backend tables.

Files changed:
- `resources/js/pages/CrmPage.vue`
- `resources/js/components/dashboard/CustomerList.vue`
- `resources/js/components/dashboard/CustomerProfilePanel.vue`
- `resources/js/stores/crmDashboard.ts`
- `resources/js/i18n/messages.ts`
- `app/Support/Dashboard/DashboardData.php`

What changed:
- customer list is now selectable;
- CRM page shows a customer profile with contact data;
- profile shows linked leads and related conversations;
- lead payload now includes `customer_id` for profile linking;
- added English and Russian UI labels.

Verification:
- `npm run build` passes;
- `php artisan test` passes: 46 tests, 230 assertions.
## Update: Lead Detail Panel 1.0

Added a focused lead detail workspace so operators can inspect a lead without leaving the CRM page.

Files changed:
- `resources/js/pages/CrmPage.vue`
- `resources/js/components/dashboard/LeadPipeline.vue`
- `resources/js/components/dashboard/LeadDetailPanel.vue`
- `resources/js/stores/crmDashboard.ts`
- `resources/js/i18n/messages.ts`
- `app/Support/Dashboard/DashboardData.php`

What changed:
- lead pipeline rows are now selectable;
- CRM page shows selected lead details next to the pipeline;
- lead detail shows customer, source, status, AI summary, linked tasks and linked conversations;
- added quick actions for qualified, won and lost;
- dashboard payload now includes `lead_id` for tasks and `customer_id` for leads;
- added English and Russian labels.

Verification:
- `npm run build` passes;
- `php artisan test` passes: 46 tests, 230 assertions.
## Update: Operator Task Board 1.0

Turned the operator task list into a simple board for day-to-day work.

Files changed:
- `resources/js/components/dashboard/TaskList.vue`
- `resources/js/i18n/messages.ts`

What changed:
- tasks are grouped into open, in progress and done columns;
- added priority filter;
- kept quick actions for start and done;
- added empty states per column;
- added English and Russian labels.

Verification:
- `npm run build` passes;
- `php artisan test` passes: 46 tests, 230 assertions.
## Update: AI Handoff Center 1.0

Added a focused handoff queue in the AI workspace for operator review of low-confidence AI decisions.

Files changed:
- `resources/js/components/dashboard/AiHandoffCenter.vue`
- `resources/js/components/dashboard/AiWorkspace.vue`
- `resources/js/i18n/messages.ts`

What changed:
- added a handoff center above AI runs;
- shows low-confidence AI runs with confidence, intent, summary, lead and conversation;
- links matching open tasks by lead;
- added quick task actions for start and done;
- added English and Russian labels.

Verification:
- `npm run build` passes;
- `php artisan test` passes: 46 tests, 230 assertions.
## Update: Workspace Navigation 1.0

Connected Inbox, CRM and AI handoff screens with shared navigation state.

Files changed:
- `resources/js/stores/crmDashboard.ts`
- `resources/js/pages/CrmPage.vue`
- `resources/js/components/dashboard/inbox/ConversationInfo.vue`
- `resources/js/components/dashboard/AiHandoffCenter.vue`

What changed:
- selected customer and selected lead are now stored in Pinia instead of local CRM page state;
- added `openCustomer`, `openLead` and `openConversation` navigation helpers;
- Inbox conversation details can jump to customer or lead in CRM;
- AI handoff items can jump to lead in CRM or conversation in Inbox;
- CRM keeps the selected customer/lead when moving between workspace pages.

Verification:
- `npm run build` passes;
- `php artisan test` passes: 46 tests, 230 assertions.
## Update: Production Readiness 1.0

Added a production readiness checklist to Settings so launch blockers are visible in one place.

Files changed:
- `resources/js/pages/SettingsPage.vue`
- `resources/js/components/dashboard/ProductionReadinessPanel.vue`
- `resources/js/i18n/messages.ts`

What changed:
- added readiness percent based on current workspace data;
- checks Dify, Chatwoot, indexed knowledge, active AI agent, team readiness and CRM data;
- added English and Russian labels;
- placed the checklist above integration settings.

Verification:
- `npm run build` passes;
- `php artisan test` passes: 46 tests, 230 assertions.
## Update: Manual QA Checklist 1.0

Added a compact manual QA checklist for validating the whole CRM loop before demos.

Files changed:
- `tasks/manual_qa_checklist.md`
- `resources/js/components/dashboard/ManualQaPanel.vue`
- `resources/js/pages/SettingsPage.vue`
- `resources/js/i18n/messages.ts`

What changed:
- documented the end-to-end manual smoke test flow;
- added a Settings UI panel that points operators to the QA scenario;
- covers environment, Chatwoot to CRM, AI draft, handoff, CRM workspace, knowledge base and final commands;
- added English and Russian labels.

Verification:
- `npm run build` passes;
- `php artisan test` passes: 46 tests, 230 assertions.
## Update: Real Channel Setup 1.0

Expanded Inbox channel cards into practical setup cards for real Telegram, WhatsApp and website channels through Chatwoot.

Files changed:
- `resources/js/components/dashboard/inbox/InboxChannels.vue`
- `resources/js/i18n/messages.ts`

What changed:
- channel cards now show setup instructions by provider;
- added CRM webhook URL display and copy action;
- added a direct link to Chatwoot inbox settings;
- added English and Russian labels;
- kept the implementation UI-only to avoid unnecessary backend surface.

Verification:
- `npm run build` passes;
- `php artisan test` passes: 46 tests, 230 assertions.
## Update: Role & Permissions UX 1.0

Made team roles visible and safer to manage from Settings.

Files changed:
- `resources/js/components/dashboard/TenantUsersPanel.vue`
- `resources/js/i18n/messages.ts`

What changed:
- added a clear owner, manager and operator permissions matrix;
- added readable English and Russian labels for roles and permissions;
- added an owner warning for the current account;
- blocked disabling your own owner account from the UI;
- kept invite, role update and status toggle flows unchanged.

Verification:
- `npm run build` passes;
- `php artisan test` passes: 46 tests, 230 assertions.
## Update: Manual AI Draft Flow 1.0

Added a clear operator-controlled path for regenerating AI replies before sending them to Chatwoot.

Files changed:
- `app/Http/Controllers/Api/ConversationAiDraftController.php`
- `routes/api.php`
- `resources/js/stores/crmDashboard.ts`
- `resources/js/components/dashboard/InboxWorkspace.vue`
- `resources/js/components/dashboard/inbox/AiDraftPanel.vue`
- `resources/js/i18n/messages.ts`
- `tests/Feature/ConversationAiDraftTest.php`

What changed:
- added `POST /api/conversations/{conversation}/ai-draft` for manual Dify draft generation;
- uses the latest customer message and linked lead to run the existing AI workflow;
- saves the generated reply as an internal AI draft message;
- added a visible Generate draft button in the AI draft tab;
- kept final customer delivery manual through Send to Chatwoot;
- added English and Russian UI labels.

Verification:
- `php -l` passes for the new controller and test;
- `npm run build` passes;
- `php artisan test` passes: 47 tests, 237 assertions;
- `php artisan optimize:clear` passes.
## Update: Operator Demo Flow 1.0

Added a compact Settings panel that shows the live status of the full operator demo path.

Files changed:
- `resources/js/components/dashboard/OperatorDemoFlowPanel.vue`
- `resources/js/pages/SettingsPage.vue`
- `resources/js/i18n/messages.ts`

What changed:
- tracks Chatwoot conversation, customer message, AI draft, operator reply and CRM links;
- shows a simple percent score and next action per step;
- added English and Russian labels;
- kept the component compact at 43 lines.

Verification:
- `npm run build` passes;
- `php artisan test` passes: 47 tests, 237 assertions;
- `php artisan optimize:clear` passes.
## Update: Mobile Polish and README 1.0

Finished a compact final pass for mobile readability and practical local startup documentation.

Files changed:
- `README.md`
- `resources/js/components/dashboard/InboxWorkspace.vue`
- `resources/js/components/dashboard/IntegrationHealthPanel.vue`
- `resources/js/components/dashboard/OperatorDemoFlowPanel.vue`

What changed:
- added local Laravel/Vite startup instructions;
- documented Chatwoot and Dify local URLs and settings values;
- documented the manual CRM demo flow;
- fixed the Inbox `generateDraft` handler for the AI draft tab;
- tightened Inbox tabs for narrow screens;
- changed 5-column health/demo cards to mobile-first grids;
- kept edited components under 100 lines.

Verification:
- `npm run build` passes;
- `php artisan test` passes: 47 tests, 237 assertions;
- `php artisan optimize:clear` passes.
## Update: Final Smoke Fix 1.0

Ran a final smoke pass and fixed the Inbox AI draft action wiring.

Files changed:
- `resources/js/components/dashboard/InboxWorkspace.vue`

What changed:
- restored the `generateDraft` handler used by the AI draft tab;
- after sending an AI draft, Inbox returns to the chat tab and clears the composer;
- confirmed `/settings` responds with HTTP 200.

Verification:
- `php -l app/Http/Controllers/Api/ConversationAiDraftController.php` passes;
- `npm run build` passes;
- `php artisan test` passes: 47 tests, 237 assertions;
- `php artisan optimize:clear` passes.
## Update: Final Acceptance Pass 1.0

Ran the final lightweight acceptance pass for the CRM MVP.

Verification:
- `/settings` responds with HTTP 200;
- Inbox `generateDraft` wiring exists in UI and Pinia store;
- Integration Health and Operator Demo Flow panels are mounted in Settings;
- `README.md` exists with startup and demo instructions;
- `npm run build` passes;
- `php artisan test` passes: 47 tests, 237 assertions;
- `php artisan optimize:clear` passes.
## Update: Theme System and Shadcn UI Pass 1.0

Date: 2026-07-07

Changed:
- Added Pinia theme store with persistent light/dark mode.
- Added ThemeSwitcher component for the dashboard header.
- Introduced CSS design tokens for background, foreground, cards, borders, inputs, primary color and muted surfaces.
- Updated UI primitives to use token-based shadcn-style surfaces and controls.
- Made sidebar, mobile nav, language switcher and app shell theme-aware.
- Kept touched UI components small and easy to extend; changed components are under 100 lines.

Verification:
- npm run build: passed.
- php artisan test: passed, 47 tests, 237 assertions.
- php artisan optimize:clear: passed.

Note:
- ui.png exists in tasks, but Windows ACL blocked direct image preview in the tool. The UI pass followed full_doc.docx requirements and existing CRM structure.

## Update: Reference SaaS UI Layout Pass

Date: 2026-07-07

Changed:
- Reworked the app shell toward the provided Gravity AI reference: compact dark SaaS sidebar, blue active navigation and wider dashboard canvas.
- Added full module navigation: Overview, Inbox, Leads, Customers, Deals, AI Agents, Knowledge Base, Analytics, Integrations, Billing and Settings.
- Added small visual dashboard components: VisualStatCard, VisualLineChart, VisualDonut and VisualPerformance.
- Rebuilt Overview with KPI cards, chart area, channel donut, lead pipeline and AI performance block.
- Added new pages for Leads kanban, Customer Profile, Analytics, Knowledge Base, Integrations and Billing.
- Extended Laravel dashboard routes for the new front-end pages.

Structure:
- New visual components live in resources/js/components/dashboard/visual.
- New pages live in resources/js/pages and are connected through resources/js/lib/pages.ts.
- New components/pages are intentionally small; added visual components are 14-16 lines and added pages are under 31 lines.

Verification:
- npm run build: passed.
- php artisan test: passed, 47 tests, 237 assertions.
- php artisan optimize:clear: passed.

## Update: Live Integrations Cards

Date: 2026-07-07

Changed:
- Converted the Integrations page from static demo cards into live CRM blocks.
- Cards now read real channel status from the Pinia dashboard store.
- Dify and Chatwoot cards now use tenant integration settings to show connected or pending state.
- Added live actions: Sync Chatwoot, Test Dify, Test Chatwoot, Configure and external open buttons.
- Added webhook URL visibility directly on the Integrations page.

Verification:
- npm run build: passed.
- php artisan test: passed, 47 tests, 237 assertions.
- php artisan optimize:clear: passed.
- IntegrationsPage.vue remains compact at 76 lines.

## Update: Chatwoot Test Page SDK Fix

Date: 2026-07-09

Issue:
- public/chatwoot-test.html used http://localhost:3000/packs/js/sdk.js.
- Current local Chatwoot Docker dev setup does not expose that route and returns HTTP 404.

Changed:
- Updated public/chatwoot-test.html with a visible Chatwoot + Dify diagnostic panel.
- Added SDK fallback loading: first /packs/js/sdk.js, then current Vite dev asset /vite-dev/assets/sdk-CCw9CEvP.js.
- Added quick links to Chatwoot, Dify and CRM Integrations.
- Kept the existing website token, verified it exists in Chatwoot DB and belongs to website_url http://localhost:8000.

Verification:
- http://localhost:8000/chatwoot-test.html: HTTP 200.
- http://localhost:3000/vite-dev/assets/sdk-CCw9CEvP.js: HTTP 200.
- Chatwoot Channel::WebWidget token lookup: found id 1, website_url http://localhost:8000.
- http://localhost:8080: HTTP 200.

## Update: Chatwoot Test Page CORS Fix

Date: 2026-07-09

Issue:
- Chatwoot SDK status stayed failed on public/chatwoot-test.html.
- The fallback SDK asset is an ES module and browser blocked it across localhost:8000 -> localhost:3000 because Chatwoot did not send Access-Control-Allow-Origin.

Changed:
- Added a local Laravel proxy route: /chatwoot-vite/assets/{asset}.
- The proxy serves Chatwoot Vite assets from http://127.0.0.1:3000/vite-dev/assets/{asset} through the CRM origin.
- Updated public/chatwoot-test.html to load /chatwoot-vite/assets/sdk-CCw9CEvP.js as a module.
- Status now reports loaded via local proxy when SDK initializes.

Verification:
- /chatwoot-vite/assets/sdk-CCw9CEvP.js: HTTP 200.
- /chatwoot-vite/assets/js.cookie-Cz0CWeBA.js: HTTP 200.
- /chatwoot-test.html: HTTP 200.
- php -l routes/web.php: passed.
- php artisan test: passed, 47 tests, 237 assertions.
- php artisan optimize:clear: passed.

## Update: Demo Readiness Acceptance Pass

Date: 2026-07-13

Changed:
- Completed local demo readiness verification across Chatwoot sync, AI draft, AI handoff, CRM workspace links, Knowledge Base context and final acceptance commands.
- Fixed CRM workspace task visibility so the CRM task board receives all tasks, including done tasks, instead of only open/in-progress tasks.
- Added missing frontend data fields used by the CRM workspace: `Lead.customer_id` and `Task.lead_id`.
- Added/verified an indexed demo FAQ document for AI Knowledge Base context.
- Restored demo task statuses so the task board visibly covers open, in progress and done columns.

Verification:
- Chatwoot-linked CRM chain verified: Customer#4 -> Lead#4 -> Conversation#4 -> CrmTask#4.
- AI handoff verified with AiRun#15, confidence 65, next_action handoff_operator, handoff_required true.
- Knowledge Base verified with KnowledgeDocument#1, status indexed, chunks_count 1, included in Dify knowledge_context.
- npm run build: passed.
- php artisan test: passed, 47 tests, 237 assertions.
- php artisan optimize:clear: passed.
