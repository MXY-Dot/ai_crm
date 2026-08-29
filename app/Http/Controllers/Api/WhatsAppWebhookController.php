<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\Ai\LlmClient;
use App\Support\Inbox\ChatwootWebhookHandler;
use App\Support\Integrations\MetaChannelResolver;
use App\Support\Meta\VerifiesMetaWebhook;
use App\Support\WhatsAppClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * WhatsApp Cloud API webhook — same shared-URL/tenant-by-payload model as
 * FacebookWebhookController (see its docblock), routed via
 * MetaChannelResolver::byWhatsappPhoneNumberId() using the `phone_number_id`
 * every inbound change carries in `value.metadata`.
 */
class WhatsAppWebhookController extends Controller
{
    use VerifiesMetaWebhook;

    public function __construct(private readonly LlmClient $llm)
    {
    }

    public function __invoke(Request $request, ChatwootWebhookHandler $handler, MetaChannelResolver $resolver, WhatsAppClient $whatsapp): JsonResponse|Response
    {
        if ($request->isMethod('get')) {
            return $this->handleSubscriptionVerification($request) ?? response('', 404);
        }

        $this->guardSignature($request);

        if ($request->input('object') !== 'whatsapp_business_account') {
            return response()->json(['ok' => true, 'ignored' => true, 'reason' => 'unsupported_object']);
        }

        $processed = 0;

        foreach ((array) $request->input('entry', []) as $entry) {
            foreach ((array) Arr::get($entry, 'changes', []) as $change) {
                $value = Arr::get($change, 'value', []);
                $messages = Arr::get($value, 'messages');

                // Non-message changes (delivery/read `statuses`, template status
                // updates, etc.) carry no `messages` key — nothing to ingest.
                if (! is_array($messages) || $messages === []) {
                    continue;
                }

                $phoneNumberId = (string) Arr::get($value, 'metadata.phone_number_id', '');
                $tenant = $resolver->byWhatsappPhoneNumberId($phoneNumberId);

                if (! $tenant) {
                    continue;
                }

                foreach ($messages as $message) {
                    $handler->handle($tenant, $this->payload($tenant, $value, $message, $whatsapp));
                    $processed++;
                }
            }
        }

        return response()->json(['ok' => true, 'processed' => $processed]);
    }

    private function payload(Tenant $tenant, array $value, array $message, WhatsAppClient $whatsapp): array
    {
        $from = (string) Arr::get($message, 'from', '');
        $messageId = (string) Arr::get($message, 'id', sha1(json_encode($message)));
        $repliedId = Arr::get($message, 'context.id');
        $name = $this->contactName($value, $from);
        $attachment = $this->downloadAttachment($tenant, $message, $whatsapp);
        $content = $this->content($message, $attachment);

        return [
            'event' => 'whatsapp_message',
            'provider' => 'whatsapp',
            'inbox' => ['id' => 'whatsapp', 'name' => 'WhatsApp'],
            'conversation' => [
                'id' => 'whatsapp-'.$from,
                'subject' => 'WhatsApp '.$from,
                'status' => 'open',
                'priority' => 'normal',
            ],
            'sender' => [
                'name' => $name !== '' ? $name : $from,
                'type' => 'customer',
                'phone_number' => $from !== '' ? '+'.ltrim($from, '+') : null,
            ],
            'message' => [
                'id' => 'whatsapp-'.$from.'-'.$messageId,
                'content' => $content,
                'reply_to_external_id' => $repliedId ? 'whatsapp-'.$from.'-'.$repliedId : null,
                'attachment' => $attachment,
            ],
        ];
    }

    private function contactName(array $value, string $waId): string
    {
        foreach ((array) Arr::get($value, 'contacts', []) as $contact) {
            if ((string) Arr::get($contact, 'wa_id') === $waId) {
                return trim((string) Arr::get($contact, 'profile.name', ''));
            }
        }

        return '';
    }

    private function content(array $message, ?array $attachment): string
    {
        $type = (string) Arr::get($message, 'type', 'text');
        $text = trim((string) Arr::get($message, 'text.body', ''));

        if ($text !== '') {
            return $text;
        }

        if ($type === 'button') {
            return trim((string) Arr::get($message, 'button.text', ''));
        }

        if ($type === 'interactive') {
            return trim((string) (Arr::get($message, 'interactive.button_reply.title') ?? Arr::get($message, 'interactive.list_reply.title') ?? ''));
        }

        if (! $attachment) {
            return '';
        }

        $transcript = trim((string) ($attachment['transcript'] ?? ''));

        if ($attachment['type'] === 'voice' && $transcript !== '') {
            return '🎤 '.$transcript;
        }

        return match ($attachment['type']) {
            'photo' => '📷 Фото',
            'voice' => '🎤 Голосовое сообщение',
            'video' => '🎥 Видео',
            default => '📎 '.$attachment['filename'],
        };
    }

    /**
     * Downloads incoming media, same two-hop resolve as Telegram (media id ->
     * short-lived URL -> bytes) — see WhatsAppClient::downloadMedia(). WhatsApp's
     * `audio` type covers both voice notes and regular audio files; the
     * `voice: true` flag (present only for actual voice-note recordings)
     * decides which of our own attachment types it maps to.
     */
    private function downloadAttachment(Tenant $tenant, array $message, WhatsAppClient $whatsapp): ?array
    {
        $waType = (string) Arr::get($message, 'type', '');
        $node = Arr::get($message, $waType);

        if (! is_array($node) || ! isset($node['id'])) {
            return null;
        }

        $type = match (true) {
            $waType === 'image' => 'photo',
            $waType === 'audio' => 'voice',
            $waType === 'video' => 'video',
            in_array($waType, ['document', 'sticker'], true) => 'document',
            default => null,
        };

        if ($type === null) {
            return null;
        }

        try {
            $bytes = $whatsapp->downloadMedia($tenant, (string) $node['id']);
        } catch (RuntimeException $error) {
            Log::warning('Failed to download incoming WhatsApp attachment', ['error' => $error->getMessage(), 'type' => $type]);

            return null;
        }

        $mime = (string) Arr::get($node, 'mime_type', 'application/octet-stream');
        $extension = Str::before(Str::afterLast($mime, '/'), ';') ?: 'bin';
        $filename = (string) Arr::get($node, 'filename', $type.'.'.$extension);
        $path = 'attachments/'.$tenant->id.'/'.Str::random(40).'.'.$extension;
        Storage::disk('public')->put($path, $bytes);

        $transcript = $type === 'voice'
            ? $this->llm->transcribeAudio(Storage::disk('public')->path($path))
            : null;

        return [
            'url' => Storage::disk('public')->url($path),
            'path' => $path,
            'filename' => $filename,
            'mime' => $mime,
            'size' => strlen($bytes),
            'type' => $type,
            'transcript' => $transcript,
        ];
    }
}
