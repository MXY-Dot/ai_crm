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

    private static function truncate(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;
    }
}
