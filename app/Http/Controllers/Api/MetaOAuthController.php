<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Company;
use App\Models\Tenant;
use App\Support\Integrations\MetaChannelResolver;
use App\Support\Integrations\TenantIntegrationSettings;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * "Continue with Facebook" / "Continue with Instagram" OAuth connect flow —
 * replaces the manual copy-a-token-from-developers.facebook.com onboarding
 * (IntegrationSettingsController::update() still accepts a pasted token
 * directly, unchanged, as a fallback/advanced option). A tenant clicks a
 * button, logs into their own Facebook/Instagram, grants access, and lands
 * back here — they never see or handle a token themselves.
 *
 * Facebook (Page connection) and Instagram (its own separate "Instagram
 * Business Login") are two genuinely different OAuth flows against two
 * different authorization servers, not one flow with two scopes — see
 * InstagramClient's docblock for why graph.instagram.com/IGAA tokens from
 * Instagram's own login are not interchangeable with the legacy
 * Page-linked/graph.facebook.com approach this class deliberately does not use.
 *
 * Meta's redirect back after consent carries only what we put in `state` (or
 * the session tied to it) — never our own ?tenant_id= query convention — so
 * the *Start() actions run behind ResolveTenant (like every other tenant API
 * call) and stash {tenant_id, csrf nonce} in the session before redirecting
 * out; the *Callback() actions run without ResolveTenant and recover tenant
 * identity from that session state instead.
 *
 * NEEDS config('services.meta.app_id') / app_secret (env META_APP_ID /
 * META_APP_SECRET) to actually work, and the two callback URLs below
 * whitelisted in the Meta App dashboard (Facebook Login → Valid OAuth
 * Redirect URIs, and the Instagram product's own settings) — written to
 * Meta's documented flow but not live-tested end-to-end, since that requires
 * real app credentials only available in the dashboard.
 */
class MetaOAuthController extends Controller
{
    private const FACEBOOK_SCOPES = 'pages_show_list,pages_manage_metadata,pages_messaging';
    private const INSTAGRAM_SCOPES = 'instagram_business_basic,instagram_business_manage_messages';

    public function __construct(
        private readonly TenantIntegrationSettings $secrets,
        private readonly MetaChannelResolver $resolver,
    ) {
    }

    public function facebookStart(Request $request, TenantContext $context): RedirectResponse
    {
        $tenant = Tenant::query()->findOrFail($context->id());
        Gate::authorize('update', $tenant);

        $state = Str::random(40);
        $request->session()->put(['meta_oauth_tenant_id' => $tenant->id, 'meta_oauth_state' => $state]);

        return redirect('https://www.facebook.com/v21.0/dialog/oauth?'.http_build_query([
            'client_id' => config('services.meta.app_id'),
            'redirect_uri' => $this->facebookRedirectUri(),
            'state' => $state,
            'scope' => self::FACEBOOK_SCOPES,
            'response_type' => 'code',
        ]));
    }

    public function facebookCallback(Request $request): RedirectResponse
    {
        $tenant = $this->consumeOauthSession($request);

        if (! $tenant || $request->query('error')) {
            return $this->redirectToSettings('facebook', $tenant ? 'denied' : 'state_mismatch');
        }

        try {
            $shortLived = $this->exchangeCodeForToken(
                'https://graph.facebook.com/v21.0/oauth/access_token',
                (string) $request->query('code'),
                $this->facebookRedirectUri(),
            );
            $longLived = $this->exchangeForLongLivedUserToken($shortLived);
            $pages = Http::get('https://graph.facebook.com/v21.0/me/accounts', [
                'fields' => 'id,name,access_token',
                'access_token' => $longLived,
            ])->throw()->json('data', []);
        } catch (Throwable $error) {
            report($error);

            return $this->redirectToSettings('facebook', 'exchange_failed');
        }

        if ($pages === []) {
            return $this->redirectToSettings('facebook', 'no_pages');
        }

        if (count($pages) === 1) {
            try {
                $this->saveFacebookPage($tenant, $pages[0]);
            } catch (RuntimeException $error) {
                return $this->redirectToSettings('facebook', $error->getMessage());
            }

            return $this->redirectToSettings('facebook', 'connected');
        }

        // More than one Page — stash the candidates (with their own tokens) in the
        // session so the operator can pick one without hitting Meta a second time;
        // nothing is written to the tenant until they choose (facebookSelectPage()).
        $request->session()->put([
            'meta_oauth_facebook_pages' => $pages,
            'meta_oauth_facebook_tenant_id' => $tenant->id,
        ]);

        return $this->redirectToSettings('facebook', 'select_page');
    }

    public function facebookPages(Request $request): JsonResponse
    {
        $pages = $request->session()->get('meta_oauth_facebook_pages', []);

        return response()->json(['pages' => array_map(fn (array $page) => Arr::only($page, ['id', 'name']), $pages)]);
    }

    public function facebookSelectPage(Request $request): JsonResponse
    {
        $data = $request->validate(['page_id' => ['required', 'string']]);

        $tenantId = $request->session()->get('meta_oauth_facebook_tenant_id');
        $pages = $request->session()->get('meta_oauth_facebook_pages', []);
        $tenant = $tenantId ? Tenant::query()->find($tenantId) : null;
        $page = collect($pages)->firstWhere('id', $data['page_id']);

        if (! $tenant || ! $page) {
            abort(422, 'Сессия подключения истекла — начните заново.');
        }

        Gate::authorize('update', $tenant);

        try {
            $this->saveFacebookPage($tenant, $page);
        } catch (RuntimeException $error) {
            abort(422, 'Эта страница уже подключена к другой компании на платформе.');
        }

        $request->session()->forget(['meta_oauth_facebook_pages', 'meta_oauth_facebook_tenant_id']);

        return response()->json(['ok' => true]);
    }

    public function instagramStart(Request $request, TenantContext $context): RedirectResponse
    {
        $tenant = Tenant::query()->findOrFail($context->id());
        Gate::authorize('update', $tenant);

        $state = Str::random(40);
        $request->session()->put(['meta_oauth_tenant_id' => $tenant->id, 'meta_oauth_state' => $state]);

        return redirect('https://www.instagram.com/oauth/authorize?'.http_build_query([
            'client_id' => config('services.meta.instagram_app_id'),
            'redirect_uri' => $this->instagramRedirectUri(),
            'state' => $state,
            'scope' => self::INSTAGRAM_SCOPES,
            'response_type' => 'code',
        ]));
    }

    public function instagramCallback(Request $request): RedirectResponse
    {
        $tenant = $this->consumeOauthSession($request);

        if (! $tenant || $request->query('error')) {
            return $this->redirectToSettings('instagram', $tenant ? 'denied' : 'state_mismatch');
        }

        try {
            // Instagram Business Login's own token endpoint (api.instagram.com),
            // distinct from Facebook's — form-encoded POST, not the query-string GET
            // Facebook's oauth/access_token uses.
            $shortLivedResponse = Http::asForm()->post('https://api.instagram.com/oauth/access_token', [
                'client_id' => config('services.meta.instagram_app_id'),
                'client_secret' => config('services.meta.instagram_app_secret'),
                'grant_type' => 'authorization_code',
                'redirect_uri' => $this->instagramRedirectUri(),
                'code' => (string) $request->query('code'),
            ])->throw()->json();

            $shortLivedToken = (string) Arr::get($shortLivedResponse, 'access_token', '');
            $userId = (string) Arr::get($shortLivedResponse, 'user_id', '');

            $longLivedResponse = Http::get('https://graph.instagram.com/access_token', [
                'grant_type' => 'ig_exchange_token',
                'client_secret' => config('services.meta.instagram_app_secret'),
                'access_token' => $shortLivedToken,
            ])->throw()->json();

            $longLivedToken = (string) Arr::get($longLivedResponse, 'access_token', '');

            $profile = Http::get('https://graph.instagram.com/v21.0/'.$userId, [
                'fields' => 'username',
                'access_token' => $longLivedToken,
            ])->throw()->json();
        } catch (Throwable $error) {
            report($error);

            return $this->redirectToSettings('instagram', 'exchange_failed');
        }

        try {
            $this->saveInstagramAccount($tenant, $userId, $longLivedToken);
        } catch (RuntimeException $error) {
            return $this->redirectToSettings('instagram', $error->getMessage());
        }

        return $this->redirectToSettings('instagram', 'connected', ['username' => Arr::get($profile, 'username')]);
    }

    /**
     * Real accounts can't cross-connect by accident — Instagram/WhatsApp/Facebook
     * login always requires that business's own credentials, a clothing shop's
     * operator simply cannot authenticate as a toy shop's account. This guards
     * the one case that's still possible: an agency (or anyone) with genuine
     * admin access to more than one business's Page picks the wrong one here.
     * Without this, MetaChannelResolver would end up with two tenants both
     * claiming the same page_id — whichever tenant it resolves to (effectively
     * whichever connected first) would silently receive the other's messages.
     */
    private function assertNotClaimedElsewhere(Tenant $tenant, ?Tenant $owner): void
    {
        if ($owner && $owner->id !== $tenant->id) {
            throw new RuntimeException('already_connected');
        }
    }

    /**
     * Same read-modify-write-under-lock shape as IntegrationSettingsController::
     * update() (see its own comment on why: settings is one JSON blob more than
     * one request can touch concurrently) — duplicated rather than shared because
     * that method is built around validated $request input, not an OAuth result.
     */
    private function saveFacebookPage(Tenant $tenant, array $page): void
    {
        $this->assertNotClaimedElsewhere($tenant, $this->resolver->byFacebookPageId($page['id']));

        DB::transaction(function () use ($tenant, $page): void {
            $locked = Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
            $settings = $locked->settings ?? [];

            Arr::set($settings, 'integrations.facebook.page_id', $page['id']);
            Arr::set($settings, 'integrations.facebook.page_access_token', $this->secrets->encrypt($page['access_token']));

            $locked->forceFill(['settings' => $settings])->save();
        });

        try {
            Http::post('https://graph.facebook.com/v21.0/'.$page['id'].'/subscribed_apps', [
                'subscribed_fields' => 'messages,messaging_postbacks',
                'access_token' => $page['access_token'],
            ])->throw();
        } catch (Throwable $error) {
            // Credentials are already saved either way — a failed subscribe call just
            // means webhook events won't arrive until this is retried (e.g. re-running
            // the "Проверить" test), not a reason to lose the connection itself.
            report($error);
        }

        $this->markChannelConnected($tenant, 'facebook', 'Facebook Messenger');
    }

    private function saveInstagramAccount(Tenant $tenant, string $accountId, string $token): void
    {
        $this->assertNotClaimedElsewhere($tenant, $this->resolver->byInstagramBusinessAccountId($accountId));

        DB::transaction(function () use ($tenant, $accountId, $token): void {
            $locked = Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
            $settings = $locked->settings ?? [];

            Arr::set($settings, 'integrations.instagram.business_account_id', $accountId);
            Arr::set($settings, 'integrations.instagram.page_access_token', $this->secrets->encrypt($token));

            $locked->forceFill(['settings' => $settings])->save();
        });

        try {
            Http::post('https://graph.instagram.com/v21.0/'.$accountId.'/subscribed_apps', [
                'subscribed_fields' => 'messages',
                'access_token' => $token,
            ])->throw();
        } catch (Throwable $error) {
            report($error);
        }

        $this->markChannelConnected($tenant, 'instagram', 'Instagram Direct');
    }

    /** Mirrors IntegrationSettingsController::markChannelConnected() — same shape, private to each controller since Channel-upsert-on-successful-connect is a one-liner, not worth sharing across a service. */
    private function markChannelConnected(Tenant $tenant, string $provider, string $name): void
    {
        $company = Company::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();

        if (! $company) {
            return;
        }

        Channel::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'company_id' => $company->id, 'provider' => $provider],
            ['name' => $name, 'status' => 'connected', 'last_synced_at' => now()]
        );
    }

    private function exchangeCodeForToken(string $url, string $code, string $redirectUri): string
    {
        $response = Http::get($url, [
            'client_id' => config('services.meta.app_id'),
            'client_secret' => config('services.meta.app_secret'),
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ])->throw()->json();

        $token = (string) Arr::get($response, 'access_token', '');

        if ($token === '') {
            throw new RuntimeException('Meta did not return an access_token.');
        }

        return $token;
    }

    private function exchangeForLongLivedUserToken(string $shortLivedToken): string
    {
        $response = Http::get('https://graph.facebook.com/v21.0/oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => config('services.meta.app_id'),
            'client_secret' => config('services.meta.app_secret'),
            'fb_exchange_token' => $shortLivedToken,
        ])->throw()->json();

        return (string) Arr::get($response, 'access_token', $shortLivedToken);
    }

    private function consumeOauthSession(Request $request): ?Tenant
    {
        $tenantId = $request->session()->pull('meta_oauth_tenant_id');
        $expectedState = $request->session()->pull('meta_oauth_state');

        if (! $tenantId || ! $expectedState || ! hash_equals((string) $expectedState, (string) $request->query('state'))) {
            return null;
        }

        return Tenant::query()->find($tenantId);
    }

    private function facebookRedirectUri(): string
    {
        return url('/api/oauth/facebook/callback');
    }

    private function instagramRedirectUri(): string
    {
        return url('/api/oauth/instagram/callback');
    }

    private function redirectToSettings(string $provider, string $status, array $extra = []): RedirectResponse
    {
        return redirect('/integrations?'.http_build_query(['meta_oauth' => $provider, 'status' => $status] + $extra));
    }
}
