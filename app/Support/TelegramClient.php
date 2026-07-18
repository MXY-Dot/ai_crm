<?php

namespace App\Support;

use App\Models\Tenant;
use App\Support\Integrations\TenantIntegrationSettings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class TelegramClient
{
    public function __construct(private readonly TenantIntegrationSettings $settings)
    {
    }

    public function sendMessage(Tenant $tenant, string $chatId, string $text): array
    {
        $token = $this->settings->telegramBotToken($tenant);

        if ($token === '') {
            throw new RuntimeException('Telegram bot token is required.');
        }

        try {
            $response = $this->http()->post('https://api.telegram.org/bot'.$token.'/sendMessage', [
                'chat_id' => $chatId,
                'text' => $text,
            ]);
        } catch (Throwable $error) {
            throw new RuntimeException('Telegram request failed: '.$error->getMessage(), previous: $error);
        }

        if (! $response->successful()) {
            throw new RuntimeException('Telegram returned HTTP '.$response->status().'.');
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    public function sendChatAction(Tenant $tenant, string $chatId, string $action = 'typing'): void
    {
        $token = $this->settings->telegramBotToken($tenant);

        if ($token === '') {
            return;
        }

        try {
            $this->http()->post('https://api.telegram.org/bot'.$token.'/sendChatAction', [
                'chat_id' => $chatId,
                'action' => $action,
            ]);
        } catch (Throwable) {
        }
    }
    private function http(): PendingRequest
    {
        return Http::timeout(12)->acceptJson();
    }
}