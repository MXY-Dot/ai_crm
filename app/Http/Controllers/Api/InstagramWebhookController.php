<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Support\Chat\AssistantModeSwitcher;
use App\Support\Chat\ChatButtonPager;
use App\Support\Chat\ChatButtons;
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

    public function __invoke(Request $request, ChatwootWebhookHandler $handler, MetaChannelResolver $resolver, InstagramClient $instagram, AssistantModeSwitcher $modeSwitcher, ChatButtonPager $pager): JsonResponse|Response
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

                // A tap on a quick reply (see ChatButtons::toMessengerQuickReplies())
                // arrives as a normal text message event PLUS this payload field --
                // the assistant/pagination sentinels are intercepted here, before the
                // normal pipeline, same reasoning as Telegram's callback_query and
                // WhatsApp's interactive.list_reply.id handling. A numbered pick needs
                // no special-casing: content() below prefers the payload digit over
                // the button's own title text, so it flows through unchanged.
                $quickReplyPayload = trim((string) Arr::get($event, 'message.quick_reply.payload', ''));
                $igsid = (string) Arr::get($event, 'sender.id', '');

                if ($quickReplyPayload !== '' && $igsid !== '') {
                    $conversation = Conversation::withoutGlobalScopes()
                        ->where('tenant_id', $tenant->id)
                        ->where('external_id', 'instagram-'.$igsid)
                        ->latest('id')
                        ->first();

                    if ($conversation && $quickReplyPayload === ChatButtons::ASSISTANT_BUTTON_ID) {
                        $modeSwitcher->handleInstagram($tenant, $conversation, $igsid);
                        $processed++;

                        continue;
                    }

                    if ($conversation && ChatButtons::isPageRequest($quickReplyPayload)) {
                        $lastMeta = $this->lastAiMeta($conversation);
                        $pager->handleInstagram($tenant, $conversation, $igsid, $lastMeta, ChatButtons::pageFromId($quickReplyPayload));
                        $processed++;

                        continue;
                    }
                }

                $handler->handle($tenant, $this->payload($tenant, $event, $instagram));
                $processed++;
            }
        }

        return response()->json(['ok' => true, 'processed' => $processed]);
    }

    /** Same "latest ai-sender row's own meta" convention every *ChatAssistant's own lastAiMeta() already uses. */
    private function lastAiMeta(Conversation $conversation): array
    {
        $meta = Message::withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->where('sender_type', 'ai')
            ->latest('id')
            ->value('meta');

        return is_array($meta) ? $meta : [];
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
        // A numbered pick's quick-reply payload is the plain option number
        // itself (see ChatButtons::toMessengerQuickReplies()) -- prefer that
        // over the button's own visible title (which Messenger also echoes
        // into message.text) so a tap resolves exactly as unambiguously as a
        // typed digit does. The assistant/page sentinels never reach here
        // (intercepted in __invoke()).
        $quickReplyPayload = trim((string) Arr::get($event, 'message.quick_reply.payload', ''));

        if ($quickReplyPayload !== '' && ctype_digit($quickReplyPayload)) {
            return $quickReplyPayload;
        }

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
