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
            'body' => ['required', 'string', 'min:1', 'max:4000'],
        ]);

        try {
            [$externalId, $meta] = $this->send($tenant, $conversation->loadMissing('channel'), $data['body'], $chatwoot, $telegram);
        } catch (RuntimeException $error) {
            throw ValidationException::withMessages(['reply' => $error->getMessage()]);
        }

        $message = Message::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'sender_type' => 'operator',
            'sender_name' => $request->user()?->name,
            'body' => $data['body'],
            'external_id' => $externalId,
            'sent_at' => now(),
            'meta' => $meta,
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

    private function send(Tenant $tenant, Conversation $conversation, string $body, ChatwootClient $chatwoot, TelegramClient $telegram): array
    {
        if ($conversation->channel?->provider === 'telegram') {
            $chatId = str_replace('telegram-', '', (string) $conversation->external_id);
            $payload = $telegram->sendMessage($tenant, $chatId, $body);
            $messageId = Arr::get($payload, 'result.message_id');

            return [
                'telegram-'.$chatId.'-'.$messageId,
                ['telegram' => $payload, 'direction' => 'outgoing'],
            ];
        }

        $payload = $chatwoot->sendOutgoingMessage($tenant, (string) $conversation->external_id, $body);

        return [
            (string) (Arr::get($payload, 'id') ?? Arr::get($payload, 'payload.id') ?? 'outgoing-'.sha1($conversation->id.'|'.$body.'|'.now()->timestamp)),
            ['chatwoot' => $payload, 'direction' => 'outgoing'],
        ];
    }
}