<?php

namespace App\Support\Emergency;

use App\Models\Message;
use App\Models\Tenant;
use Illuminate\Support\Arr;

/**
 * Picks the customer-facing outage message (ЭТАП 16.7/16.8/16.9) — per-tenant
 * editable text from tenants.settings.emergency.fallback_message.{ru,tj,en},
 * falling back to the spec's own default templates. Language is guessed from the
 * customer's own message rather than any stored preference, since a customer's
 * very first message (the one most likely to land during an outage) has no
 * language history yet.
 */
class FallbackMessageResolver
{
    private const DEFAULTS = [
        'ru' => 'Ваше сообщение получили. Оператор скоро вам ответит.',
        'tj' => 'Паёми шуморо гирифтем. Система муваққатан дастнорас аст. Оператор ба зудӣ ба шумо ҷавоб медиҳад.',
        'en' => 'Thanks for your message. Our assistant is temporarily unavailable — an operator will reply shortly.',
    ];

    public function resolve(Tenant $tenant, Message $latestCustomerMessage): string
    {
        $language = $this->detectLanguage($latestCustomerMessage->body);
        $custom = trim((string) Arr::get($tenant->settings ?? [], 'emergency.fallback_message.'.$language, ''));

        return $custom !== '' ? $custom : self::DEFAULTS[$language];
    }

    /**
     * Cheap heuristic, not a real language detector: Cyrillic script covers both
     * Russian and (non-Latin) Tajik, so Tajik-specific letters (ғ ӣ қ ӯ ҳ ҷ, absent
     * from Russian) are checked first to tell them apart; anything without
     * Cyrillic at all falls to English/Latin transliteration.
     */
    private function detectLanguage(string $text): string
    {
        if (preg_match('/[ғӣқӯҳҷ]/ui', $text)) {
            return 'tj';
        }

        if (preg_match('/[а-яё]/ui', $text)) {
            return 'ru';
        }

        return 'en';
    }
}
