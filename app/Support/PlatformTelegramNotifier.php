<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Platform-level Telegram alerts for moderators (new company registrations,
 * new/updated support tickets) — separate from TelegramClient, which is
 * tenant-scoped and reads its bot token from each tenant's own integration
 * settings. This one reads a single bot token + chat id from platform config.
 * Best-effort and never throws: a notification failure must never break
 * registration or support-ticket flows.
 */
class PlatformTelegramNotifier
{
    public static function notify(string $text): void
    {
        $token = config('services.telegram_moderator.bot_token');
        $chatId = config('services.telegram_moderator.chat_id');

        if (! $token || ! $chatId) {
            return;
        }

        try {
            Http::connectTimeout(5)->timeout(10)->post('https://api.telegram.org/bot'.$token.'/sendMessage', [
                'chat_id' => $chatId,
                'text' => $text,
            ]);
        } catch (Throwable $error) {
            Log::warning('PlatformTelegramNotifier failed', ['error' => $error->getMessage()]);
        }
    }
}
