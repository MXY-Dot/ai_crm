<?php

namespace App\Support;

use App\Models\Tenant;
use App\Support\Integrations\TenantIntegrationSettings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
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

    public function sendPhoto(Tenant $tenant, string $chatId, string $localPath, string $filename, string $caption = ''): array
    {
        return $this->sendMedia($tenant, 'sendPhoto', $chatId, 'photo', $localPath, $filename, $caption);
    }

    public function sendVoice(Tenant $tenant, string $chatId, string $localPath, string $filename, string $caption = ''): array
    {
        return $this->sendMedia($tenant, 'sendVoice', $chatId, 'voice', $localPath, $filename, $caption);
    }

    public function sendDocument(Tenant $tenant, string $chatId, string $localPath, string $filename, string $caption = ''): array
    {
        return $this->sendMedia($tenant, 'sendDocument', $chatId, 'document', $localPath, $filename, $caption);
    }

    /**
     * Uploads the file directly to Telegram (multipart) instead of passing a fetchable URL.
     * Telegram's URL-fetch mode reliably rejects file URLs hosted on a bare IP address
     * (this server has no public domain name yet), so direct upload is used instead.
     */
    private function sendMedia(Tenant $tenant, string $method, string $chatId, string $field, string $localPath, string $filename, string $caption): array
    {
        $token = $this->settings->telegramBotToken($tenant);

        if ($token === '') {
            throw new RuntimeException('Telegram bot token is required.');
        }

        if (! is_readable($localPath)) {
            throw new RuntimeException('Attachment file is not readable on the server.');
        }

        try {
            $response = $this->http()
                ->attach($field, file_get_contents($localPath), $filename)
                ->post('https://api.telegram.org/bot'.$token.'/'.$method, array_filter([
                    'chat_id' => $chatId,
                    'caption' => $caption !== '' ? $caption : null,
                ], fn ($value) => $value !== null));
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

    public function getMe(Tenant $tenant): array
    {
        $token = $this->settings->telegramBotToken($tenant);

        if ($token === '') {
            throw new RuntimeException('Telegram bot token is required.');
        }

        try {
            $response = $this->http()->get('https://api.telegram.org/bot'.$token.'/getMe');
        } catch (Throwable $error) {
            throw new RuntimeException('Telegram request failed: '.$error->getMessage(), previous: $error);
        }

        $json = $response->json();

        if (! $response->successful() || ! Arr::get($json, 'ok', false)) {
            throw new RuntimeException('Telegram rejected the token: '.Arr::get($json, 'description', 'HTTP '.$response->status()));
        }

        return Arr::get($json, 'result', []);
    }

    public function setWebhook(Tenant $tenant, string $url, string $secret): array
    {
        $token = $this->settings->telegramBotToken($tenant);

        if ($token === '') {
            throw new RuntimeException('Telegram bot token is required.');
        }

        try {
            $response = $this->http()->post('https://api.telegram.org/bot'.$token.'/setWebhook', [
                'url' => $url,
                'secret_token' => $secret,
                'allowed_updates' => ['message', 'edited_message'],
                'drop_pending_updates' => true,
            ]);
        } catch (Throwable $error) {
            throw new RuntimeException('Telegram request failed: '.$error->getMessage(), previous: $error);
        }

        $json = $response->json();

        if (! $response->successful() || ! Arr::get($json, 'ok', false)) {
            throw new RuntimeException('Telegram rejected the webhook: '.Arr::get($json, 'description', 'HTTP '.$response->status()));
        }

        return is_array($json) ? $json : [];
    }

    private function http(): PendingRequest
    {
        return Http::connectTimeout(5)->timeout(15)->retry(2, 400)->acceptJson();
    }
}