<?php

namespace App\Support\Integrations;

use App\Models\Tenant;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class TenantIntegrationSettings
{
    private const PREFIX = 'enc:v1:';

    public function difyApiKey(Tenant $tenant, bool $withConfigFallback = true): string
    {
        $value = $this->decrypt(Arr::get($tenant->settings ?? [], 'integrations.dify.api_key'));

        if ($value === '' && $withConfigFallback) {
            return (string) config('services.dify.api_key', '');
        }

        return $value;
    }

    public function difyUrl(Tenant $tenant): string
    {
        return rtrim((string) Arr::get($tenant->settings ?? [], 'integrations.dify.url', config('services.dify.url', '')), '/');
    }

    public function chatwootWebhookSecret(Tenant $tenant, bool $withConfigFallback = true): string
    {
        $value = $this->decrypt(Arr::get($tenant->settings ?? [], 'integrations.chatwoot.webhook_secret'));

        if ($value === '' && $withConfigFallback) {
            return (string) config('services.chatwoot.webhook_secret', '');
        }

        return $value;
    }
    public function chatwootUrl(Tenant $tenant): string
    {
        return rtrim((string) Arr::get($tenant->settings ?? [], 'integrations.chatwoot.url', config('services.chatwoot.url', '')), '/');
    }

    public function chatwootApiToken(Tenant $tenant, bool $withConfigFallback = true): string
    {
        $value = $this->decrypt(Arr::get($tenant->settings ?? [], 'integrations.chatwoot.api_token'));

        if ($value === '' && $withConfigFallback) {
            return (string) config('services.chatwoot.api_token', '');
        }

        return $value;
    }


    /**
     * Auto-reply has 3 states, not just on/off: 'off' (AI never sends), 'priority'
     * (AI sends, but yields to an operator who already has the conversation open —
     * see ConversationTypingController::hasActiveViewer()), 'always' (AI sends
     * regardless of operator presence). Falls back to the legacy boolean
     * `auto_reply_enabled` for tenants saved before this 3-state field existed.
     */
    public function autoReplyMode(Tenant $tenant): string
    {
        $mode = Arr::get($tenant->settings ?? [], 'integrations.chatwoot.auto_reply_mode');

        if (in_array($mode, ['off', 'priority', 'always'], true)) {
            return $mode;
        }

        return Arr::get($tenant->settings ?? [], 'integrations.chatwoot.auto_reply_enabled', false) ? 'priority' : 'off';
    }

    public function telegramBotToken(Tenant $tenant): string
    {
        return $this->decrypt(Arr::get($tenant->settings ?? [], 'integrations.telegram.bot_token'));
    }

    public function telegramWebhookSecret(Tenant $tenant): string
    {
        return $this->decrypt(Arr::get($tenant->settings ?? [], 'integrations.telegram.webhook_secret'));
    }

    /** Direct WhatsApp Cloud API (Meta) — no Chatwoot involved. */
    public function whatsappAccessToken(Tenant $tenant): string
    {
        return $this->decrypt(Arr::get($tenant->settings ?? [], 'integrations.whatsapp.access_token'));
    }

    public function whatsappPhoneNumberId(Tenant $tenant): string
    {
        return (string) Arr::get($tenant->settings ?? [], 'integrations.whatsapp.phone_number_id', '');
    }

    public function whatsappBusinessAccountId(Tenant $tenant): string
    {
        return (string) Arr::get($tenant->settings ?? [], 'integrations.whatsapp.business_account_id', '');
    }

    /** Direct Instagram Graph API (Meta) — Instagram Business Account, no Chatwoot involved. */
    public function instagramPageAccessToken(Tenant $tenant): string
    {
        return $this->decrypt(Arr::get($tenant->settings ?? [], 'integrations.instagram.page_access_token'));
    }

    public function instagramBusinessAccountId(Tenant $tenant): string
    {
        return (string) Arr::get($tenant->settings ?? [], 'integrations.instagram.business_account_id', '');
    }

    /** Direct Facebook Graph API (Meta) — Page access token, no Chatwoot involved. */
    public function facebookPageAccessToken(Tenant $tenant): string
    {
        return $this->decrypt(Arr::get($tenant->settings ?? [], 'integrations.facebook.page_access_token'));
    }

    public function facebookPageId(Tenant $tenant): string
    {
        return (string) Arr::get($tenant->settings ?? [], 'integrations.facebook.page_id', '');
    }

    public function encrypt(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (str_starts_with($value, self::PREFIX)) {
            return $value;
        }

        return self::PREFIX.Crypt::encryptString($value);
    }

    public function decrypt(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        if (! str_starts_with($value, self::PREFIX)) {
            return $value;
        }

        try {
            return Crypt::decryptString(substr($value, strlen(self::PREFIX)));
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Fixed-width regardless of the secret's real length (WhatsApp permanent
     * tokens in particular run 200+ chars) — scaling the asterisk run with
     * strlen() used to produce a mask that long, which broke the settings
     * dialog's layout by forcing it wider than the viewport.
     */
    public function mask(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        return str_repeat('*', 8).substr($value, -4);
    }
}