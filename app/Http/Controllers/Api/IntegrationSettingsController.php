<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiAgent;
use App\Models\Channel;
use App\Models\Company;
use App\Models\Tenant;
use App\Support\Audit\AuditLogger;
use App\Support\Integrations\TenantIntegrationSettings;
use App\Support\Tenancy\TenantContext;
use App\Support\TelegramClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class IntegrationSettingsController extends Controller
{
    public function __construct(
        private readonly TenantIntegrationSettings $secrets,
        private readonly TelegramClient $telegram,
    ) {
    }

    public function show(TenantContext $context): JsonResponse
    {
        $tenant = $this->tenant($context);
        Gate::authorize('view', $tenant);

        return response()->json($this->payload($tenant));
    }

    public function update(Request $request, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $tenant = $this->tenant($context);
        Gate::authorize('update', $tenant);

        $data = $request->validate([
            'dify.url' => ['nullable', 'url', 'max:255'],
            'dify.api_key' => ['nullable', 'string', 'max:255'],
            'dify.timeout' => ['nullable', 'integer', 'min:3', 'max:60'],
            'dify.handoff_threshold' => ['nullable', 'integer', 'min:1', 'max:100'],
            'chatwoot.url' => ['nullable', 'url', 'max:255'],
            'chatwoot.account_id' => ['nullable', 'integer', 'min:1'],
            'chatwoot.api_token' => ['nullable', 'string', 'max:255'],
            'chatwoot.webhook_secret' => ['nullable', 'string', 'max:255'],
            'chatwoot.auto_reply_mode' => ['nullable', Rule::in(['off', 'priority', 'always'])],
            'telegram.bot_token' => ['nullable', 'string', 'max:255'],
            'telegram.webhook_secret' => ['nullable', 'string', 'max:255'],
            'whatsapp.access_token' => ['nullable', 'string', 'max:255'],
            'whatsapp.phone_number_id' => ['nullable', 'string', 'max:64'],
            'whatsapp.business_account_id' => ['nullable', 'string', 'max:64'],
            'instagram.page_access_token' => ['nullable', 'string', 'max:255'],
            'instagram.business_account_id' => ['nullable', 'string', 'max:64'],
            'facebook.page_access_token' => ['nullable', 'string', 'max:255'],
            'facebook.page_id' => ['nullable', 'string', 'max:64'],
        ]);

        $oldAudit = $this->auditSettingsSnapshot($tenant);

        // `settings` is a single JSON blob that more than one controller reads,
        // merges into, and saves independently (see SuperAdminCompanyController::
        // updatePlan(), which does the same billing.plan merge on this same column).
        // Without a lock, two concurrent requests can both read the same "before"
        // snapshot and the second save silently overwrites whatever the first one
        // just added — this is exactly how a just-saved Telegram token could vanish
        // minutes later with no trace of anything explicitly deleting it. Locking the
        // row for the duration of the read-modify-write serializes concurrent writers
        // so each one always merges on top of the latest committed data.
        $telegramTokenNowSet = DB::transaction(function () use ($tenant, $data): bool {
            $locked = Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
            $settings = $locked->settings ?? [];

            foreach (['url', 'timeout', 'handoff_threshold'] as $key) {
                if (array_key_exists($key, $data['dify'] ?? [])) {
                    Arr::set($settings, 'integrations.dify.'.$key, $data['dify'][$key]);
                }
            }

            if ($this->hasFilledSecret($data['dify'] ?? [], 'api_key')) {
                Arr::set($settings, 'integrations.dify.api_key', $this->secrets->encrypt($data['dify']['api_key']));
            }

            foreach (['url', 'account_id', 'auto_reply_mode'] as $key) {
                if (array_key_exists($key, $data['chatwoot'] ?? [])) {
                    Arr::set($settings, 'integrations.chatwoot.'.$key, $data['chatwoot'][$key]);
                }
            }

            if ($this->hasFilledSecret($data['chatwoot'] ?? [], 'api_token')) {
                Arr::set($settings, 'integrations.chatwoot.api_token', $this->secrets->encrypt($data['chatwoot']['api_token']));
            }

            if ($this->hasFilledSecret($data['chatwoot'] ?? [], 'webhook_secret')) {
                Arr::set($settings, 'integrations.chatwoot.webhook_secret', $this->secrets->encrypt($data['chatwoot']['webhook_secret']));
            }

            if ($this->hasFilledSecret($data['telegram'] ?? [], 'bot_token')) {
                Arr::set($settings, 'integrations.telegram.bot_token', $this->secrets->encrypt($data['telegram']['bot_token']));
            }

            if ($this->hasFilledSecret($data['telegram'] ?? [], 'webhook_secret')) {
                Arr::set($settings, 'integrations.telegram.webhook_secret', $this->secrets->encrypt($data['telegram']['webhook_secret']));
            }

            $telegramTokenNowSet = $this->secrets->decrypt(Arr::get($settings, 'integrations.telegram.bot_token')) !== '';

            if ($telegramTokenNowSet && $this->secrets->decrypt(Arr::get($settings, 'integrations.telegram.webhook_secret')) === '') {
                Arr::set($settings, 'integrations.telegram.webhook_secret', $this->secrets->encrypt(Str::random(32)));
            }

            foreach (['phone_number_id', 'business_account_id'] as $key) {
                if (array_key_exists($key, $data['whatsapp'] ?? [])) {
                    Arr::set($settings, 'integrations.whatsapp.'.$key, $data['whatsapp'][$key]);
                }
            }

            if ($this->hasFilledSecret($data['whatsapp'] ?? [], 'access_token')) {
                Arr::set($settings, 'integrations.whatsapp.access_token', $this->secrets->encrypt($data['whatsapp']['access_token']));
            }

            if (array_key_exists('business_account_id', $data['instagram'] ?? [])) {
                Arr::set($settings, 'integrations.instagram.business_account_id', $data['instagram']['business_account_id']);
            }

            if ($this->hasFilledSecret($data['instagram'] ?? [], 'page_access_token')) {
                Arr::set($settings, 'integrations.instagram.page_access_token', $this->secrets->encrypt($data['instagram']['page_access_token']));
            }

            if (array_key_exists('page_id', $data['facebook'] ?? [])) {
                Arr::set($settings, 'integrations.facebook.page_id', $data['facebook']['page_id']);
            }

            if ($this->hasFilledSecret($data['facebook'] ?? [], 'page_access_token')) {
                Arr::set($settings, 'integrations.facebook.page_access_token', $this->secrets->encrypt($data['facebook']['page_access_token']));
            }

            $locked->forceFill(['settings' => $settings])->save();

            return $telegramTokenNowSet;
        });

        if (Arr::has($data, 'dify.handoff_threshold')) {
            AiAgent::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->update(['handoff_threshold' => (int) Arr::get($data, 'dify.handoff_threshold')]);
        }

        $tenant = $tenant->fresh();
        $telegramWebhook = $telegramTokenNowSet ? $this->registerTelegramWebhook($tenant) : null;

        $audit->record('integration_settings.updated', $tenant, $this->auditSettingsSnapshot($tenant), $oldAudit, $request);

        return response()->json($this->payload($tenant) + ['telegram_webhook' => $telegramWebhook]);
    }

    private function registerTelegramWebhook(Tenant $tenant): array
    {
        $url = url('/api/telegram/webhook?tenant_slug='.$tenant->slug);
        $secret = $this->secrets->telegramWebhookSecret($tenant);

        try {
            $this->telegram->setWebhook($tenant, $url, $secret);
        } catch (Throwable $error) {
            return ['ok' => false, 'message' => $error->getMessage()];
        }

        $company = Company::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();

        if ($company) {
            Channel::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'company_id' => $company->id, 'provider' => 'telegram', 'name' => 'Telegram Bot'],
                ['status' => 'connected', 'last_synced_at' => now()]
            );
        }

        return ['ok' => true, 'message' => 'Telegram-бот подключён, вебхук зарегистрирован.'];
    }

    public function test(Request $request, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $tenant = $this->tenant($context);
        Gate::authorize('update', $tenant);

        $data = $request->validate([
            'provider' => ['required', Rule::in(['dify', 'chatwoot', 'telegram', 'whatsapp', 'instagram', 'facebook'])],
            'dify.url' => ['nullable', 'url', 'max:255'],
            'dify.api_key' => ['nullable', 'string', 'max:255'],
            'dify.timeout' => ['nullable', 'integer', 'min:3', 'max:60'],
            'chatwoot.url' => ['nullable', 'url', 'max:255'],
            'chatwoot.account_id' => ['nullable', 'integer', 'min:1'],
            'chatwoot.api_token' => ['nullable', 'string', 'max:255'],
            'chatwoot.webhook_secret' => ['nullable', 'string', 'max:255'],
            'chatwoot.auto_reply_mode' => ['nullable', Rule::in(['off', 'priority', 'always'])],
            'telegram.bot_token' => ['nullable', 'string', 'max:255'],
            'telegram.webhook_secret' => ['nullable', 'string', 'max:255'],
            'whatsapp.access_token' => ['nullable', 'string', 'max:255'],
            'whatsapp.phone_number_id' => ['nullable', 'string', 'max:64'],
            'instagram.page_access_token' => ['nullable', 'string', 'max:255'],
            'instagram.business_account_id' => ['nullable', 'string', 'max:64'],
            'facebook.page_access_token' => ['nullable', 'string', 'max:255'],
            'facebook.page_id' => ['nullable', 'string', 'max:64'],
        ]);

        $result = match ($data['provider']) {
            'dify' => $this->testDify($tenant, $data['dify'] ?? []),
            'chatwoot' => $this->testChatwoot($tenant, $data['chatwoot'] ?? []),
            'telegram' => $this->testTelegram($tenant),
            'whatsapp' => $this->testWhatsapp($tenant, $data['whatsapp'] ?? []),
            'instagram' => $this->testInstagram($tenant, $data['instagram'] ?? []),
            'facebook' => $this->testFacebook($tenant, $data['facebook'] ?? []),
        };

        $audit->record('integration_connection.tested', $tenant, [
            'provider' => $result['provider'],
            'status' => $result['status'],
            'ok' => $result['ok'],
        ], [], $request);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    private function testDify(Tenant $tenant, array $draft): array
    {
        $settings = $tenant->settings ?? [];
        $baseUrl = rtrim((string) ($draft['url'] ?? $this->secrets->difyUrl($tenant)), '/');
        $apiKey = (string) ($draft['api_key'] ?? $this->secrets->difyApiKey($tenant));
        $timeout = (int) ($draft['timeout'] ?? Arr::get($settings, 'integrations.dify.timeout', config('services.dify.timeout', 12)));

        if ($baseUrl === '' || $apiKey === '') {
            return $this->connectionResult(false, 'dify', 'missing_credentials', 'AI engine URL and API key are required.');
        }

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout(4)
                ->acceptJson()
                ->withToken($apiKey)
                ->post($baseUrl.'/chat-messages', [
                    'inputs' => ['connection_test' => true],
                    'query' => 'Gravity AI CRM connection test. Reply with ok.',
                    'response_mode' => 'blocking',
                    'user' => 'tenant-'.$tenant->id.'-connection-test',
                ]);
        } catch (Throwable $error) {
            return $this->connectionResult(false, 'dify', 'request_failed', $error->getMessage());
        }

        if (! $response->successful()) {
            return $this->connectionResult(false, 'dify', 'http_'.$response->status(), 'AI engine returned HTTP '.$response->status().'.');
        }

        return $this->connectionResult(true, 'dify', 'connected', 'AI engine connection succeeded.');
    }

    private function testChatwoot(Tenant $tenant, array $draft): array
    {
        $settings = $tenant->settings ?? [];
        $baseUrl = rtrim((string) ($draft['url'] ?? $this->secrets->chatwootUrl($tenant)), '/');
        $accountId = (int) ($draft['account_id'] ?? Arr::get($settings, 'integrations.chatwoot.account_id', config('services.chatwoot.account_id', 0)));
        $apiToken = (string) ($draft['api_token'] ?? $this->secrets->chatwootApiToken($tenant));
        $webhookSecret = (string) ($draft['webhook_secret'] ?? $this->secrets->chatwootWebhookSecret($tenant));
        $webhookUrl = url('/api/chatwoot/webhook?tenant_slug='.$tenant->slug);

        if ($baseUrl === '' || $accountId < 1 || $apiToken === '') {
            return $this->connectionResult(false, 'chatwoot', 'missing_credentials', 'Unified inbox URL, account ID and API token are required.', [
                'webhook_url' => $webhookUrl,
                'webhook_secret_configured' => $webhookSecret !== '',
            ]);
        }

        try {
            $response = Http::timeout(10)
                ->connectTimeout(4)
                ->acceptJson()
                ->withHeaders(['api_access_token' => $apiToken])
                ->get($baseUrl.'/api/v1/accounts/'.$accountId.'/inboxes');
        } catch (Throwable $error) {
            return $this->connectionResult(false, 'chatwoot', 'request_failed', $error->getMessage(), [
                'webhook_url' => $webhookUrl,
            ]);
        }

        if (! $response->successful()) {
            return $this->connectionResult(false, 'chatwoot', 'http_'.$response->status(), 'Unified inbox returned HTTP '.$response->status().'.', [
                'webhook_url' => $webhookUrl,
            ]);
        }

        return $this->connectionResult(true, 'chatwoot', 'connected', 'Unified inbox connection succeeded.', [
            'account_id' => (string) $accountId,
            'webhook_url' => $webhookUrl,
            'tenant_query' => 'tenant_slug='.$tenant->slug,
            'webhook_secret_configured' => $webhookSecret !== '',
        ]);
    }

    private function testTelegram(Tenant $tenant): array
    {
        $token = $this->secrets->telegramBotToken($tenant);
        $webhookUrl = url('/api/telegram/webhook?tenant_slug='.$tenant->slug);

        if ($token === '') {
            return $this->connectionResult(false, 'telegram', 'missing_credentials', 'Сначала сохраните токен бота.', [
                'webhook_url' => $webhookUrl,
            ]);
        }

        try {
            $response = Http::timeout(10)->connectTimeout(4)->acceptJson()
                ->get('https://api.telegram.org/bot'.$token.'/getMe');
        } catch (Throwable $error) {
            return $this->connectionResult(false, 'telegram', 'request_failed', $error->getMessage(), [
                'webhook_url' => $webhookUrl,
            ]);
        }

        $json = $response->json();

        if (! $response->successful() || ! Arr::get($json, 'ok', false)) {
            return $this->connectionResult(false, 'telegram', 'invalid_token', Arr::get($json, 'description', 'Telegram отклонил токен.'), [
                'webhook_url' => $webhookUrl,
            ]);
        }

        $username = Arr::get($json, 'result.username');

        return $this->connectionResult(true, 'telegram', 'connected', 'Бот @'.$username.' подтверждён.', [
            'webhook_url' => $webhookUrl,
            'bot_username' => (string) $username,
        ]);
    }

    /** Direct WhatsApp Cloud API (Meta) — no Chatwoot involved. */
    private function testWhatsapp(Tenant $tenant, array $draft): array
    {
        $token = (string) ($draft['access_token'] ?? $this->secrets->whatsappAccessToken($tenant));
        $phoneNumberId = (string) ($draft['phone_number_id'] ?? $this->secrets->whatsappPhoneNumberId($tenant));

        if ($token === '' || $phoneNumberId === '') {
            return $this->connectionResult(false, 'whatsapp', 'missing_credentials', 'Укажите access token и phone number ID.');
        }

        try {
            $response = Http::timeout(10)->connectTimeout(4)->acceptJson()
                ->get('https://graph.facebook.com/v21.0/'.$phoneNumberId, ['access_token' => $token]);
        } catch (Throwable $error) {
            return $this->connectionResult(false, 'whatsapp', 'request_failed', $error->getMessage());
        }

        $json = $response->json();

        if (! $response->successful()) {
            return $this->connectionResult(false, 'whatsapp', 'http_'.$response->status(), Arr::get($json, 'error.message', 'Meta вернул ошибку.'));
        }

        $this->markChannelConnected($tenant, 'whatsapp', 'WhatsApp');

        return $this->connectionResult(true, 'whatsapp', 'connected', 'Номер '.Arr::get($json, 'display_phone_number', $phoneNumberId).' подтверждён.', [
            'display_phone_number' => Arr::get($json, 'display_phone_number'),
            'verified_name' => Arr::get($json, 'verified_name'),
        ]);
    }

    /** Direct Instagram Graph API (Meta) — Instagram Business Account, no Chatwoot involved. */
    private function testInstagram(Tenant $tenant, array $draft): array
    {
        $token = (string) ($draft['page_access_token'] ?? $this->secrets->instagramPageAccessToken($tenant));
        $accountId = (string) ($draft['business_account_id'] ?? $this->secrets->instagramBusinessAccountId($tenant));

        if ($token === '' || $accountId === '') {
            return $this->connectionResult(false, 'instagram', 'missing_credentials', 'Укажите page access token и Instagram Business Account ID.');
        }

        try {
            $response = Http::timeout(10)->connectTimeout(4)->acceptJson()
                ->get('https://graph.facebook.com/v21.0/'.$accountId, ['fields' => 'username', 'access_token' => $token]);
        } catch (Throwable $error) {
            return $this->connectionResult(false, 'instagram', 'request_failed', $error->getMessage());
        }

        $json = $response->json();

        if (! $response->successful()) {
            return $this->connectionResult(false, 'instagram', 'http_'.$response->status(), Arr::get($json, 'error.message', 'Meta вернул ошибку.'));
        }

        $this->markChannelConnected($tenant, 'instagram', 'Instagram Direct');

        return $this->connectionResult(true, 'instagram', 'connected', 'Аккаунт @'.Arr::get($json, 'username', $accountId).' подтверждён.', [
            'username' => Arr::get($json, 'username'),
        ]);
    }

    /** Direct Facebook Graph API (Meta) — Page access token, no Chatwoot involved. */
    private function testFacebook(Tenant $tenant, array $draft): array
    {
        $token = (string) ($draft['page_access_token'] ?? $this->secrets->facebookPageAccessToken($tenant));
        $pageId = (string) ($draft['page_id'] ?? $this->secrets->facebookPageId($tenant));

        if ($token === '' || $pageId === '') {
            return $this->connectionResult(false, 'facebook', 'missing_credentials', 'Укажите page access token и Page ID.');
        }

        try {
            $response = Http::timeout(10)->connectTimeout(4)->acceptJson()
                ->get('https://graph.facebook.com/v21.0/'.$pageId, ['fields' => 'name', 'access_token' => $token]);
        } catch (Throwable $error) {
            return $this->connectionResult(false, 'facebook', 'request_failed', $error->getMessage());
        }

        $json = $response->json();

        if (! $response->successful()) {
            return $this->connectionResult(false, 'facebook', 'http_'.$response->status(), Arr::get($json, 'error.message', 'Meta вернул ошибку.'));
        }

        $this->markChannelConnected($tenant, 'facebook', 'Facebook Messenger');

        return $this->connectionResult(true, 'facebook', 'connected', 'Страница «'.Arr::get($json, 'name', $pageId).'» подтверждена.', [
            'page_name' => Arr::get($json, 'name'),
        ]);
    }

    /**
     * Unlike Telegram (Channel row created as part of registering the webhook on
     * save), WhatsApp/Instagram/Facebook have no such save-time API call — their
     * webhook is subscribed once, manually, in the Meta App dashboard, not per
     * tenant. A successful "Проверить" (test connection) is the first point WERO
     * actually confirms the credentials work, so that's what flips the channel
     * card from "требуется настройка" to "подключено" here, mirroring what
     * registerTelegramWebhook() does for Telegram.
     *
     * Matches the existing row on (tenant, company, provider) only — NOT name.
     * A tenant can only sensibly have one channel per provider, and some tenants
     * (demo-seeded ones especially) already had a row under a different display
     * name (e.g. "WhatsApp Business" instead of "WhatsApp"); matching on name too
     * meant this silently created a second, duplicate row instead of updating the
     * existing one — IntegrationsPage.vue's `channels.find()` then kept showing
     * whichever row happened to come first, which was the old stale one.
     */
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

    private function hasFilledSecret(array $input, string $key): bool
    {
        return array_key_exists($key, $input) && trim((string) $input[$key]) !== '';
    }

    private function connectionResult(bool $ok, string $provider, string $status, string $message, array $meta = []): array
    {
        return [
            'ok' => $ok,
            'provider' => $provider,
            'status' => $status,
            'message' => $message,
            'checked_at' => now()->toIso8601String(),
            'meta' => $meta,
        ];
    }

    private function auditSettingsSnapshot(Tenant $tenant): array
    {
        $payload = $this->payload($tenant);

        return [
            'dify' => [
                'url' => $payload['dify']['url'],
                'api_key_configured' => $payload['dify']['api_key_configured'],
                'api_key_mask' => $payload['dify']['api_key_mask'],
                'timeout' => $payload['dify']['timeout'],
                'handoff_threshold' => $payload['dify']['handoff_threshold'],
            ],
            'chatwoot' => [
                'url' => $payload['chatwoot']['url'],
                'account_id' => $payload['chatwoot']['account_id'],
                'api_token_configured' => $payload['chatwoot']['api_token_configured'],
                'api_token_mask' => $payload['chatwoot']['api_token_mask'],
                'webhook_secret_configured' => $payload['chatwoot']['webhook_secret_configured'],
                'webhook_secret_mask' => $payload['chatwoot']['webhook_secret_mask'],
                'webhook_url' => $payload['chatwoot']['webhook_url'],
                'auto_reply_mode' => $payload['chatwoot']['auto_reply_mode'] ?? 'off',
            ],
            'telegram' => [
                'bot_token_configured' => $payload['telegram']['bot_token_configured'],
                'bot_token_mask' => $payload['telegram']['bot_token_mask'],
                'webhook_secret_configured' => $payload['telegram']['webhook_secret_configured'],
                'webhook_secret_mask' => $payload['telegram']['webhook_secret_mask'],
                'webhook_url' => $payload['telegram']['webhook_url'],
            ],
            'whatsapp' => [
                'access_token_configured' => $payload['whatsapp']['access_token_configured'],
                'access_token_mask' => $payload['whatsapp']['access_token_mask'],
                'phone_number_id' => $payload['whatsapp']['phone_number_id'],
                'business_account_id' => $payload['whatsapp']['business_account_id'],
            ],
            'instagram' => [
                'page_access_token_configured' => $payload['instagram']['page_access_token_configured'],
                'page_access_token_mask' => $payload['instagram']['page_access_token_mask'],
                'business_account_id' => $payload['instagram']['business_account_id'],
            ],
            'facebook' => [
                'page_access_token_configured' => $payload['facebook']['page_access_token_configured'],
                'page_access_token_mask' => $payload['facebook']['page_access_token_mask'],
                'page_id' => $payload['facebook']['page_id'],
            ],
        ];
    }

    private function tenant(TenantContext $context): Tenant
    {
        return Tenant::query()->findOrFail($context->id());
    }

    private function payload(Tenant $tenant): array
    {
        $settings = $tenant->settings ?? [];
        $difyKey = $this->secrets->difyApiKey($tenant, false);
        $chatwootToken = $this->secrets->chatwootApiToken($tenant, false);
        $chatwootSecret = $this->secrets->chatwootWebhookSecret($tenant, false);
        $telegramToken = $this->secrets->telegramBotToken($tenant);
        $telegramSecret = $this->secrets->telegramWebhookSecret($tenant);
        $whatsappToken = $this->secrets->whatsappAccessToken($tenant);
        $instagramToken = $this->secrets->instagramPageAccessToken($tenant);
        $facebookToken = $this->secrets->facebookPageAccessToken($tenant);

        return [
            'dify' => [
                'url' => $this->secrets->difyUrl($tenant) ?: null,
                'api_key_configured' => $difyKey !== '',
                'api_key_mask' => $this->secrets->mask($difyKey),
                'timeout' => Arr::get($settings, 'integrations.dify.timeout', config('services.dify.timeout', 12)),
                'handoff_threshold' => Arr::get($settings, 'integrations.dify.handoff_threshold'),
            ],
            'chatwoot' => [
                'url' => $this->secrets->chatwootUrl($tenant),
                'account_id' => Arr::get($settings, 'integrations.chatwoot.account_id', config('services.chatwoot.account_id')),
                'api_token_configured' => $chatwootToken !== '',
                'api_token_mask' => $this->secrets->mask($chatwootToken),
                'webhook_secret_configured' => $chatwootSecret !== '',
                'webhook_secret_mask' => $this->secrets->mask($chatwootSecret),
                'webhook_url' => url('/api/chatwoot/webhook?tenant_slug='.$tenant->slug),
                'auto_reply_mode' => $this->secrets->autoReplyMode($tenant),
            ],
            'telegram' => [
                'bot_token_configured' => $telegramToken !== '',
                'bot_token_mask' => $this->secrets->mask($telegramToken),
                'webhook_secret_configured' => $telegramSecret !== '',
                'webhook_secret_mask' => $this->secrets->mask($telegramSecret),
                'webhook_url' => url('/api/telegram/webhook?tenant_slug='.$tenant->slug),
            ],
            'whatsapp' => [
                'access_token_configured' => $whatsappToken !== '',
                'access_token_mask' => $this->secrets->mask($whatsappToken),
                'phone_number_id' => $this->secrets->whatsappPhoneNumberId($tenant) ?: null,
                'business_account_id' => $this->secrets->whatsappBusinessAccountId($tenant) ?: null,
                // Unlike Telegram's webhook_url, this is the SAME url for every tenant —
                // WhatsApp/Instagram/Facebook webhooks are registered once per platform
                // App in Meta's developer console, not per tenant (see
                // FacebookWebhookController's docblock). Shown here so whoever is doing
                // the Meta App setup knows what to paste into the App's Webhooks config.
                'webhook_url' => url('/api/whatsapp/webhook'),
            ],
            'instagram' => [
                'page_access_token_configured' => $instagramToken !== '',
                'page_access_token_mask' => $this->secrets->mask($instagramToken),
                'business_account_id' => $this->secrets->instagramBusinessAccountId($tenant) ?: null,
                'webhook_url' => url('/api/instagram/webhook'),
            ],
            'facebook' => [
                'page_access_token_configured' => $facebookToken !== '',
                'page_access_token_mask' => $this->secrets->mask($facebookToken),
                'page_id' => $this->secrets->facebookPageId($tenant) ?: null,
                'webhook_url' => url('/api/facebook/webhook'),
            ],
        ];
    }
}