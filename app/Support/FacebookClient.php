<?php

namespace App\Support;

use App\Models\Tenant;
use App\Support\Chat\ChatButtons;
use App\Support\Integrations\TenantIntegrationSettings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/** Direct Facebook Messenger (Graph API) client — no Chatwoot involved. */
class FacebookClient
{
    private const API_BASE = 'https://graph.facebook.com/v21.0';

    public function __construct(private readonly TenantIntegrationSettings $settings)
    {
    }

    public function sendMessage(Tenant $tenant, string $recipientPsid, string $text): array
    {
        return $this->post($tenant, [
            'recipient' => ['id' => $recipientPsid],
            'messaging_type' => 'RESPONSE',
            'message' => ['text' => $text],
        ]);
    }

    /**
     * Messenger accepts outbound media by public URL directly, same as WhatsApp —
     * our own attachment public URL (ConversationAttachmentController) is used as-is.
     * Messenger has no separate "caption" field, so a non-empty caption is sent as
     * its own follow-up text message.
     */
    public function sendMedia(Tenant $tenant, string $recipientPsid, string $type, string $url, string $caption = ''): array
    {
        $fbType = match ($type) {
            'photo' => 'image',
            'voice' => 'audio',
            default => 'file',
        };

        $result = $this->post($tenant, [
            'recipient' => ['id' => $recipientPsid],
            'messaging_type' => 'RESPONSE',
            'message' => ['attachment' => ['type' => $fbType, 'payload' => ['url' => $url, 'is_reusable' => false]]],
        ]);

        if ($caption !== '') {
            $this->sendMessage($tenant, $recipientPsid, $caption);
        }

        return $result;
    }

    /** Real tap buttons -- see ChatButtons::toMessengerQuickReplies()'s own docblock for the shape/limits. */
    public function sendQuickReplies(Tenant $tenant, string $recipientPsid, string $text, array $buttons): array
    {
        return $this->post($tenant, [
            'recipient' => ['id' => $recipientPsid],
            'messaging_type' => 'RESPONSE',
            'message' => ['text' => $text, 'quick_replies' => ChatButtons::toMessengerQuickReplies($buttons)],
        ]);
    }

    /** Messenger Platform sender action -- real typing_on/typing_off, unlike WhatsApp's read-receipt-triggered indicator, so this genuinely turns off too. */
    public function sendTypingAction(Tenant $tenant, string $recipientPsid, bool $typing): void
    {
        $this->post($tenant, [
            'recipient' => ['id' => $recipientPsid],
            'sender_action' => $typing ? 'typing_on' : 'typing_off',
        ]);
    }

    private function post(Tenant $tenant, array $body): array
    {
        $token = $this->settings->facebookPageAccessToken($tenant);

        if ($token === '') {
            throw new RuntimeException('Facebook page access token is required.');
        }

        try {
            $response = $this->http()->post(self::API_BASE.'/me/messages', $body + ['access_token' => $token]);
        } catch (Throwable $error) {
            throw new RuntimeException('Facebook request failed: '.$error->getMessage(), previous: $error);
        }

        if (! $response->successful()) {
            throw new RuntimeException('Facebook returned HTTP '.$response->status().': '.Arr::get($response->json(), 'error.message', ''));
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * The webhook payload only ever carries the sender's PSID, never a name — see
     * InstagramClient::getUserProfile()'s identical rationale (both ride the same
     * Messenger Platform "User Profile API"). Best-effort: never throws, so a
     * profile-lookup failure never blocks ingesting the actual message.
     */
    public function getUserProfile(Tenant $tenant, string $psid): array
    {
        $token = $this->settings->facebookPageAccessToken($tenant);
        if ($token === '') {
            return [];
        }

        try {
            $response = $this->http()->get(self::API_BASE.'/'.$psid, [
                'fields' => 'first_name,last_name,profile_pic',
                'access_token' => $token,
            ]);
        } catch (Throwable) {
            return [];
        }

        return $response->successful() ? (array) $response->json() : [];
    }

    /** Messenger's inbound attachment URL is directly fetchable, no bearer token needed — unlike Telegram/WhatsApp's two-hop resolve. */
    public function downloadAttachmentUrl(string $url): string
    {
        try {
            $response = $this->http()->get($url);
        } catch (Throwable $error) {
            throw new RuntimeException('Facebook attachment download failed: '.$error->getMessage(), previous: $error);
        }

        if (! $response->successful()) {
            throw new RuntimeException('Facebook attachment download returned HTTP '.$response->status().'.');
        }

        return $response->body();
    }

    private function http(): PendingRequest
    {
        return Http::connectTimeout(5)->timeout(15)->retry(2, 400)->acceptJson();
    }
}
