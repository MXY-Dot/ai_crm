# Inertia Pages and Working Actions Design

## Goal

Replace the dashboard's client-side component switcher with explicit Laravel/Inertia pages and make every visible interactive control perform its intended action across the public and authenticated UI.

## Scope

The audit and implementation cover:

- `/`
- `/login`
- `/register`
- `/app`
- `/inbox`
- `/leads`
- `/customers`
- `/crm`
- `/ai`
- `/knowledge`
- `/analytics`
- `/integrations`
- `/settings`

Production deployment to `ai-crm-server` is included after local verification and a GitHub push.

## Architecture

### Explicit Inertia routes

`routes/web.php` will define a named route for every page. The authenticated routes will render their corresponding Inertia page directly:

| URL | Inertia page |
| --- | --- |
| `/app` | `OverviewPage` |
| `/inbox` | `InboxPage` |
| `/leads` | `LeadsPage` |
| `/customers` | `CustomerProfilePage` |
| `/crm` | `CrmPage` |
| `/ai` | `AiPage` |
| `/knowledge` | `KnowledgePage` |
| `/analytics` | `AnalyticsPage` |
| `/integrations` | `IntegrationsPage` |
| `/settings` | `SettingsPage` |

The public home, login, and registration routes will render dedicated Inertia pages instead of passing an authentication mode into `Dashboard.vue`.

The generic `/{page}` route and the `currentPage` component switcher will be removed.

### Shared authenticated layout

Authenticated pages will use one persistent `AppLayout.vue`. It owns:

- desktop and mobile navigation;
- the authenticated header;
- tenant and user identity;
- logout;
- dashboard polling;
- toast notifications;
- hydration of the existing Pinia dashboard store from Inertia props.

The active navigation entry will be derived from the current route instead of being an independent source of truth in the Pinia store.

The existing `DashboardData` bootstrap payload remains shared by every authenticated route. Splitting it into page-specific backend queries is outside this change because it is not required to restore correctness.

### Thin page boundaries

Existing feature components remain in place. New pages such as `InboxPage.vue` and `AiPage.vue` will be thin Inertia boundaries that render the existing `InboxWorkspace` and `AiWorkspace` features through the shared layout.

No new UI kit, router, state library, or application abstraction will be introduced.

## Interaction behavior

### Button defaults

The shared UI `Button` component will default to native `type="button"`. Every intentional form submission will retain an explicit `type="submit"`. This prevents test, copy, theme, status, and navigation controls inside forms from accidentally submitting their parent form.

### Navigation actions

Navigation uses Inertia `Link` or `router.visit`.

The missing `openLead`, `openCustomer`, and `openConversation` behavior will be implemented at the shared store/navigation boundary. Each action selects the requested record and visits the page that displays it.

### Leads

The decorative Filters, New Lead, and Add Lead controls will become functional:

- Filters changes the visible lead status;
- New Lead opens a creation form;
- Add Lead opens the same form with the relevant pipeline status;
- successful creation refreshes the dashboard data and closes or resets the form;
- validation failures remain visible.

### Integrations

The integrations form will:

- load saved workspace settings;
- save Dify timeout, handoff threshold, and optional API key;
- save Chatwoot account ID, optional API token, optional webhook secret, and auto-reply state;
- save Telegram optional bot token, optional webhook secret, and auto-reply state;
- preserve existing secrets when their password inputs are blank;
- keep connection-test buttons separate from form submission;
- show success and validation/error feedback;
- reload the saved response into the form after a successful request.

### Remaining controls

Every button, button-styled link, form submission, selection control, and copy/open action in the scoped routes will be inventoried.

Each visible interactive element must satisfy one of these outcomes:

- navigate to a valid route;
- submit a valid form;
- mutate local UI state;
- issue its intended API request;
- copy or open the displayed resource;
- be removed or restyled as non-interactive when no product action exists.

## Error handling

- API validation errors will expose useful Laravel validation messages rather than only a generic failure.
- Failed mutations will retain form data and show an error toast or inline error.
- Successful mutations will show a success toast.
- Controls will be disabled while their request is in flight when duplicate submissions would be unsafe.
- External integration connection failures will be displayed as test results and will not overwrite saved settings.

## Testing

### Backend and routing

PHPUnit feature tests will verify:

- guests cannot open authenticated pages;
- every authenticated URL renders its exact Inertia page component;
- unknown top-level routes still return 404;
- integrations save and return all supported non-secret fields;
- blank secret inputs preserve encrypted saved secrets;
- auto-reply flags persist for Chatwoot and Telegram.

### Frontend build and static audit

- The Vite production build must pass.
- A button/action inventory will identify controls without an event, link, or form-submit role.
- Type errors surfaced by the production build must be fixed.

### Rendered browser verification

Playwright will be added as a development dependency because no browser automation dependency currently exists and the requested all-controls audit requires rendered interaction checks.

The browser suite will:

- create or use an isolated local test workspace;
- verify all public and authenticated routes;
- exercise navigation on desktop and mobile;
- submit representative create/update forms;
- verify the `/integrations` load-save-reload cycle;
- exercise non-destructive buttons on every page;
- assert that the expected URL, visible state, toast, modal, copied value, or API request occurs;
- fail on relevant browser console errors and framework overlays.

External Chatwoot, Dify, Telegram, and Google calls will be mocked or limited to explicit connection-failure handling in local automation. Real production credentials will not be placed in tests.

## Deployment

1. Run the complete PHP test suite, production frontend build, static button audit, and Playwright suite locally.
2. Confirm the worktree contains only intended changes.
3. Commit the implementation and push `main` to `https://github.com/MXY-Dot/ai_crm.git`.
4. Create a fresh production backup of the AI CRM database, environment, and deployment configuration.
5. Confirm the production worktree is clean and run `git pull --ff-only`.
6. Install exact locked PHP and Node dependencies required for the production build.
7. Build frontend assets, refresh Laravel caches, and reload only affected runtime services.
8. Verify HTTPS routes, authentication redirects, static assets, and read-only application health.

Production browser checks will not create, update, or delete business records.

## Success criteria

- No `currentPage` or equivalent manual page-component switch remains.
- Every scoped URL has a direct Laravel/Inertia route and page.
- `/integrations` saves and reloads all supported settings.
- Every visible interactive control in scope has verified behavior or is no longer presented as interactive.
- PHPUnit, the production build, the static action audit, and Playwright all pass.
- GitHub `main` contains the verified commit.
- Production is updated by a fast-forward pull and passes HTTPS smoke checks.
