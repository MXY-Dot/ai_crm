# Routed Inertia Pages and Working Actions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the monolithic dashboard view switcher with explicit Laravel/Inertia pages and make every rendered action on the public and authenticated AI CRM screens perform its intended operation.

**Architecture:** Laravel `web.php` routes render one concrete Inertia page per URL. Authenticated pages use one persistent `AppLayout` that hydrates the existing Pinia dashboard store from `DashboardData`; public landing and auth pages remain layout-free. Existing feature components and API endpoints stay in place, while missing navigation/actions are added at their shared source and verified by feature tests, a small source audit, and Playwright browser flows.

**Tech Stack:** Laravel 12, Inertia.js 3, Vue 3, Pinia, TypeScript, Vite 8, PHPUnit, Playwright.

## Global Constraints

- Keep `app/Support/Dashboard/DashboardData.php` as the single authenticated bootstrap provider.
- Use only existing components under `resources/js/components/ui/*`; do not add another UI kit.
- Use Inertia `<Link>` or `router.visit()` for internal navigation.
- The current URL is the source of truth for the active authenticated page.
- Shared `Button` instances default to native `type="button"`; genuine form submissions set `type="submit"` explicitly.
- Do not expose `.env`, API keys, tokens, or encrypted integration credentials in tests, logs, screenshots, or deployment output.
- Browser tests must intercept integration connection checks and must not call real Chatwoot, Dify, or Telegram services.
- Preserve tenant scoping and the existing integration settings API.
- Keep the implementation minimal: reuse current page and feature components, and delete the manual `currentPage` switcher.
- Before production deployment, require a clean server worktree, a backup, `git pull --ff-only`, locked dependency installation, a production asset build, Laravel cache refresh, service reload, and HTTPS smoke tests.

---

### Task 1: Make Laravel Render Concrete Inertia Pages

**Files:**
- Modify: `tests/Feature/AuthFlowTest.php`
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/Auth/SessionController.php:20-50`
- Create: `resources/js/pages/HomePage.vue`
- Create: `resources/js/pages/LoginPage.vue`
- Create: `resources/js/pages/RegisterPage.vue`
- Create: `resources/js/pages/InboxPage.vue`
- Create: `resources/js/pages/AiPage.vue`

**Interfaces:**
- Consumes: `DashboardData::forUser(User $user): array`, `LandingScreen`, `LoginScreen`, `InboxWorkspace`, and `AiWorkspace`.
- Produces: concrete Inertia components `HomePage`, `LoginPage`, `RegisterPage`, `OverviewPage`, `InboxPage`, `LeadsPage`, `CustomerProfilePage`, `CrmPage`, `AiPage`, `KnowledgePage`, `AnalyticsPage`, `IntegrationsPage`, and `SettingsPage`.

- [ ] **Step 1: Replace the generic dashboard route assertion with exact component assertions**

In `tests/Feature/AuthFlowTest.php`, add a route-to-component data set and assert each authenticated URL renders its own page:

```php
public static function dashboardPages(): array
{
    return [
        ['/app', 'OverviewPage'],
        ['/inbox', 'InboxPage'],
        ['/leads', 'LeadsPage'],
        ['/customers', 'CustomerProfilePage'],
        ['/crm', 'CrmPage'],
        ['/ai', 'AiPage'],
        ['/knowledge', 'KnowledgePage'],
        ['/analytics', 'AnalyticsPage'],
        ['/integrations', 'IntegrationsPage'],
        ['/settings', 'SettingsPage'],
    ];
}

#[DataProvider('dashboardPages')]
public function test_authenticated_user_can_open_each_concrete_inertia_page(
    string $uri,
    string $component,
): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get($uri)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component($component)
            ->has('bootstrap'));
}
```

Also assert `/`, `/login`, and `/register` render `HomePage`, `LoginPage`, and `RegisterPage` for guests.

- [ ] **Step 2: Run the route tests and verify RED**

Run:

```powershell
php artisan test tests/Feature/AuthFlowTest.php
```

Expected: FAIL because the routes and auth controller still render `Dashboard`.

- [ ] **Step 3: Replace the catch-all route with explicit routes**

In `routes/web.php`, keep the existing auth middleware and reuse one closure that only builds shared props:

```php
$dashboardPage = static fn (
    Request $request,
    DashboardData $dashboard,
    string $component,
) => Inertia::render($component, [
    'bootstrap' => $dashboard->forUser($request->user()),
]);

