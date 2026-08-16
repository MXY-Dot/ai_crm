<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\Ai\LlmClient;
use App\Support\Inbox\ChatwootWebhookHandler;
use App\Support\Integrations\TenantIntegrationSettings;
use App\Support\TelegramClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class TelegramWebhookController extends Controller
{
    public function __construct(private readonly LlmClient $llm)
    {
    }

    public function __invoke(Request $request, ChatwootWebhookHandler $handler, TenantIntegrationSettings $settings, TelegramClient $telegram): JsonResponse
    {
        $tenant = $this->tenant($request);
        $this->guardSecret($request, $tenant, $settings);
        $message = $request->input('message') ?? $request->input('edited_message');

        // A shared-contact update (tapped the "request contact" keyboard button — see
        // AiWorkflow::requestContact()) has no text/caption of its own, so it would
        // otherwise be dropped by the empty-text guard below. Same reasoning for a
        // photo/document/voice/video sent with no caption text.
        $hasContact = is_array($message) && $this->ownContact($message) !== null;
        $hasMedia = is_array($message) && $this->media($message) !== null;

        if (! is_array($message) || (! $hasContact && ! $hasMedia && $this->text($message) === '')) {
            return response()->json(['ok' => true, 'ignored' => true, 'reason' => 'unsupported_update']);
        }

        $result = $handler->handle($tenant, $this->payload($tenant, $message, $telegram));

        return response()->json(['ok' => true] + $result, ! empty($result['duplicate']) ? 200 : 201);
    }

    private function guardSecret(Request $request, Tenant $tenant, TenantIntegrationSettings $settings): void
    {
        $expected = $settings->telegramWebhookSecret($tenant);

        if ($expected !== '' && ! hash_equals($expected, (string) $request->header('X-Telegram-Bot-Api-Secret-Token', ''))) {
            abort(401, 'Invalid Telegram webhook secret.');
        }
    }

    private function tenant(Request $request): Tenant
    {
        $value = $request->header('X-Tenant-Id') ?? $request->input('tenant_id') ?? $request->input('tenant_slug');

        if (! $value) {
            throw ValidationException::withMessages(['tenant' => 'Tenant context is required.']);
        }

        $tenant = Tenant::query()
            ->where('slug', $value)
            ->when(is_numeric($value), fn ($query) => $query->orWhere('id', $value))
            ->first();

        if (! $tenant) {
            throw ValidationException::withMessages(['tenant' => 'Tenant was not found.']);
        }

        return $tenant;
    }

    private function payload(Tenant $tenant, array $message, TelegramClient $telegram): array
    {
        $chatId = (string) Arr::get($message, 'chat.id', '');
        $messageId = (string) Arr::get($message, 'message_id', sha1(json_encode($message)));
        $name = trim(Arr::get($message, 'from.first_name', '').' '.Arr::get($message, 'from.last_name', ''));
        $repliedId = Arr::get($message, 'reply_to_message.message_id');
        $contact = $this->ownContact($message);
        $attachment = $this->downloadAttachment($tenant, $message, $telegram);
        $content = $this->content($message, $contact, $attachment);

        return [
            'event' => 'telegram_message',
            'provider' => 'telegram',
            'inbox' => ['id' => 'telegram', 'name' => 'Telegram Bot'],
            'conversation' => [
                'id' => 'telegram-'.$chatId,
                'subject' => 'Telegram chat '.$chatId,
                'status' => 'open',
                'priority' => 'normal',
            ],
            'sender' => [
                'name' => $name !== '' ? $name : (Arr::get($message, 'from.username') ?? 'Telegram user'),
                'type' => 'customer',
                'phone_number' => $contact ? Arr::get($contact, 'phone_number') : null,
            ],
            'message' => [
                'id' => 'telegram-'.$chatId.'-'.$messageId,
                'content' => $content,
                'reply_to_external_id' => $repliedId ? 'telegram-'.$chatId.'-'.$repliedId : null,
                'attachment' => $attachment,
            ],
        ];
    }

    private function content(array $message, ?array $contact, ?array $attachment): string
    {
        if ($contact) {
            return '📱 Поделился(-ась) контактом';
        }

        $text = $this->text($message);

        if ($text !== '' || ! $attachment) {
            return $text;
        }

        // Voice notes can't carry a Telegram caption at all (Bot API has no caption
        // field for `voice`), so this is the *only* path a voice message ever takes —
        // use the transcript (see LlmClient::transcribeAudio()) as the actual message
        // content when available, so the AI can understand and answer it instead of
        // just acknowledging "got your voice note".
        $transcript = trim((string) ($attachment['transcript'] ?? ''));

        if ($attachment['type'] === 'voice' && $transcript !== '') {
            return '🎤 '.$transcript;
        }

        // No caption on the media itself — fall back to the same emoji-prefixed
        // placeholders ChatMessageItem.vue already recognizes and hides once the
        // real attachment renders (see bodyIsAttachmentPlaceholder there), matching
        // the convention ConversationReplyController::attachmentLabel() uses for
        // outgoing attachments.
        return match ($attachment['type']) {
            'photo' => '📷 Фото',
            'voice' => '🎤 Голосовое сообщение',
            'video' => '🎥 Видео',
            default => '📎 '.$attachment['filename'],
        };
    }

    private function text(array $message): string
    {
        return trim((string) (Arr::get($message, 'text') ?? Arr::get($message, 'caption') ?? ''));
    }

    /**
     * Only trusts a `contact` payload when it's the sender's own number (Telegram's
     * request_contact keyboard button enforces this client-side, but a raw API call
     * could in principle attach someone else's contact card — don't trust that as
     * the sender's phone).
     */
    private function ownContact(array $message): ?array
    {
        $contact = Arr::get($message, 'contact');

        if (! is_array($contact) || Arr::get($contact, 'user_id') !== Arr::get($message, 'from.id')) {
            return null;
        }

        return $contact;
    }

    /**
     * Detects which (if any) of Telegram's media fields are present and normalizes
     * them to a `file_id` + our own attachment `type` + a best-effort filename/mime.
     * `photo` is an array of `PhotoSize` at increasing resolutions — picks the largest
     * by pixel area rather than trusting array order (Telegram doesn't formally
     * guarantee ascending order, even though that's the de facto behavior).
     * `audio` (music/podcast files, distinct from `voice` notes) and `video_note`
     * (the circular "video message") map onto the existing `document`/`video` types
     * rather than getting dedicated ones — no UI reason to distinguish them further.
     */
    private function media(array $message): ?array
    {
        $photos = Arr::get($message, 'photo');

        if (is_array($photos) && $photos !== []) {
            $largest = collect($photos)->sortByDesc(fn ($p) => ((int) ($p['width'] ?? 0)) * ((int) ($p['height'] ?? 0)))->first();

            return ['type' => 'photo', 'file_id' => $largest['file_id'], 'filename' => 'photo.jpg', 'mime' => 'image/jpeg'];
        }

        if ($document = Arr::get($message, 'document')) {
            return [
                'type' => 'document',
                'file_id' => $document['file_id'],
                'filename' => $document['file_name'] ?? 'file',
                'mime' => $document['mime_type'] ?? 'application/octet-stream',
            ];
        }

        if ($voice = Arr::get($message, 'voice')) {
            return ['type' => 'voice', 'file_id' => $voice['file_id'], 'filename' => 'voice.ogg', 'mime' => $voice['mime_type'] ?? 'audio/ogg'];
        }

        if ($video = Arr::get($message, 'video')) {
            return ['type' => 'video', 'file_id' => $video['file_id'], 'filename' => 'video.mp4', 'mime' => $video['mime_type'] ?? 'video/mp4'];
        }

        if ($videoNote = Arr::get($message, 'video_note')) {
            return ['type' => 'video', 'file_id' => $videoNote['file_id'], 'filename' => 'video.mp4', 'mime' => 'video/mp4'];
        }

        if ($audio = Arr::get($message, 'audio')) {
            return [
                'type' => 'document',
                'file_id' => $audio['file_id'],
                'filename' => $audio['file_name'] ?? (($audio['title'] ?? 'audio').'.mp3'),
                'mime' => $audio['mime_type'] ?? 'audio/mpeg',
            ];
        }

        return null;
    }

    /**
     * Downloads the media (if any) from Telegram and stores it exactly where operator
     * uploads land (ConversationAttachmentController — `attachments/{tenant_id}/...`
     * on the `public` disk), so the frontend's existing attachment renderer needs no
     * knowledge of which side sent it. Best-effort: a download failure degrades to a
     * plain text message (with a note in the body) rather than losing the message
     * entirely — Telegram doesn't retry a webhook that returned 200, so failing hard
     * here would silently drop the customer's message.
     */
    private function downloadAttachment(Tenant $tenant, array $message, TelegramClient $telegram): ?array
    {
        $media = $this->media($message);

        if (! $media) {
            return null;
        }

        try {
            $bytes = $telegram->downloadFile($tenant, $media['file_id']);
        } catch (RuntimeException $error) {
            Log::warning('Failed to download incoming Telegram attachment', ['error' => $error->getMessage(), 'type' => $media['type']]);

            return null;
        }

        $extension = pathinfo($media['filename'], PATHINFO_EXTENSION) ?: 'bin';
        $path = 'attachments/'.$tenant->id.'/'.Str::random(40).'.'.$extension;
        Storage::disk('public')->put($path, $bytes);

        $transcript = $media['type'] === 'voice'
            ? $this->llm->transcribeAudio(Storage::disk('public')->path($path))
            : null;

        return [
            'url' => Storage::disk('public')->url($path),
            'path' => $path,
            'filename' => $media['filename'],
            'mime' => $media['mime'],
            'size' => strlen($bytes),
            'type' => $media['type'],
            'transcript' => $transcript,
        ];
    }
}
