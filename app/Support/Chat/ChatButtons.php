<?php

namespace App\Support\Chat;

/**
 * Shared button-shape helpers used by every module's own *OfferButtons
 * builder (BookingOfferButtons, TableReservationOfferButtons, etc. -- kept
 * one file per module on purpose, mirroring how each module already has its
 * own ChatContext/ChatAssistant rather than one generic class covering all
 * of them) and by AiWorkflow's own delivery chokepoint (autoReply()/
 * autoReplyMeta()), which translates the normalized
 * {id, title, description?} shape below into each platform's real button
 * payload. Only "how do I turn one offered slot into button text" is
 * per-module; everything platform-shaped lives here once.
 */
class ChatButtons
{
    public const ASSISTANT_BUTTON_ID = 'assistant';

    /**
     * Every offer's button set ends with this one -- the customer can always
     * opt out of tapping and just type freely instead ("Поговорить с
     * ассистентом"), same as they always could before buttons existed, but
     * now an explicit, discoverable option rather than something only
     * obvious to look for. TelegramWebhookController/WhatsAppWebhookController
     * both special-case ASSISTANT_BUTTON_ID before it ever reaches the normal
     * message pipeline -- see their own switchToAssistantMode().
     *
     * @param array<int, array{id:string, title:string, description?:string}> $buttons
     * @return array<int, array{id:string, title:string, description?:string}>
     */
    public static function withAssistantOption(array $buttons): array
    {
        return [...$buttons, ['id' => self::ASSISTANT_BUTTON_ID, 'title' => '💬 Поговорить с ассистентом']];
    }

    public const PAGE_ID_PREFIX = 'page:';

    private const PER_PAGE = 7;

    /**
     * Wraps a module's own raw numbered picks (no assistant option, no
     * pagination -- see e.g. BookingOfferButtons::build()) into one page's
     * worth of real, sendable buttons: up to PER_PAGE picks for $page, a
     * "◀ Назад"/"Далее ▶" nav row where needed, and the shared assistant
     * option always last. Every existing offer flow has at most 3 picks
     * (OFFER_LIMIT everywhere), so $page is always 0 and totalPages always
     * 1 in practice today -- this exists so a future module offering more
     * doesn't silently blow past WhatsApp's 10-row hard cap (PER_PAGE=7 +
     * up to 2 nav rows + 1 assistant row = 10 max). Each option's own `id`
     * is set by the *OfferButtons builder as its absolute position in the
     * FULL list (e.g. "8" on page 2, not renumbered to "1") -- slicing here
     * never touches `id`, so a pick on any page still resolves through the
     * exact same 0-based `selected_offer_index` matching every
     * *ChatAssistant::continueFlow() already does for a typed digit.
     *
     * @param array<int, array{id:string, title:string, description?:string}> $rawOptions
     * @return array<int, array{id:string, title:string, description?:string}>
     */
    public static function forOffer(array $rawOptions, int $page = 0): array
    {
        $totalPages = max(1, (int) ceil(count($rawOptions) / self::PER_PAGE));
        $page = max(0, min($page, $totalPages - 1));
        $slice = array_slice($rawOptions, $page * self::PER_PAGE, self::PER_PAGE);

        $nav = [];

        if ($page > 0) {
            $nav[] = ['id' => self::PAGE_ID_PREFIX.($page - 1), 'title' => '◀ Назад'];
        }

        if ($page < $totalPages - 1) {
            $nav[] = ['id' => self::PAGE_ID_PREFIX.($page + 1), 'title' => 'Далее ▶'];
        }

        return self::withAssistantOption([...$slice, ...$nav]);
    }

    public static function isPageRequest(string $buttonId): bool
    {
        return str_starts_with($buttonId, self::PAGE_ID_PREFIX);
    }

    public static function pageFromId(string $buttonId): int
    {
        return (int) substr($buttonId, strlen(self::PAGE_ID_PREFIX));
    }

