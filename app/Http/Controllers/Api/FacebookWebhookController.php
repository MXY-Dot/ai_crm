<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\FacebookClient;
use App\Support\Inbox\ChatwootWebhookHandler;
use App\Support\Integrations\MetaChannelResolver;
use App\Support\Meta\VerifiesMetaWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Facebook Messenger webhook. This URL is registered ONCE at the platform App
 * level in Meta's developer console (not per tenant, unlike Telegram) — every
 * subscribed Page's events land here, so each entry is routed to its owning
 * tenant via MetaChannelResolver::byFacebookPageId() using the Page id it
 * carries (facebook.page_id, saved by IntegrationSettingsController).
 */
class FacebookWebhookController extends Controller
{
    use VerifiesMetaWebhook;

    public function __invoke(Request $request, ChatwootWebhookHandler $handler, MetaChannelResolver $resolver, FacebookClient $facebook): JsonResponse|Response
    {
        if ($request->isMethod('get')) {
            return $this->handleSubscriptionVerification($request) ?? response('', 404);
        }

        $this->guardSignature($request);

        if ($request->input('object') !== 'page') {
            return response()->json(['ok' => true, 'ignored' => true, 'reason' => 'unsupported_object']);
        }

        $processed = 0;

        foreach ((array) $request->input('entry', []) as $entry) {
            $pageId = (string) Arr::get($entry, 'id', '');
            $tenant = $resolver->byFacebookPageId($pageId);

            if (! $tenant) {
                continue;
            }

            foreach ((array) Arr::get($entry, 'messaging', []) as $event) {
                if (! $this->shouldIngest($event)) {
                    continue;
                }

                $handler->handle($tenant, $this->payload($tenant, $event, $facebook));
                $processed++;
            }
        }

        return response()->json(['ok' => true, 'processed' => $processed]);
    }

    /** Skips echoes of our own outbound sends, postbacks, delivery/read receipts and anything with neither text nor an attachment. */
    private function shouldIngest(array $event): bool
    {
        if ((bool) Arr::get($event, 'message.is_echo', false)) {
            return false;
        }

        $text = trim((string) Arr::get($event, 'message.text', ''));
        $attachments = Arr::get($event, 'message.attachments');

        return $text !== '' || (is_array($attachments) && $attachments !== []);
    }

    private function payload(Tenant $tenant, array $event, FacebookClient $facebook): array
    {
        $psid = (string) Arr::get($event, 'sender.id', '');
        $messageId = (string) Arr::get($event, 'message.mid', sha1(json_encode($event)));
        $repliedId = Arr::get($event, 'message.reply_to.mid');
        $attachment = $this->downloadAttachment($tenant, $event, $facebook);
        $content = $this->content($event, $attachment);
        $profile = $facebook->getUserProfile($tenant, $psid);
        $displayName = trim(Arr::get($profile, 'first_name', '').' '.Arr::get($profile, 'last_name', '')) ?: 'Facebook user';

        return [
            'event' => 'facebook_message',
            'provider' => 'facebook',
            'inbox' => ['id' => 'facebook', 'name' => 'Facebook Messenger'],
            'conversation' => [
                'id' => 'facebook-'.$psid,
                'subject' => 'Facebook Messenger '.$psid,
                'status' => 'open',
                'priority' => 'normal',
            ],
            'sender' => [
                'name' => $displayName,
                'type' => 'customer',
                'avatar_url' => Arr::get($profile, 'profile_pic'),
            ],
            'message' => [
                'id' => 'facebook-'.$psid.'-'.$messageId,
                'content' => $content,
                'reply_to_external_id' => $repliedId ? 'facebook-'.$psid.'-'.$repliedId : null,
                'attachment' => $attachment,
            ],
        ];
    }

    private function content(array $event, ?array $attachment): string
    {
        $text = trim((string) Arr::get($event, 'message.text', ''));

        if ($text !== '' || ! $attachment) {
            return $text;
        }

        return match ($attachment['type']) {
            'photo' => '📷 Фото',
            'voice' => '🎤 Голосовое сообщение',
            'video' => '🎥 Видео',
            default => '📎 '.$attachment['filename'],
        };
    }

    /**
     * Only the first attachment is handled (matches Telegram's one-attachment-per-message
     * model this CRM's UI/data layer assumes) — the Messenger CDN url needs no auth to
     * fetch, so this is a single hop, unlike WhatsApp/Telegram's two-hop resolve.
     */
    private function downloadAttachment(Tenant $tenant, array $event, FacebookClient $facebook): ?array
    {
        $attachments = Arr::get($event, 'message.attachments');

        if (! is_array($attachments) || $attachments === []) {
            return null;
        }

        $first = $attachments[0];
        $fbType = (string) Arr::get($first, 'type', 'file');
        $url = (string) Arr::get($first, 'payload.url', '');

        if ($url === '') {
            return null;
        }

        $type = match ($fbType) {
            'image' => 'photo',
            'audio' => 'voice',
            'video' => 'video',
            default => 'document',
        };

        try {
            $bytes = $facebook->downloadAttachmentUrl($url);
        } catch (RuntimeException $error) {
            Log::warning('Failed to download incoming Facebook attachment', ['error' => $error->getMessage(), 'type' => $type]);

            return null;
        }

        $extension = match ($type) {
            'photo' => 'jpg',
            'voice' => 'mp4',
            'video' => 'mp4',
            default => 'bin',
        };
        $path = 'attachments/'.$tenant->id.'/'.Str::random(40).'.'.$extension;
        Storage::disk('public')->put($path, $bytes);

        return [
            'url' => Storage::disk('public')->url($path),
            'path' => $path,
            'filename' => basename($path),
            'mime' => match ($type) {
                'photo' => 'image/jpeg',
                'voice' => 'audio/mp4',
                'video' => 'video/mp4',
                default => 'application/octet-stream',
            },
            'size' => strlen($bytes),
            'type' => $type,
        ];
    }
}
