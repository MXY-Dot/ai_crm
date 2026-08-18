<?php

namespace App\Support\Chatwoot;

use App\Models\Tenant;
use App\Support\Integrations\TenantIntegrationSettings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class ChatwootClient
{
    public function __construct(private readonly TenantIntegrationSettings $secrets)
    {
    }

    public function conversations(Tenant $tenant): array
    {
        $payload = $this->request($tenant, 'get', 'conversations');

        return Arr::get($payload, 'data.payload', Arr::get($payload, 'payload', []));
    }

    public function sendOutgoingMessage(Tenant $tenant, string $conversationId, string $content): array
    {
        return $this->request($tenant, 'post', 'conversations/'.$conversationId.'/messages', [
            'content' => $content,
            'message_type' => 'outgoing',
        ]);
    }

    public function toggleTyping(Tenant $tenant, string $conversationId, bool $typing): void
    {
        $this->request($tenant, 'post', 'conversations/'.$conversationId.'/toggle_typing_status', [
            'typing_status' => $typing ? 'on' : 'off',
        ]);
    }

    /** ЭТАП 3.10 — pushes WERO's own resolve() action back to Chatwoot; see ConversationController::resolve(). */
    public function resolveConversation(Tenant $tenant, string $conversationId): void
    {
        $this->request($tenant, 'post', 'conversations/'.$conversationId.'/toggle_status', ['status' => 'resolved']);
    }

    /** ЭТАП 3.10 — pushes WERO's own label list back to Chatwoot; see ConversationController::labels(). */
    public function setLabels(Tenant $tenant, string $conversationId, array $labels): void
    {
        $this->request($tenant, 'post', 'conversations/'.$conversationId.'/labels', ['labels' => $labels]);
    }

    private function request(Tenant $tenant, string $method, string $path, array $body = []): array
    {
        $settings = $this->settings($tenant);
        $url = $settings['base_url'].'/api/v1/accounts/'.$settings['account_id'].'/'.ltrim($path, '/');

        try {
            $response = $this->http($settings['api_token'])->{$method}($url, $body);
        } catch (Throwable $error) {
            throw new RuntimeException('Chatwoot request failed: '.$error->getMessage(), previous: $error);
        }

        if (! $response->successful()) {
            throw new RuntimeException('Chatwoot returned HTTP '.$response->status().'.');
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function http(string $apiToken): PendingRequest
    {
        return Http::timeout(12)
            ->connectTimeout(4)
            ->acceptJson()
            ->withHeaders(['api_access_token' => $apiToken]);
    }

    private function settings(Tenant $tenant): array
    {
        $baseUrl = $this->secrets->chatwootUrl($tenant);
        $accountId = (int) data_get($tenant->settings ?? [], 'integrations.chatwoot.account_id', config('services.chatwoot.account_id', 0));
        $apiToken = $this->secrets->chatwootApiToken($tenant);

        if ($baseUrl === '' || $accountId < 1 || $apiToken === '') {
            throw new RuntimeException('Chatwoot URL, account ID and API token are required.');
        }

        return [
            'base_url' => $baseUrl,
            'account_id' => $accountId,
            'api_token' => $apiToken,
        ];
    }
}