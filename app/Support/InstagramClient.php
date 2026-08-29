<?php

namespace App\Support;

use App\Models\Tenant;
use App\Support\Integrations\TenantIntegrationSettings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Direct Instagram messaging (Graph API) client — no Chatwoot involved.
 * Uses the same `/me/messages` edge as Messenger with the Page's access
 * token (this is the Page-linked Instagram messaging flow, matching the
 * `instagram.page_access_token` setting name — not the newer standalone
 * Instagram API with Instagram Login).
 */
class InstagramClient
{
    private const API_BASE = 'https://graph.facebook.com/v21.0';

    public function __construct(private readonly TenantIntegrationSettings $settings)
    {
    }

    public function sendMessage(Tenant $tenant, string $recipientIgsid, string $text): array
    {
        return $this->post($tenant, [
            'recipient' => ['id' => $recipientIgsid],
            'message' => ['text' => $text],
        ]);
    }

    public function sendMedia(Tenant $tenant, string $recipientIgsid, string $type, string $url, string $caption = ''): array
    {
        $igType = match ($type) {
            'photo' => 'image',
            'voice' => 'audio',
            default => 'file',
        };

        $result = $this->post($tenant, [
            'recipient' => ['id' => $recipientIgsid],
            'message' => ['attachment' => ['type' => $igType, 'payload' => ['url' => $url]]],
        ]);

        if ($caption !== '') {
            $this->sendMessage($tenant, $recipientIgsid, $caption);
        }

        return $result;
    }

    private function post(Tenant $tenant, array $body): array
    {
        $token = $this->settings->instagramPageAccessToken($tenant);

        if ($token === '') {
            throw new RuntimeException('Instagram page access token is required.');
        }

        try {
            $response = $this->http()->post(self::API_BASE.'/me/messages', $body + ['access_token' => $token]);
        } catch (Throwable $error) {
            throw new RuntimeException('Instagram request failed: '.$error->getMessage(), previous: $error);
        }

        if (! $response->successful()) {
            throw new RuntimeException('Instagram returned HTTP '.$response->status().': '.Arr::get($response->json(), 'error.message', ''));
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /** Instagram's inbound attachment URL is directly fetchable, same as Messenger. */
    public function downloadAttachmentUrl(string $url): string
    {
        try {
            $response = $this->http()->get($url);
        } catch (Throwable $error) {
            throw new RuntimeException('Instagram attachment download failed: '.$error->getMessage(), previous: $error);
        }

        if (! $response->successful()) {
            throw new RuntimeException('Instagram attachment download returned HTTP '.$response->status().'.');
        }

        return $response->body();
    }

    private function http(): PendingRequest
    {
        return Http::connectTimeout(5)->timeout(15)->retry(2, 400)->acceptJson();
    }
}
