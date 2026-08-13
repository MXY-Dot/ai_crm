<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Support\Chatwoot\ChatwootClient;
use App\Support\TelegramClient;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ConversationReplyController extends Controller
{
    public function __invoke(Request $request, Conversation $conversation, TenantContext $context, ChatwootClient $chatwoot, TelegramClient $telegram): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($context->id());
        Gate::authorize('update', $tenant);

        if ((int) $conversation->tenant_id !== (int) $tenant->id) {
            abort(404);
        }

        if (! $conversation->external_id) {
            throw ValidationException::withMessages(['conversation' => 'Conversation is not linked to an external channel.']);
        }

        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:4000'],
            'attachment' => ['nullable', 'array'],
            'attachment.url' => ['required_with:attachment', 'string'],
            'attachment.path' => ['required_with:attachment', 'string'],
            'attachment.type' => ['required_with:attachment', Rule::in(['photo', 'voice', 'document'])],
            'attachment.filename' => ['nullable', 'string'],
            'attachment.mime' => ['nullable', 'string'],
        ]);

        $body = trim((string) ($data['body'] ?? ''));
        $attachment = $data['attachment'] ?? null;

        if ($body === '' && ! $attachment) {
            throw ValidationException::withMessages(['body' => 'Message body or attachment is required.']);
        }

        try {
            [$externalId, $meta] = $this->send($tenant, $conversation->loadMissing('channel'), $body, $attachment, $chatwoot, $telegram);
        } catch (RuntimeException $error) {
            throw ValidationException::withMessages(['reply' => $error->getMessage()]);
        }

        $message = Message::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'sender_type' => 'operator',
            'sender_name' => $request->user()?->name,
            'body' => $body !== '' ? $body : $this->attachmentLabel($attachment),
            'external_id' => $externalId,
            'sent_at' => now(),
            'meta' => $attachment ? $meta + ['attachment' => $attachment] : $meta,
        ]);

        $conversation->forceFill([
            'status' => 'open',
            'last_message_at' => now(),
        ])->save();

        return response()->json([
            'ok' => true,
            'message' => $message->fresh(),
            'conversation' => $conversation->fresh(['channel', 'customer', 'lead']),
        ], 201);
    }

    private function send(Tenant $tenant, Conversation $conversation, string $body, ?array $attachment, ChatwootClient $chatwoot, TelegramClient $telegram): array
    {
        if ($conversation->channel?->provider === 'telegram') {
            $chatId = str_replace('telegram-', '', (string) $conversation->external_id);

            $localPath = $attachment ? Storage::disk('public')->path($attachment['path']) : null;
            $filename = $attachment['filename'] ?? 'file';

            $payload = match ($attachment['type'] ?? null) {
                'photo' => $telegram->sendPhoto($tenant, $chatId, $localPath, $filename, $body),
                // Browser-recorded voice notes are webm/opus, which Telegram's format-strict sendVoice
                // may reject (only .ogg/opus, .mp3, .m4a are documented as supported) — sendDocument
                // accepts any format, so it is used here to guarantee delivery over a native voice bubble.
                'voice', 'document' => $telegram->sendDocument($tenant, $chatId, $localPath, $filename, $body),
                default => $telegram->sendMessage($tenant, $chatId, $body),
            };
            $messageId = Arr::get($payload, 'result.message_id');

            return [
                'telegram-'.$chatId.'-'.$messageId,
                ['telegram' => $payload, 'direction' => 'outgoing'],
            ];
        }

        $text = $attachment ? trim($body."\n".$attachment['url']) : $body;
        $payload = $chatwoot->sendOutgoingMessage($tenant, (string) $conversation->external_id, $text);

        return [
            (string) (Arr::get($payload, 'id') ?? Arr::get($payload, 'payload.id') ?? 'outgoing-'.sha1($conversation->id.'|'.$text.'|'.now()->timestamp)),
            ['chatwoot' => $payload, 'direction' => 'outgoing'],
        ];
    }

    private function attachmentLabel(?array $attachment): string
    {
        return match ($attachment['type'] ?? null) {
            'photo' => '📷 Фото',
            'voice' => '🎤 Голосовое сообщение',
            'document' => '📎 '.($attachment['filename'] ?? 'Файл'),
            default => '',
        };
    }
}