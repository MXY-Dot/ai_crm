<?php

namespace App\Support\Messaging;

use App\Models\MessageLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Arr;

/**
 * Central point for two things super admin asked for: an audit trail of
 * every outbound email/Telegram send (who, when, which company), and a
 * per-company kill switch for each channel. Email logging/blocking is
 * wired generically via Laravel's Mail events in AppServiceProvider --
 * every Mailable, notification, or raw Mail::send() call is covered with
 * zero changes at the call site. Telegram has no equivalent event system,
 * so its two send sites (TenantTelegramChannel, NotificationSettingsController
 * ::testTelegram) call this directly.
 */
class MessageLogger
{
    public static function log(?int $tenantId, string $channel, string $recipient, ?string $subject, string $status, ?string $error = null): void
    {
        MessageLog::query()->create([
            'tenant_id' => $tenantId,
            'channel' => $channel,
            'recipient' => $recipient,
            'subject' => $subject,
            'status' => $status,
            'error' => $error ? mb_substr($error, 0, 2000) : null,
        ]);
    }

    public static function isChannelEnabled(?int $tenantId, string $channel): bool
    {
        if (! $tenantId) {
            return true;
        }

        $tenant = Tenant::query()->find($tenantId);
        $key = "messaging.{$channel}_enabled";

        return (bool) Arr::get($tenant?->settings ?? [], $key, true);
    }

    public static function setChannelEnabled(Tenant $tenant, string $channel, bool $enabled): void
    {
        $settings = $tenant->settings ?? [];
        Arr::set($settings, "messaging.{$channel}_enabled", $enabled);
        $tenant->update(['settings' => $settings]);
    }

    /** Every mail we send goes to a real User's inbox -- resolves which company it belongs to without threading tenant_id through every Mailable's constructor. */
    public static function tenantIdForRecipient(string $email): ?int
    {
        return User::withoutGlobalScopes()->where('email', $email)->value('tenant_id');
    }
}
