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
use Illuminate\Support\Str;
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
            'reply_to_message_id' => [
                'nullable',
                Rule::exists('messages', 'id')->where('conversation_id', $conversation->id),
            ],
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
            [$externalId, $meta] = $this->send($tenant, $conversation->loadMissing('channel'), $body, $attachment, $data['reply_to_message_id'] ?? null, $chatwoot, $telegram);
        } catch (RuntimeException $error) {
            throw ValidationException::withMessages(['reply' => $error->getMessage()]);
        }

        $message = Message::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'reply_to_message_id' => $data['reply_to_message_id'] ?? null,
            'sender_type' => 'operator',
            'sender_name' => $request->user()?->name,
            'body' => $body !== '' ? $body : $this->attachmentLabel($attachment),
            'external_id' => $externalId,
            'sent_at' => now(),
            'meta' => $attachment ? $meta + ['attachment' => $attachment] : $meta,
        ]);

        // First operator to reply "claims" the conversation — makes it visible in the
        // sidebar who's already on it, without hard-blocking a second operator from
        // also replying if they want to (no enforcement beyond this soft claim).
        $conversation->forceFill([
            'status' => 'open',
            'last_message_at' => now(),
            'assigned_user_id' => $conversation->assigned_user_id ?? $request->user()->id,
        ])->save();
        $conversation->markFirstResponse();

        return response()->json([
            'ok' => true,
            'message' => $message->fresh()->load('replyTo'),
            'conversation' => $conversation->fresh(['channel', 'customer', 'lead']),
        ], 201);
    }

    private function send(Tenant $tenant, Conversation $conversation, string $body, ?array $attachment, int|string|null $replyToMessageId, ChatwootClient $chatwoot, TelegramClient $telegram): array
    {
        if ($conversation->channel?->provider === 'telegram') {
            $chatId = str_replace('telegram-', '', (string) $conversation->external_id);
            $telegramReplyId = $this->resolveTelegramReplyId($tenant, $conversation, $replyToMessageId);

            $localPath = $attachment ? Storage::disk('public')->path($attachment['path']) : null;
            $filename = $attachment['filename'] ?? 'file';

            $payload = match ($attachment['type'] ?? null) {
                'photo' => $telegram->sendPhoto($tenant, $chatId, $localPath, $filename, $body, $telegramReplyId),
                // ConversationAttachmentController remuxes browser-recorded voice notes to
                // .ogg/opus at upload time specifically so this can be a real sendVoice call
                // and render as a native playable voice bubble on Telegram's side, not a file.
                'voice' => $telegram->sendVoice($tenant, $chatId, $localPath, $filename, $body, $telegramReplyId),
                'document' => $telegram->sendDocument($tenant, $chatId, $localPath, $filename, $body, $telegramReplyId),
                default => $telegram->sendMessage($tenant, $chatId, $body, $telegramReplyId),
            };
            $messageId = Arr::get($payload, 'result.message_id');

            return [
                'telegram-'.$chatId.'-'.$messageId,
                ['telegram' => $payload, 'direction' => 'outgoing'],
            ];
        }

        // A widget conversation has no external platform to push to — same reasoning
        // as AiWorkflow::autoReply()'s 'website' branch. The created Message row is
        // the delivery: WidgetController::index() is what the visitor's browser polls.
        if ($conversation->channel?->provider === 'website') {
            return [
                'widget-'.$conversation->id.'-'.Str::random(12),
                ['direction' => 'outgoing'],
            ];
        }

        $text = $attachment ? trim($body."\n".$attachment['url']) : $body;
        $payload = $chatwoot->sendOutgoingMessage($tenant, (string) $conversation->external_id, $text);

        return [
            (string) (Arr::get($payload, 'id') ?? Arr::get($payload, 'payload.id') ?? 'outgoing-'.sha1($conversation->id.'|'.$text.'|'.now()->timestamp)),
            ['chatwoot' => $payload, 'direction' => 'outgoing'],
        ];
    }

    /**
     * Resolves the WERO reply target (our internal message id) to the numeric Telegram
     * message id Telegram's own reply_parameters expects, by reading it back out of the
     * target message's external_id (format `telegram-{chatId}-{messageId}`, same format
     * used for both inbound and outbound Telegram messages — see MessageController).
     * Returns null (send proceeds as a normal, non-threaded message) if the target has no
     * Telegram-side id, e.g. it was never actually delivered to Telegram.
     */
    private function resolveTelegramReplyId(Tenant $tenant, Conversation $conversation, int|string|null $replyToMessageId): ?string
    {
        if (! $replyToMessageId) {
            return null;
        }

        $externalId = Message::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('conversation_id', $conversation->id)
            ->where('id', $replyToMessageId)
            ->value('external_id');

        if (! $externalId || ! str_starts_with($externalId, 'telegram-')) {
            return null;
        }

        $telegramMessageId = Str::afterLast($externalId, '-');

        return $telegramMessageId !== '' ? $telegramMessageId : null;
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