Route::get('/', fn () => Inertia::render('HomePage'))->name('home');

Route::middleware('auth')->group(function () use ($dashboardPage): void {
    Route::get('/app', fn (Request $request, DashboardData $dashboard) =>
        $dashboardPage($request, $dashboard, 'OverviewPage'))->name('dashboard');
    Route::get('/inbox', fn (Request $request, DashboardData $dashboard) =>
        $dashboardPage($request, $dashboard, 'InboxPage'))->name('inbox');
    Route::get('/leads', fn (Request $request, DashboardData $dashboard) =>
        $dashboardPage($request, $dashboard, 'LeadsPage'))->name('leads');
    Route::get('/customers', fn (Request $request, DashboardData $dashboard) =>
        $dashboardPage($request, $dashboard, 'CustomerProfilePage'))->name('customers');
    Route::get('/crm', fn (Request $request, DashboardData $dashboard) =>
        $dashboardPage($request, $dashboard, 'CrmPage'))->name('crm');
    Route::get('/ai', fn (Request $request, DashboardData $dashboard) =>
        $dashboardPage($request, $dashboard, 'AiPage'))->name('ai');
    Route::get('/knowledge', fn (Request $request, DashboardData $dashboard) =>
        $dashboardPage($request, $dashboard, 'KnowledgePage'))->name('knowledge');
    Route::get('/analytics', fn (Request $request, DashboardData $dashboard) =>
        $dashboardPage($request, $dashboard, 'AnalyticsPage'))->name('analytics');
    Route::get('/integrations', fn (Request $request, DashboardData $dashboard) =>
        $dashboardPage($request, $dashboard, 'IntegrationsPage'))->name('integrations');
    Route::get('/settings', fn (Request $request, DashboardData $dashboard) =>
        $dashboardPage($request, $dashboard, 'SettingsPage'))->name('settings');
});
```

Delete the generic `/{page}` dashboard route and its `whereIn` list.

- [ ] **Step 4: Render dedicated auth components**

Change `SessionController::create()` to render `LoginPage` with `login` and `plan` props, and `SessionController::register()` to render `RegisterPage` with `plan`:

```php
return Inertia::render('LoginPage', [
    'login' => ['email' => 'owner@gravity.test', 'password' => 'password'],
    'plan' => request('plan', 'starter'),
]);
```

```php
return Inertia::render('RegisterPage', [
    'plan' => request('plan', 'starter'),
]);
```

`HomePage.vue`, `LoginPage.vue`, and `RegisterPage.vue` should be thin wrappers around the existing public/auth components. Modify `LoginScreen.vue` only as needed to accept an explicit `mode: 'login' | 'register'`, `login`, and `plan` instead of reading `store.authMode`.

`InboxPage.vue` and `AiPage.vue` should only render the existing feature roots:

```vue
<script setup lang="ts">
import InboxWorkspace from '@/components/dashboard/InboxWorkspace.vue'
</script>

<template>
    <InboxWorkspace />
</template>
```

- [ ] **Step 5: Run the focused and complete PHP test suites**

Run:

```powershell
php artisan test tests/Feature/AuthFlowTest.php
php artisan test
```

Expected: PASS, including a 404 for an unknown dashboard-like URL.

- [ ] **Step 6: Commit the routed pages**

```powershell
git add routes/web.php app/Http/Controllers/Auth/SessionController.php tests/Feature/AuthFlowTest.php resources/js/pages resources/js/components/auth/LoginScreen.vue
git commit -m "refactor: route each Inertia page directly"
```

---

### Task 2: Move Shared Dashboard Behavior into a Persistent Layout

**Files:**
- Create: `resources/js/layouts/AppLayout.vue`
- Modify: `resources/js/pages/OverviewPage.vue`
- Modify: `resources/js/pages/InboxPage.vue`
- Modify: `resources/js/pages/LeadsPage.vue`
- Modify: `resources/js/pages/CustomerProfilePage.vue`
- Modify: `resources/js/pages/CrmPage.vue`
- Modify: `resources/js/pages/AiPage.vue`
- Modify: `resources/js/pages/KnowledgePage.vue`
- Modify: `resources/js/pages/AnalyticsPage.vue`
- Modify: `resources/js/pages/IntegrationsPage.vue`
- Modify: `resources/js/pages/SettingsPage.vue`
- Modify: `resources/js/lib/pages.ts`
- Modify: `resources/js/stores/crmDashboard.ts:306-354`
- Delete: `resources/js/pages/Dashboard.vue`

**Interfaces:**
- Consumes: each authenticated page's `bootstrap` Inertia prop and existing `AppSidebar`, `MobileNav`, toast, theme, locale, refresh, polling, and logout behavior from `Dashboard.vue`.
- Produces: `AppLayout.vue`; `pageFromPath(pathname: string): DashboardPage`; `hydrateBootstrap(data: DashboardBootstrap): void`.

- [ ] **Step 1: Add a failing path-mapping check**

Create `tests/ui/pages.test.ts` using Node's built-in test runner:

```ts
import assert from 'node:assert/strict'
import test from 'node:test'
import { pageFromPath } from '../../resources/js/lib/pages'