    /**
     * When real tap buttons are attached, the enumerated "1) ... 2) ... 3) ..."
     * list and the "напишите номер..." instruction in the assistant's own
     * text become pure duplication -- the buttons themselves already show
     * each option, and there's nothing left to "write a number" for. Strips
     * both mechanically rather than each of the 5 *OfferButtons builders
     * (or their owning *ChatAssistant) maintaining a second text variant:
     * every module's own offer text follows the identical
     * "{intro}:\n{numbered list}\n{напишите номер instruction}" shape (see
     * e.g. AiChatBookingAssistant::offerNewBookingSlots()), so a numbered-
     * line filter plus one fixed instruction phrase covers all 5 uniformly.
     */
    public static function shortenForButtons(string $text): string
    {
        $kept = collect(explode("\n", $text))
            ->reject(fn (string $line): bool => (bool) preg_match('/^\d+\)\s/', trim($line)))
            ->reject(fn (string $line): bool => str_contains($line, 'Напишите номер'))
            ->map(fn (string $line): string => rtrim($line))
            ->filter(fn (string $line): bool => $line !== '')
            ->implode("\n");

        return trim($kept).' Выберите вариант ниже 👇';
    }

    /**
     * Telegram inline keyboard -- one button per row, callback_data = the
     * button id verbatim (a tiny digit or 'assistant', always well under
     * Telegram's 64-byte callback_data cap). Combines `title`+`description`
     * into one label -- Telegram buttons have no separate title/description
     * split the way a WhatsApp list row does, and `title` alone is often not
     * enough to tell offers apart (e.g. two slots with the same employee but
     * different times, or two employees at the same time).
     */
    public static function toTelegramInlineKeyboard(array $buttons): array
    {
        return [
            'inline_keyboard' => collect($buttons)
                ->map(fn (array $b) => [['text' => self::truncate(isset($b['description']) ? $b['title'].' — '.$b['description'] : $b['title'], 60), 'callback_data' => $b['id']]])
                ->values()->all(),
        ];
    }

    /**
     * WhatsApp Cloud API interactive LIST message -- deliberately not the
     * "button" interactive type, which hard-caps at 3 buttons total and so
     * leaves no room for the assistant option once a real offer already
     * uses all 3 (every *ChatContext's own OFFER_LIMIT is 3). A list fits up
     * to 10 rows, comfortably covering 3 offered picks + 1 assistant row.
     * Row `id` carries the same value a Telegram callback_data would (a
     * plain digit or 'assistant') -- WhatsAppWebhookController reads that
     * id back, not the human-readable title, for the exact same reason
     * Telegram reads callback_data and not a button's visible text.
     */
    public static function toWhatsAppInteractiveList(string $bodyText, array $buttons): array
    {
        return [
            'type' => 'list',
            'body' => ['text' => $bodyText],
            'action' => [
                'button' => 'Выбрать',
                'sections' => [[
                    'rows' => collect($buttons)->map(fn (array $b) => array_filter([
                        'id' => $b['id'],
                        'title' => self::truncate($b['title'], 24),
                        'description' => isset($b['description']) ? self::truncate($b['description'], 72) : null,
                    ], fn ($value) => $value !== null))->values()->all(),
                ]],
            ],
        ];
    }

    /**
     * Messenger Platform quick replies -- the same Send API shape Instagram
     * Direct and Facebook Messenger both ride (see InstagramClient's own
     * docblock for why Instagram DMs go through this infrastructure at
     * all). Up to 13 quick replies, `title` capped at 20 chars (tighter
     * than WhatsApp's own 24) -- description has no field to go in here,
     * so unlike Telegram this never combines title+description. `payload`
     * carries the same id a Telegram callback_data/WhatsApp row id would;
     * InstagramWebhookController/FacebookWebhookController both read that
     * back (message.quick_reply.payload), not the button's visible title,
     * same reasoning as the other two platforms.
     */
    public static function toMessengerQuickReplies(array $buttons): array
    {
        return collect($buttons)
            ->map(fn (array $b) => ['content_type' => 'text', 'title' => self::truncate($b['title'], 20), 'payload' => $b['id']])
            ->values()->all();
    }

    private static function truncate(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;
    }
}
