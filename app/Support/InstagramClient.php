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
 * Direct Instagram messaging (Graph API) client — no Chatwoot involved. Uses the
 * `/me/messages` edge with the account's own access token via graph.instagram.com
 * — Meta's current "Instagram Business Login" (Instagram API with Instagram Login)
 * flow, which is what Meta's own setup wizard leads a new tenant to today. Found
 * live: a token from this flow (IGAA-prefixed) is rejected by graph.facebook.com
 * with "Cannot parse access token" even though it's completely valid — confirmed
 * the same token succeeds against graph.instagram.com for both `/me` and a direct
 * account-id lookup. The `instagram.page_access_token` setting name is legacy
 * (predates this fix, when the client targeted the older Page-linked flow) but the
 * value it holds is genuinely the Instagram account's own access token either way.
 *
 * Deliberately has no sendQuickReplies() the way FacebookClient does -- found
 * live: sending a `quick_replies` payload here returns success (a real
 * message_id, no error) but the customer never actually sees any buttons,
 * just the plain text. Matches Meta's own documented gap (quick replies are
 * a Messenger-only feature, not part of Instagram Messaging's supported set)
 * rather than a bug on our side. AiWorkflow::autoReplyMeta() only calls
 * sendMessage() for this provider now.
 */
class InstagramClient
{
    private const API_BASE = 'https://graph.instagram.com/v21.0';

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

    /** Same sender_action mechanism as Messenger (Instagram DMs ride the same Messenger Platform infrastructure) -- real typing_on/typing_off. */
    public function sendTypingAction(Tenant $tenant, string $recipientIgsid, bool $typing): void
    {
        $this->post($tenant, [
            'recipient' => ['id' => $recipientIgsid],
            'sender_action' => $typing ? 'typing_on' : 'typing_off',
        ]);
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

    /**
     * The webhook payload only ever carries the sender's IGSID, never a name — this is
     * the only way to learn who's actually messaging (matches Messenger Platform's own
     * "User Profile API": name/username/profile_pic for anyone who's messaged the
     * business, https://developers.facebook.com/docs/messenger-platform/instagram/features/user-profile).
     * Best-effort: returns an empty array (never throws) so a profile-lookup failure
     * never blocks ingesting the actual message.
     */
    public function getUserProfile(Tenant $tenant, string $igsid): array
    {
        $token = $this->settings->instagramPageAccessToken($tenant);
        if ($token === '') {
            return [];
        }

        try {
            $response = $this->http()->get(self::API_BASE.'/'.$igsid, [
                'fields' => 'name,username,profile_pic',
                'access_token' => $token,
            ]);
        } catch (Throwable) {
            return [];
        }

        return $response->successful() ? (array) $response->json() : [];
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
