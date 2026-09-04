<?php

namespace App\Support\Chat;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Support\TelegramClient;
use App\Support\WhatsAppClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Handles a "◀ Назад"/"Далее ▶" tap (see ChatButtons::forOffer()'s own
 * pagination rows) -- re-sends the SAME offer with a different page of
 * buttons, without re-running any booking/availability logic at all. The
 * raw (unpaginated, un-numbered-reset) option list rides on the previous
 * ai message's own meta.raw_buttons; every other meta key (flow,
 * offered_slots/offered_groups/offered_departures, etc.) carries over
 * completely unchanged, so a numbered pick on the new page still resolves
 * through the exact same *ChatAssistant::continueFlow() logic a pick on
 * page 0 always has -- see ChatButtons::forOffer()'s own docblock for why
 * a button's `id` is never renumbered when it's sliced onto a later page.
 *
 * Never actually exercised by real data today (every *ChatContext's own
 * OFFER_LIMIT is 3, well under ChatButtons::forOffer()'s own per-page
 * cap) -- exists so a future module offering more doesn't hit a dead end.
 */
class ChatButtonPager
{
    private const PAGE_INTRO = 'Вот доступные варианты — выберите один ниже 👇';

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly WhatsAppClient $whatsapp,
    ) {
    }

    public function handleTelegram(Tenant $tenant, Conversation $conversation, string $chatId, array $lastMeta, int $page): void
    {
        $buttons = $this->pageButtons($lastMeta, $page);

        if ($buttons === null) {
            return;
        }

        $externalId = 'telegram-page-'.$chatId.'-'.Str::random(8);

        try {
            $payload = $this->telegram->sendMessage($tenant, $chatId, self::PAGE_INTRO, replyMarkup: ChatButtons::toTelegramInlineKeyboard($buttons));
            $externalId = 'telegram-'.$chatId.'-'.Arr::get($payload, 'result.message_id');
        } catch (RuntimeException) {
        }

        $this->persist($tenant, $conversation, $externalId, $lastMeta, $buttons);
    }

    public function handleWhatsapp(Tenant $tenant, Conversation $conversation, string $recipient, array $lastMeta, int $page): void
    {
        $buttons = $this->pageButtons($lastMeta, $page);

        if ($buttons === null) {
            return;
        }

        $externalId = 'whatsapp-page-'.$recipient.'-'.Str::random(8);

        try {
            $payload = $this->whatsapp->sendInteractiveList($tenant, $recipient, self::PAGE_INTRO, $buttons);
            $messageId = Arr::get($payload, 'messages.0.id');
            $externalId = 'whatsapp-'.$recipient.'-'.($messageId ?? Str::random(12));
        } catch (RuntimeException) {
        }

        $this->persist($tenant, $conversation, $externalId, $lastMeta, $buttons);
    }

    /** @return array<int, array{id:string, title:string, description?:string}>|null */
    private function pageButtons(array $lastMeta, int $page): ?array
    {
        $raw = is_array($lastMeta['raw_buttons'] ?? null) ? $lastMeta['raw_buttons'] : null;

        return $raw === null ? null : ChatButtons::forOffer($raw, $page);
    }

    private function persist(Tenant $tenant, Conversation $conversation, string $externalId, array $lastMeta, array $buttons): void
    {
        Message::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'sender_type' => 'ai',
            'sender_name' => 'WERO AI',
            'body' => self::PAGE_INTRO,
            'external_id' => $externalId,
            'sent_at' => now(),
            'meta' => [...$lastMeta, 'buttons' => $buttons],
        ]);
    }
}
