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