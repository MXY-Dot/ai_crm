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

    public function sendMessage(Tenant $tenant, string $chatId, string $text, ?string $replyToMessageId = null, ?array $replyMarkup = null): array
    {
        $token = $this->settings->telegramBotToken($tenant);

        if ($token === '') {
            throw new RuntimeException('Telegram bot token is required.');
        }

        try {
            $response = $this->http()->post('https://api.telegram.org/bot'.$token.'/sendMessage', array_filter([
                'chat_id' => $chatId,
                'text' => $text,
                'reply_parameters' => $replyToMessageId ? ['message_id' => (int) $replyToMessageId] : null,
                'reply_markup' => $replyMarkup,
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

    public function sendPhoto(Tenant $tenant, string $chatId, string $localPath, string $filename, string $caption = '', ?string $replyToMessageId = null): array
    {
        return $this->sendMedia($tenant, 'sendPhoto', $chatId, 'photo', $localPath, $filename, $caption, $replyToMessageId);
    }

    public function sendVoice(Tenant $tenant, string $chatId, string $localPath, string $filename, string $caption = '', ?string $replyToMessageId = null): array
    {
        return $this->sendMedia($tenant, 'sendVoice', $chatId, 'voice', $localPath, $filename, $caption, $replyToMessageId);
    }

    public function sendDocument(Tenant $tenant, string $chatId, string $localPath, string $filename, string $caption = '', ?string $replyToMessageId = null): array
    {
        return $this->sendMedia($tenant, 'sendDocument', $chatId, 'document', $localPath, $filename, $caption, $replyToMessageId);
    }

    /**
     * Uploads the file directly to Telegram (multipart) instead of passing a fetchable URL.
     * Telegram's URL-fetch mode reliably rejects file URLs hosted on a bare IP address
     * (this server has no public domain name yet), so direct upload is used instead.
     */
    private function sendMedia(Tenant $tenant, string $method, string $chatId, string $field, string $localPath, string $filename, string $caption, ?string $replyToMessageId = null): array
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
                    // multipart/form-data has no nested-object support, so structured fields
                    // (like reply_parameters) must be sent as a JSON-encoded string — this is
                    // Telegram Bot API's own documented convention for complex fields over multipart.
                    'reply_parameters' => $replyToMessageId ? json_encode(['message_id' => (int) $replyToMessageId]) : null,
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

    /**
     * Best-effort: edits a message WERO previously sent via the bot. Returns false
     * (never throws) on any failure — Telegram rejects edits on messages older than
     * 48h or already-deleted messages, and callers here only need "did it work?"
     * to decide whether to note the outcome, not a hard error.
     */
    public function editMessageText(Tenant $tenant, string $chatId, string $messageId, string $text): bool
    {
        $token = $this->settings->telegramBotToken($tenant);

        if ($token === '') {
            return false;
        }

        try {
            $response = $this->http()->post('https://api.telegram.org/bot'.$token.'/editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $text,
            ]);
        } catch (Throwable) {
            return false;
        }

        return $response->successful() && (bool) Arr::get($response->json(), 'ok', false);
    }

    /** Best-effort: deletes a message WERO previously sent via the bot. Never throws. */
    public function deleteMessage(Tenant $tenant, string $chatId, string $messageId): bool
    {
        $token = $this->settings->telegramBotToken($tenant);

        if ($token === '') {
            return false;
        }

        try {
            $response = $this->http()->post('https://api.telegram.org/bot'.$token.'/deleteMessage', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
            ]);
        } catch (Throwable) {
            return false;
        }

        return $response->successful() && (bool) Arr::get($response->json(), 'ok', false);
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

    /**
     * Resolves a Telegram `file_id` (from an incoming photo/document/voice/video message)
     * to the actual file bytes, in two hops as Telegram's API requires: `getFile` first
     * resolves the `file_id` to a short-lived `file_path`, then the file itself is fetched
     * from a *different* host (`api.telegram.org/file/...`, not `/bot.../...`). Telegram
     * caps bot file downloads at 20MB — same limit `ConversationAttachmentController`
     * already enforces for operator-uploaded attachments, so no separate check is needed
     * here beyond trusting Telegram's own cap.
     */
    public function downloadFile(Tenant $tenant, string $fileId): string
    {
        $token = $this->settings->telegramBotToken($tenant);

        if ($token === '') {
            throw new RuntimeException('Telegram bot token is required.');
        }

        try {
            $response = $this->http()->get('https://api.telegram.org/bot'.$token.'/getFile', ['file_id' => $fileId]);
        } catch (Throwable $error) {
            throw new RuntimeException('Telegram request failed: '.$error->getMessage(), previous: $error);
        }

        $json = $response->json();

        if (! $response->successful() || ! Arr::get($json, 'ok', false)) {
            throw new RuntimeException('Telegram rejected getFile: '.Arr::get($json, 'description', 'HTTP '.$response->status()));
        }

        $filePath = Arr::get($json, 'result.file_path');

        if (! $filePath) {
            throw new RuntimeException('Telegram did not return a file_path.');
        }

        try {
            $fileResponse = $this->http()->get('https://api.telegram.org/file/bot'.$token.'/'.$filePath);
        } catch (Throwable $error) {
            throw new RuntimeException('Telegram file download failed: '.$error->getMessage(), previous: $error);
        }

        if (! $fileResponse->successful()) {
            throw new RuntimeException('Telegram file download returned HTTP '.$fileResponse->status().'.');
        }

        return $fileResponse->body();
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