<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\Tenant;
use App\Support\TelegramClient;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Edit/delete for messages WERO itself sent (sender_type = 'operator') — never
 * for customer- or AI-authored messages, which are a record of what was actually
 * said. Both best-effort mirror the change to Telegram when the conversation is
 * Telegram-routed (chat id + Telegram message id are parseable back out of
 * `external_id`, format `telegram-{chatId}-{messageId}` — see
 * ConversationReplyController::send()); Chatwoot-routed conversations only get
 * the WERO-side change (ChatwootClient has no edit/delete API wired up, and
 * duplicating that surface wasn't needed for this batch of work).
 */
class MessageController extends Controller
{
    public function update(Request $request, Message $message, TenantContext $context, TelegramClient $telegram): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($context->id());
        Gate::authorize('update', $tenant);
        abort_unless((int) $message->tenant_id === (int) $tenant->id, 404);

        if ($message->sender_type !== 'operator') {
            throw ValidationException::withMessages(['message' => 'Only your own messages can be edited.']);
        }

        if ($message->deleted_at) {
            throw ValidationException::withMessages(['message' => 'This message was deleted.']);
        }

        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);
        $body = trim($data['body']);

        $message->loadMissing('conversation.channel');
        $telegramSynced = null;

        if ($message->conversation->channel?->provider === 'telegram' && $message->external_id) {
            [$chatId, $telegramMessageId] = $this->parseTelegramExternalId($message->external_id);

            if ($chatId && $telegramMessageId) {
                $telegramSynced = $telegram->editMessageText($tenant, $chatId, $telegramMessageId, $body);
            }
        }

        $message->forceFill(['body' => $body, 'edited_at' => now()])->save();

        return response()->json(['ok' => true, 'message' => $message->fresh()->load('replyTo'), 'telegram_synced' => $telegramSynced]);
    }

    public function destroy(Message $message, TenantContext $context, TelegramClient $telegram): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($context->id());
        Gate::authorize('update', $tenant);
        abort_unless((int) $message->tenant_id === (int) $tenant->id, 404);

        if ($message->sender_type !== 'operator') {
            throw ValidationException::withMessages(['message' => 'Only your own messages can be deleted.']);
        }

        $message->loadMissing('conversation.channel');
        $telegramSynced = null;

        if ($message->conversation->channel?->provider === 'telegram' && $message->external_id) {
            [$chatId, $telegramMessageId] = $this->parseTelegramExternalId($message->external_id);

            if ($chatId && $telegramMessageId) {
                $telegramSynced = $telegram->deleteMessage($tenant, $chatId, $telegramMessageId);
            }
        }

        $message->forceFill(['deleted_at' => now(), 'body' => ''])->save();

        return response()->json(['ok' => true, 'message' => $message->fresh(), 'telegram_synced' => $telegramSynced]);
    }

    /** @return array{0: ?string, 1: ?string} [chatId, telegramMessageId] */
    private function parseTelegramExternalId(string $externalId): array
    {
        if (! str_starts_with($externalId, 'telegram-')) {
            return [null, null];
        }

        $rest = Str::after($externalId, 'telegram-');
        $chatId = Str::beforeLast($rest, '-');
        $messageId = Str::afterLast($rest, '-');

        return $chatId !== '' && $messageId !== '' ? [$chatId, $messageId] : [null, null];
    }
}
