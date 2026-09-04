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

/** Direct WhatsApp Cloud API (Meta) client — no Chatwoot involved. */
class WhatsAppClient
{
    private const API_BASE = 'https://graph.facebook.com/v21.0';

    public function __construct(private readonly TenantIntegrationSettings $settings)
    {
    }

    public function sendMessage(Tenant $tenant, string $to, string $text, ?string $replyToMessageId = null): array
    {
        return $this->post($tenant, array_filter([
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'text',
            'text' => ['body' => $text],
            'context' => $replyToMessageId ? ['message_id' => $replyToMessageId] : null,
        ], fn ($value) => $value !== null));
    }

    /**
     * Real tap buttons -- an interactive LIST message (not the "button" type,
     * which hard-caps at 3 total and leaves no room for the assistant-mode
     * option once a real offer already uses all 3). See ChatButtons's own
     * docblock for why a list was chosen over stacked reply buttons.
     */
    public function sendInteractiveList(Tenant $tenant, string $to, string $bodyText, array $buttons, ?string $replyToMessageId = null): array
    {
        return $this->post($tenant, array_filter([
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'interactive',
            'interactive' => ChatButtons::toWhatsAppInteractiveList($bodyText, $buttons),
            'context' => $replyToMessageId ? ['message_id' => $replyToMessageId] : null,
        ], fn ($value) => $value !== null));
    }

    /**
     * WhatsApp accepts outbound media by public URL directly (no need to upload
     * first, unlike Telegram's multipart requirement) — our own attachment public
     * URL (see ConversationAttachmentController) is used as-is.
     */
    public function sendMedia(Tenant $tenant, string $to, string $type, string $url, string $caption = '', ?string $replyToMessageId = null): array
    {
        $field = match ($type) {
            'photo' => 'image',
            'voice' => 'audio',
            default => 'document',
        };
        $waType = $field === 'audio' ? 'audio' : ($field === 'image' ? 'image' : 'document');

        $media = array_filter([
            'link' => $url,
            'caption' => $waType !== 'audio' && $caption !== '' ? $caption : null,
        ], fn ($value) => $value !== null);

        return $this->post($tenant, array_filter([
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => $waType,
            $waType => $media,
            'context' => $replyToMessageId ? ['message_id' => $replyToMessageId] : null,
        ], fn ($value) => $value !== null));
    }

    private function post(Tenant $tenant, array $body): array
    {
        $token = $this->settings->whatsappAccessToken($tenant);
        $phoneNumberId = $this->settings->whatsappPhoneNumberId($tenant);

        if ($token === '' || $phoneNumberId === '') {
            throw new RuntimeException('WhatsApp access token and phone number ID are required.');
        }

        try {
            $response = $this->http($token)->post(self::API_BASE.'/'.$phoneNumberId.'/messages', $body);
        } catch (Throwable $error) {
            throw new RuntimeException('WhatsApp request failed: '.$error->getMessage(), previous: $error);
        }

        if (! $response->successful()) {
            throw new RuntimeException('WhatsApp returned HTTP '.$response->status().': '.Arr::get($response->json(), 'error.message', ''));
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * WhatsApp has no standalone "typing on/off" call like Telegram/Messenger --
     * showing a typing bubble is a side effect of marking a specific INCOMING
     * message as read (Cloud API "typing indicators" feature), so it needs that
     * message's own id, not just the chat. It auto-dismisses after 25s or when a
     * reply is actually sent, whichever comes first -- there is no explicit "off"
     * call to make. https://developers.facebook.com/docs/whatsapp/cloud-api/typing-indicators/
     */
    public function markReadWithTypingIndicator(Tenant $tenant, string $waMessageId): void
    {
        $this->post($tenant, [
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $waMessageId,
            'typing_indicator' => ['type' => 'text'],
        ]);
    }

    /**
     * WhatsApp media is a two-hop resolve, like Telegram: an incoming message
     * only carries a `media id`, which first has to be exchanged for a
     * short-lived CDN URL (`GET /{media-id}`), which itself requires the same
     * bearer token to actually fetch (unlike Messenger, whose attachment URL
     * needs no auth at all).
     */
    public function downloadMedia(Tenant $tenant, string $mediaId): string
    {
        $token = $this->settings->whatsappAccessToken($tenant);

        if ($token === '') {
            throw new RuntimeException('WhatsApp access token is required.');
        }

        try {
            $meta = $this->http($token)->get(self::API_BASE.'/'.$mediaId);
        } catch (Throwable $error) {
            throw new RuntimeException('WhatsApp media lookup failed: '.$error->getMessage(), previous: $error);
        }

        if (! $meta->successful()) {
            throw new RuntimeException('WhatsApp media lookup returned HTTP '.$meta->status().'.');
        }

        $url = Arr::get($meta->json(), 'url');

        if (! $url) {
            throw new RuntimeException('WhatsApp did not return a media URL.');
        }

        try {
            $file = $this->http($token)->get($url);
        } catch (Throwable $error) {
            throw new RuntimeException('WhatsApp media download failed: '.$error->getMessage(), previous: $error);
        }

        if (! $file->successful()) {
            throw new RuntimeException('WhatsApp media download returned HTTP '.$file->status().'.');
        }

        return $file->body();
    }

    public function verifyPhoneNumber(Tenant $tenant): array
    {
        $token = $this->settings->whatsappAccessToken($tenant);
        $phoneNumberId = $this->settings->whatsappPhoneNumberId($tenant);

        if ($token === '' || $phoneNumberId === '') {
            throw new RuntimeException('WhatsApp access token and phone number ID are required.');
        }

        $response = $this->http($token)->get(self::API_BASE.'/'.$phoneNumberId);

        return is_array($response->json()) ? $response->json() : [];
    }

    private function http(string $token): PendingRequest
    {
        return Http::connectTimeout(5)->timeout(15)->retry(2, 400)->acceptJson()->withToken($token);
    }
}