test('maps routed URLs to dashboard page ids', () => {
    assert.equal(pageFromPath('/app'), 'overview')
    assert.equal(pageFromPath('/integrations'), 'integrations')
    assert.equal(pageFromPath('/unknown'), 'overview')
})
```

Add `"test:ui": "node --import tsx --test tests/ui/*.test.ts"` and the minimum `tsx` development dependency only if TypeScript cannot be imported by the installed Node runtime.

- [ ] **Step 2: Run the check and verify RED**

Run:

```powershell
npm run test:ui
```

Expected: FAIL because `pageFromPath` does not exist.

- [ ] **Step 3: Add the inverse route lookup**

In `resources/js/lib/pages.ts`, keep the existing `pagePaths` and add:

```ts
export function pageFromPath(pathname: string): DashboardPage {
    return (Object.entries(pagePaths).find(([, path]) => path === pathname)?.[0]
        ?? 'overview') as DashboardPage
}
```

Run `npm run test:ui`; expected PASS.

- [ ] **Step 4: Extract the authenticated shell**

Move the authenticated shell only from `Dashboard.vue` into `AppLayout.vue`:

```vue
<script setup lang="ts">
import { computed, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import AppSidebar from '@/components/dashboard/AppSidebar.vue'
import MobileNav from '@/components/dashboard/MobileNav.vue'
import { pageFromPath } from '@/lib/pages'
import { useCrmDashboardStore } from '@/stores/crmDashboard'

const page = usePage<{ bootstrap: DashboardBootstrap }>()
const store = useCrmDashboardStore()
const activePage = computed(() => pageFromPath(new URL(page.url, window.location.origin).pathname))

watch(
    () => page.props.bootstrap,
    (bootstrap) => bootstrap && store.hydrateBootstrap(bootstrap),
    { immediate: true },
)
</script>

<template>
    <div class="min-h-screen">
        <AppSidebar :active-page="activePage" />
        <main>
            <slot />
        </main>
        <MobileNav :active-page="activePage" />
    </div>
</template>
```

Carry over the existing polling, header, theme, locale, help panel, toast display, and logout behavior without changing their behavior. Do not move landing or auth mode branches into this layout.

- [ ] **Step 5: Assign the persistent layout to every authenticated page**

Add the same layout declaration to each of the ten authenticated pages:

```vue
<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'

defineOptions({ layout: AppLayout })
</script>
```

Merge it into existing `<script setup>` blocks instead of adding a second block.

- [ ] **Step 6: Remove manual page state**

Change `hydrateBootstrap(data, page)` to `hydrateBootstrap(data)`, remove `activeView` as a navigation source, remove `currentPage`, `authMode`, and dynamic `<component :is="currentPage">` code, then delete `Dashboard.vue`. Update all callers to derive navigation from the Inertia URL.

- [ ] **Step 7: Verify layout behavior**

Run:

```powershell
npm run test:ui
npm run build
php artisan test tests/Feature/AuthFlowTest.php
```

Expected: all PASS and Vite reports no unresolved `Dashboard.vue` import.

- [ ] **Step 8: Commit the persistent layout**

```powershell
git add resources/js/layouts resources/js/pages resources/js/lib/pages.ts resources/js/stores/crmDashboard.ts tests/ui package.json package-lock.json
git commit -m "refactor: move dashboard shell to Inertia layout"
```

---

### Task 3: Fix Shared Button Semantics and Cross-Page Navigation

**Files:**
- Modify: `resources/js/components/ui/button/Button.vue`
- Modify: `resources/js/stores/crmDashboard.ts`
- Modify: `resources/js/components/dashboard/AiHandoffCenter.vue`
- Modify: `resources/js/components/dashboard/inbox/ConversationInfo.vue`
- Modify: `resources/js/components/dashboard/CustomerList.vue`
- Modify: `resources/js/components/dashboard/LeadPipeline.vue`
- Modify: `tests/ui/pages.test.ts`

**Interfaces:**
- Consumes: `pagePaths`, Pinia selection state, and Inertia `router.visit`.
- Produces: `openLead(id: number): void`, `openCustomer(id: number): void`, and `openConversation(id: number): void`.

- [ ] **Step 1: Add failing navigation target tests**

Export a small pure route helper from `pages.ts` and test it:

```ts
export function pathForRecord(
    kind: 'lead' | 'customer' | 'conversation',
): string
```

```ts
test('maps record kinds to routed workspaces', () => {
    assert.equal(pathForRecord('lead'), '/leads')
    assert.equal(pathForRecord('customer'), '/customers')
    assert.equal(pathForRecord('conversation'), '/inbox')
})
```

- [ ] **Step 2: Run the check and verify RED**

Run `npm run test:ui`.

Expected: FAIL because `pathForRecord` does not exist.

- [ ] **Step 3: Implement record navigation once in the store**

Implement `pathForRecord` with the existing `pagePaths`, then add store methods that set the relevant selected ID and call `router.visit(pathForRecord(kind), { preserveState: true })`:

```ts
function openConversation(id: number): void {
    selectedConversationId.value = id
    router.visit(pathForRecord('conversation'), { preserveState: true })
}
```

Use the existing selected lead/customer refs for the other methods. Keep callers in `AiHandoffCenter`, `ConversationInfo`, `CustomerList`, and `LeadPipeline` pointed at these store actions instead of duplicating route logic.

- [ ] **Step 4: Make shared buttons safe inside forms**

In `Button.vue`, pass `type="button"` when the rendered primitive is a native button and no explicit type was supplied. Preserve `as`, `asChild`, disabled, and variant behavior. Every genuine form submission in the codebase must explicitly use:

```vue
<Button type="submit">Save</Button>
```

- [ ] **Step 5: Run unit, build, and PHP checks**

Run:

```powershell
npm run test:ui
npm run build
php artisan test
```

Expected: all PASS, with no Vue prop or native button warnings.

- [ ] **Step 6: Commit shared interaction fixes**

```powershell
git add resources/js/components/ui/button/Button.vue resources/js/lib/pages.ts resources/js/stores/crmDashboard.ts resources/js/components/dashboard tests/ui/pages.test.ts
git commit -m "fix: wire shared dashboard navigation actions"
```

---

### Task 4: Make Leads Controls Perform Real Actions

**Files:**
- Modify: `resources/js/pages/LeadsPage.vue`
- Modify: `resources/js/components/dashboard/LeadPipeline.vue`
- Modify: `resources/js/components/dashboard/CrmQuickForms.vue`
- Test: `tests/Feature/TenantIsolationTest.php`

**Interfaces:**
- Consumes: the existing lead create/update API actions and existing lead pipeline data in the Pinia store.
- Produces: working search/filter state, a visible lead creation form, and opening of the selected lead.

- [ ] **Step 1: Add or tighten the existing lead mutation feature test**

In `TenantIsolationTest.php`, add a tenant-authenticated test that creates a lead through the existing API and verifies it appears only for its tenant. Use the current endpoint and payload from `CrmQuickForms.vue`; do not create a second backend endpoint.

- [ ] **Step 2: Run the focused test and verify its current state**

Run:

```powershell
php artisan test tests/Feature/TenantIsolationTest.php --filter=lead
```

Expected: PASS for the backend contract; the missing behavior is client wiring.

- [ ] **Step 3: Wire the page controls**

In `LeadsPage.vue`:

- bind the search input to a local `query`;
- make “Filters” toggle a compact native filter row for stage/status using existing lead fields;
- make “New lead” and the empty-state “Add lead” open the existing `CrmQuickForms` lead form;
- pass the filtered lead collection to `LeadPipeline`;
- use `store.openLead(id)` when a pipeline item is selected.

Use native inputs/selects and the existing UI components. Do not add a modal library.

- [ ] **Step 4: Verify the leads page compiles and backend behavior remains intact**

Run:

```powershell
npm run build
php artisan test tests/Feature/TenantIsolationTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit the leads controls**

```powershell
git add resources/js/pages/LeadsPage.vue resources/js/components/dashboard/LeadPipeline.vue resources/js/components/dashboard/CrmQuickForms.vue tests/Feature/TenantIsolationTest.php
git commit -m "fix: connect lead page controls"
```

---

### Task 5: Correct Integration Saving and Connection Checks

**Files:**
- Modify: `tests/Feature/IntegrationSettingsTest.php`
- Modify: `resources/js/components/dashboard/IntegrationSettingsPanel.vue`
- Modify: `resources/js/stores/crmDashboard.ts:507-525`
- Modify: `resources/js/pages/IntegrationsPage.vue`

**Interfaces:**
- Consumes: `PATCH /api/integration-settings` and the existing integration test endpoint.
- Produces: a complete Chatwoot/Dify/Telegram settings payload, distinct test buttons, visible pending/success/error states, and no accidental form submit.

- [ ] **Step 1: Add the missing auto-reply persistence assertion**

Extend the existing integration settings feature test:

```php
$response = $this->actingAs($owner)->patchJson('/api/integration-settings', [
    'chatwoot' => [
        'base_url' => 'https://chatwoot.test',
        'account_id' => '1',
        'inbox_id' => '2',
        'auto_reply_enabled' => true,
    ],
]);

$response->assertOk()
    ->assertJsonPath('settings.chatwoot.auto_reply_enabled', true);
```

Keep token assertions redacted/encrypted as in the current suite.

- [ ] **Step 2: Run the integration test and verify RED if the backend omits the field**

Run:

```powershell
php artisan test tests/Feature/IntegrationSettingsTest.php
```

Expected: either the new assertion fails at the backend boundary or passes and proves the bug is client-only. Do not change the controller when the contract already passes.

- [ ] **Step 3: Send every rendered integration field**

Update `IntegrationSettingsPanel.vue` save payload so Chatwoot includes:

```ts
chatwoot: {
    base_url: form.chatwoot.base_url,
    account_id: form.chatwoot.account_id,
    inbox_id: form.chatwoot.inbox_id,
    api_access_token: form.chatwoot.api_access_token,
    webhook_secret: form.chatwoot.webhook_secret,
    auto_reply_enabled: form.chatwoot.auto_reply_enabled,
}
```

Keep the existing Dify and Telegram values. Mark only the save control `type="submit"`; mark all connection-test controls `type="button"`.

- [ ] **Step 4: Separate save and connection-check states**

Disable the save button while `updateIntegrationSettings` is pending. Give Dify and Chatwoot test buttons independent pending state and surface the API message through the existing toast mechanism. On validation/network errors, retain form values and show the returned message; do not log secrets.

- [ ] **Step 5: Verify integration behavior**

Run:

```powershell
php artisan test tests/Feature/IntegrationSettingsTest.php
npm run build
```

Expected: PASS and no TypeScript/Vue build errors.

- [ ] **Step 6: Commit the integration fix**

```powershell
git add tests/Feature/IntegrationSettingsTest.php resources/js/components/dashboard/IntegrationSettingsPanel.vue resources/js/stores/crmDashboard.ts resources/js/pages/IntegrationsPage.vue
git commit -m "fix: persist and test integration settings"
```

---

### Task 6: Audit Every Rendered Control and Remove Dead UI

**Files:**
- Create: `tools/audit-ui-actions.js`
- Modify: `package.json`
- Modify: `package-lock.json`
- Modify: scoped `.vue` files under `resources/js/pages`, `resources/js/components/auth`, and `resources/js/components/dashboard`

**Interfaces:**
- Consumes: Vue source tags and the interaction contract from the approved design.
- Produces: `npm run audit:ui-actions`, which fails on native/shared button tags without a click, submit, link, menu-trigger, copy/open, or explicit non-interactive treatment.

- [ ] **Step 1: Create the failing source audit**

Implement `tools/audit-ui-actions.js` with Node `fs` and recursive directory traversal. For each `.vue` file, scan complete opening `<button ...>` and `<Button ...>` tags across lines. Allow a control only when the tag has one of:

```js
const actions = [
  '@click',
  '@submit',
  'type="submit"',
  'as-child',
  'asChild',
  'DropdownMenuTrigger',
]
```

Report `relative/path.vue:line` and the opening tag for every failure, then set `process.exitCode = 1`. Add:

```json
"audit:ui-actions": "node tools/audit-ui-actions.js"
```

- [ ] **Step 2: Run the audit and capture RED**

Run:

```powershell
npm run audit:ui-actions
```

Expected: FAIL and list the remaining dead controls, including decorative actions found on the scoped pages.

- [ ] **Step 3: Classify and fix each reported control**

For every reported item:

- route internal navigation with `<Link>`;
- call the existing store/API action for mutations;
- use `type="submit"` for form submissions;
- implement copy/open actions with `navigator.clipboard.writeText()` or `window.open(url, '_blank', 'noopener,noreferrer')`;
- remove a control that has no product behavior;
- restyle static status text as a badge instead of leaving it as a button.

Do not silence a finding by adding an empty handler. While touching the scoped templates, replace visibly corrupted mojibake strings with their intended Russian labels.

- [ ] **Step 4: Re-run the source audit and build**

Run:

```powershell
npm run audit:ui-actions
npm run build
```

Expected: PASS with zero unclassified controls and no compiler warnings.

- [ ] **Step 5: Run backend regression tests**

Run:

```powershell
php artisan test
```

Expected: PASS.

- [ ] **Step 6: Commit the UI action audit**

```powershell
git add tools/audit-ui-actions.js package.json package-lock.json resources/js/pages resources/js/components/auth resources/js/components/dashboard
git commit -m "fix: remove dead dashboard controls"
```

---

### Task 7: Verify Public, Routed, and Integration Flows in a Real Browser

**Files:**
- Modify: `package.json`
- Modify: `package-lock.json`
- Create: `playwright.config.ts`
- Create: `tests/e2e/app-actions.spec.ts`
- Modify: `.gitignore`

**Interfaces:**
- Consumes: the local app at `http://127.0.0.1:8000`, all 13 concrete routes, and browser-visible controls.
- Produces: `npm run test:e2e`; no real external integration requests.

- [ ] **Step 1: Add Playwright and a route smoke test**

Install the single development dependency:

```powershell
npm install --save-dev @playwright/test
```

Add scripts:

```json
"test:e2e": "playwright test",
"test:e2e:headed": "playwright test --headed"
```

Configure `baseURL: 'http://127.0.0.1:8000'`, `reuseExistingServer: true`, desktop Chromium, and a mobile Chromium project. Ignore `test-results/` and `playwright-report/`.

The first test must open `/`, `/login`, and `/register`, assert their main heading/form, and collect `pageerror` plus console `error` messages.

- [ ] **Step 2: Run the public smoke test and verify RED**

Run:

```powershell
npx playwright install chromium
npm run test:e2e -- --grep "public routes"
```

Expected: FAIL on any remaining selector, route, or console defect before the final browser wiring.

- [ ] **Step 3: Add one authenticated workflow**

Register a unique workspace through `/register`, then visit all ten authenticated routes and assert each route-specific heading. Reuse the same browser context/session.

For actionable controls:

- open sidebar and mobile navigation targets;
- create a lead and open its detail;
- create/open a customer when supported by the existing form;
- open a conversation and AI handoff when records exist;
- exercise knowledge/settings controls that mutate local tenant data;
- save integration settings;
- click Dify and Chatwoot connection-test buttons.

Intercept the integration test endpoint and respond with deterministic JSON:

```ts
await page.route('**/api/integration-settings/test', async route => {
    await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ ok: true, message: 'Connection successful' }),
    })
})
```

Never place production secrets in the test.

- [ ] **Step 4: Assert control and console quality**

On each route:

```ts
const buttons = page.getByRole('button')
for (let index = 0; index < await buttons.count(); index += 1) {
    const button = buttons.nth(index)
    await expect(button).toHaveAccessibleName()
}
```

After each intended click, assert its observable result: URL change, form visibility, success toast, updated text, copied value, or opened page. Fail the test on uncaught page errors and unexpected console errors.

- [ ] **Step 5: Run desktop and mobile suites**

Run:

```powershell
npm run test:e2e
```

Expected: PASS for desktop and mobile projects with no unexpected console errors. Keep screenshots/videos only on failure and outside tracked source.

- [ ] **Step 6: Run the complete local gate**

Run:

```powershell
php artisan test
npm run test:ui
npm run audit:ui-actions
npm run build
npm run test:e2e
git status --short
```

Expected: all checks PASS; only the intended implementation files are modified.

- [ ] **Step 7: Commit browser coverage**

```powershell
git add package.json package-lock.json playwright.config.ts tests/e2e .gitignore
git commit -m "test: cover routed CRM actions in browser"
```

---

### Task 8: Review, Publish, and Deploy Safely

**Files:**
- Review: all files changed since `9c2a827`
- Remote update: `/var/www/html/ai_crm`

**Interfaces:**
- Consumes: a clean, verified `main` branch and the existing production services.
- Produces: the tested commit on GitHub and the same commit deployed on `ai-crm-server`.

- [ ] **Step 1: Review the final diff**

Run:

```powershell
git status --short
git diff 9c2a827...HEAD --stat
git diff 9c2a827...HEAD --check
```

Inspect that no `.env`, credentials, Playwright artifacts, screenshots, or codebase-memory index churn are included.

- [ ] **Step 2: Run the final verification gate again**

Run:

```powershell
php artisan test
npm run test:ui
npm run audit:ui-actions
npm run build
npm run test:e2e
```

Expected: all PASS immediately before publishing.

- [ ] **Step 3: Push the exact tested branch**

Run:

```powershell
git push origin main
```

Expected: GitHub `main` advances to the locally tested commit.

- [ ] **Step 4: Audit production before mutation**

Use only the required SSH command form:

```powershell
ssh ai-crm-server "cd /var/www/html/ai_crm && git status --short && git branch --show-current && git rev-parse HEAD && git remote -v"
```

Expected: branch `main`, expected origin, and a clean worktree. Stop before deployment if the worktree is dirty.

- [ ] **Step 5: Create a recoverable production backup**

Create timestamped backups outside the Git worktree for:

- current commit hash;
- `.env` without printing it;
- database dump or SQLite file, based on the configured production driver;
- current Nginx AI CRM site config;
- current `public/build` directory.

Use server-side `cp`, `tar`, and the database-native dump command through:

```powershell
ssh ai-crm-server "COMMAND"
```

Record the backup directory path in the deployment notes without printing secrets.

- [ ] **Step 6: Pull and build the tested revision**

Run production commands only through:

```powershell
ssh ai-crm-server "cd /var/www/html/ai_crm && git pull --ff-only && composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader && npm ci && npm run build"
```

Do not run migrations unless `php artisan migrate:status` shows a pending migration introduced by the tested commit. This implementation should not require a schema change.

- [ ] **Step 7: Refresh Laravel and workers**

Run:

```powershell
ssh ai-crm-server "cd /var/www/html/ai_crm && php artisan optimize:clear && php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan queue:restart"
```

Reload only the already configured PHP-FPM/Nginx services if the production setup requires it.

- [ ] **Step 8: Smoke-test production by IP**

Verify read-only HTTP status and redirects for:

- `https://201.24.124.144/`
- `https://201.24.124.144/login`
- authenticated pages through a browser session;
- `https://201.24.124.144:3000` Chatwoot;
- `https://201.24.124.144:8080` Dify.

Confirm the deployed commit:

```powershell
ssh ai-crm-server "cd /var/www/html/ai_crm && git rev-parse HEAD && git status --short"
```

- [ ] **Step 9: Roll back if smoke tests fail**

Restore the recorded prior commit with a forward-safe checkout of that exact revision, restore `.env`, database, Nginx config, and `public/build` from the timestamped backup, then rebuild caches and restart queue workers. Do not use `git reset --hard` or `git clean`.

- [ ] **Step 10: Report the result**

Report:

- local and deployed commit hashes;
- PHP, UI audit, build, and Playwright results;
- production route status;
- whether Chatwoot and Dify IP endpoints still respond;
- backup path and whether rollback was needed;
- any remaining external limitation, especially browser trust warnings for an IP certificate.
