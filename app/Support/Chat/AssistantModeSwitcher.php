<?php

namespace App\Support\Chat;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Support\FacebookClient;
use App\Support\InstagramClient;
use App\Support\TelegramClient;
use App\Support\WhatsAppClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Handles a tap of the shared "💬 Поговорить с ассистентом" button (see
 * ChatButtons::withAssistantOption()) -- identical handling regardless of
 * which module's offer the customer was looking at, so both
 * TelegramWebhookController and WhatsAppWebhookController call this instead
 * of duplicating it. Two things happen: (1) a short inviting reply is sent
 * and persisted as a real 'ai'-sender Message, and (2) that message's own
 * meta explicitly clears `flow` -- every *ChatAssistant's own lastAiMeta()
 * reads only the LATEST 'ai' row, so this is what stops the customer's next
 * free-text message from being treated as a stale pick against the
 * abandoned offer (see e.g. AiChatBookingAssistant::reofferForPendingFlow()'s
 * own docblock for why that safety net exists in the first place).
 */
class AssistantModeSwitcher
{
    private const REPLY_TEXT = 'Хорошо! Пишите в свободной форме — я подскажу и отвечу.';

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly WhatsAppClient $whatsapp,
        private readonly InstagramClient $instagram,
        private readonly FacebookClient $facebook,
    ) {
    }

    public function handleTelegram(Tenant $tenant, Conversation $conversation, string $chatId): void
    {
        $externalId = 'telegram-assistant-'.$chatId.'-'.Str::random(8);

        try {
            $payload = $this->telegram->sendMessage($tenant, $chatId, self::REPLY_TEXT);
            $externalId = 'telegram-'.$chatId.'-'.Arr::get($payload, 'result.message_id');
        } catch (RuntimeException) {
        }

        $this->persist($tenant, $conversation, $externalId);
    }

    public function handleWhatsapp(Tenant $tenant, Conversation $conversation, string $recipient): void
    {
        $externalId = 'whatsapp-assistant-'.$recipient.'-'.Str::random(8);

        try {
            $payload = $this->whatsapp->sendMessage($tenant, $recipient, self::REPLY_TEXT);
            $messageId = Arr::get($payload, 'messages.0.id');
            $externalId = 'whatsapp-'.$recipient.'-'.($messageId ?? Str::random(12));
        } catch (RuntimeException) {
        }

        $this->persist($tenant, $conversation, $externalId);
    }

    public function handleInstagram(Tenant $tenant, Conversation $conversation, string $igsid): void
    {
        $externalId = 'instagram-assistant-'.$igsid.'-'.Str::random(8);

        try {
            $payload = $this->instagram->sendMessage($tenant, $igsid, self::REPLY_TEXT);
            $messageId = Arr::get($payload, 'message_id');
            $externalId = 'instagram-'.$igsid.'-'.($messageId ?? Str::random(12));
        } catch (RuntimeException) {
        }

        $this->persist($tenant, $conversation, $externalId);
    }

    public function handleFacebook(Tenant $tenant, Conversation $conversation, string $psid): void
    {
        $externalId = 'facebook-assistant-'.$psid.'-'.Str::random(8);

        try {
            $payload = $this->facebook->sendMessage($tenant, $psid, self::REPLY_TEXT);
            $messageId = Arr::get($payload, 'message_id');
            $externalId = 'facebook-'.$psid.'-'.($messageId ?? Str::random(12));
        } catch (RuntimeException) {
        }

        $this->persist($tenant, $conversation, $externalId);
    }

    private function persist(Tenant $tenant, Conversation $conversation, string $externalId): void
    {
        Message::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'sender_type' => 'ai',
            'sender_name' => 'WERO AI',
            'body' => self::REPLY_TEXT,
            'external_id' => $externalId,
            'sent_at' => now(),
            'meta' => ['flow' => null, 'system' => true],
        ]);
    }
}
