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

    public function mask(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        return str_repeat('*', max(4, strlen($value) - 4)).substr($value, -4);
    }
}