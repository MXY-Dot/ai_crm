<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\Inbox\ChatwootWebhookHandler;
use App\Support\InstagramClient;
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
 * Instagram Direct webhook — same shared-URL/tenant-by-payload model as
 * FacebookWebhookController (see its docblock), routed via
 * MetaChannelResolver::byInstagramBusinessAccountId() instead of a page id.
 */
class InstagramWebhookController extends Controller
{
    use VerifiesMetaWebhook;

    public function __invoke(Request $request, ChatwootWebhookHandler $handler, MetaChannelResolver $resolver, InstagramClient $instagram): JsonResponse|Response
    {
        if ($request->isMethod('get')) {
            return $this->handleSubscriptionVerification($request) ?? response('', 404);
        }

        $this->guardSignature($request, 'services.meta.instagram_app_secret');

        if ($request->input('object') !== 'instagram') {
            return response()->json(['ok' => true, 'ignored' => true, 'reason' => 'unsupported_object']);
        }

        $processed = 0;

        foreach ((array) $request->input('entry', []) as $entry) {
            $accountId = (string) Arr::get($entry, 'id', '');
            $tenant = $resolver->byInstagramBusinessAccountId($accountId);

            if (! $tenant) {
                continue;
            }

            foreach ((array) Arr::get($entry, 'messaging', []) as $event) {
                if (! $this->shouldIngest($event)) {
                    continue;
                }

                $handler->handle($tenant, $this->payload($tenant, $event, $instagram));
                $processed++;
            }
        }

        return response()->json(['ok' => true, 'processed' => $processed]);
    }

    private function shouldIngest(array $event): bool
    {
        if ((bool) Arr::get($event, 'message.is_echo', false)) {
            return false;
        }

        $text = trim((string) Arr::get($event, 'message.text', ''));
        $attachments = Arr::get($event, 'message.attachments');

        return $text !== '' || (is_array($attachments) && $attachments !== []);
    }

    private function payload(Tenant $tenant, array $event, InstagramClient $instagram): array
    {
        $igsid = (string) Arr::get($event, 'sender.id', '');
        $messageId = (string) Arr::get($event, 'message.mid', sha1(json_encode($event)));
        $attachment = $this->downloadAttachment($tenant, $event, $instagram);
        $content = $this->content($event, $attachment);
        $profile = $instagram->getUserProfile($tenant, $igsid);
        $displayName = (string) (Arr::get($profile, 'name') ?: Arr::get($profile, 'username') ?: 'Instagram user');

        return [
            'event' => 'instagram_message',
            'provider' => 'instagram',
            'inbox' => ['id' => 'instagram', 'name' => 'Instagram Direct'],
            'conversation' => [
                'id' => 'instagram-'.$igsid,
                'subject' => 'Instagram Direct '.$igsid,
                'status' => 'open',
                'priority' => 'normal',
            ],
            'sender' => [
                'name' => $displayName,
                'type' => 'customer',
                'avatar_url' => Arr::get($profile, 'profile_pic'),
            ],
            'message' => [
                'id' => 'instagram-'.$igsid.'-'.$messageId,
                'content' => $content,
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

    private function downloadAttachment(Tenant $tenant, array $event, InstagramClient $instagram): ?array
    {
        $attachments = Arr::get($event, 'message.attachments');

        if (! is_array($attachments) || $attachments === []) {
            return null;
        }

        $first = $attachments[0];
        $igType = (string) Arr::get($first, 'type', 'file');
        $url = (string) Arr::get($first, 'payload.url', '');

        if ($url === '') {
            return null;
        }

        $type = match ($igType) {
            'image' => 'photo',
            'audio' => 'voice',
            'video', 'ig_reel' => 'video',
            default => 'document',
        };

        try {
            $bytes = $instagram->downloadAttachmentUrl($url);
        } catch (RuntimeException $error) {
            Log::warning('Failed to download incoming Instagram attachment', ['error' => $error->getMessage(), 'type' => $type]);

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
