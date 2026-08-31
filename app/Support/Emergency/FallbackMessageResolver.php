<?php

namespace App\Support\Emergency;

use App\Models\Message;
use App\Models\Tenant;
use App\Support\Language\LanguageDetector;
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

    public function __construct(private readonly LanguageDetector $detector)
    {
    }

    public function resolve(Tenant $tenant, Message $latestCustomerMessage): string
    {
        // This resolver only has ru/tj/en templates — tj_latin (Tajik typed in
        // Latin script) still gets the tj template, not treated as a 4th language.
        $detected = $this->detector->detect($latestCustomerMessage->body);
        $language = $detected === 'tj_latin' ? 'tj' : $detected;

        // TEMPORARY, per explicit user request (2026-09-01) — Tajik output paused
        // platform-wide (see AiWorkflow's system prompt for the main-reply side of
        // this); this outage-fallback path needs the same override or a Tajik
        // customer would still get a Tajik message here even while the AI's normal
        // replies are Russian-only. Delete this line to restore Tajik detection.
        $language = $language === 'tj' ? 'ru' : $language;

        $custom = trim((string) Arr::get($tenant->settings ?? [], 'emergency.fallback_message.'.$language, ''));

        return $custom !== '' ? $custom : self::DEFAULTS[$language];
    }
}
