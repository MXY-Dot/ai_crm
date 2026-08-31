<?php

namespace App\Notifications\Channels;

use App\Models\Tenant;
use App\Models\User;
use App\Support\TelegramClient;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ТЗ раздел 18 — sends a notification to a staff member's own linked
 * Telegram chat, through the TENANT'S OWN already-connected bot (the same
 * one customers message) — not a separate platform bot, which would need
 * its own token this environment doesn't have. A user only has a
 * telegram_chat_id once they've run the /link flow (see
 * TelegramWebhookController); best-effort like every other notification
 * side-channel in this codebase — a send failure here must never surface
 * to whatever triggered the notification.
 */
class TenantTelegramChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || ! $notifiable->telegram_chat_id || ! $notifiable->tenant_id) {
            return;
        }

        if (! method_exists($notification, 'toTenantTelegram')) {
            return;
        }

        $tenant = Tenant::query()->find($notifiable->tenant_id);

        if (! $tenant) {
            return;
        }

        try {
            app(TelegramClient::class)->sendMessage($tenant, $notifiable->telegram_chat_id, $notification->toTenantTelegram($notifiable));
        } catch (Throwable $error) {
            Log::warning('TenantTelegramChannel: send failed', ['user_id' => $notifiable->id, 'error' => $error->getMessage()]);
        }
    }
}
