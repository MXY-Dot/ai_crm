<?php

namespace App\Support\Sentiment;

use App\Support\Language\LanguageDetector;
use App\Support\Language\TajikTransliterator;

/**
 * ЭТАП 12.4 — cheap keyword-based sentiment, not a real emotion classifier.
 * Deliberately a per-message signal only (stored on AiRun.payload, see
 * AiWorkflow::run()) — never written back onto Customer as a persistent trait,
 * matching VipScoreCalculator's own explicit warning against treating "one
 * message" as a durable fact about a customer. Same Latin-Tajik retry pattern
 * as LocalConversationAnalyzer::intent() (ЭТАП 6.5).
 */
class SentimentDetector
{
    private const NEGATIVE_MARKERS = [
        'angry', 'bad', 'complaint', 'cancel', 'terrible', 'awful', 'worst', 'hate', 'disappointed', 'refund',
        'ужасно', 'плохо', 'жалоб', 'недоволен', 'разочарован', 'отмени', 'кошмар', 'обман', 'бесит', 'ужасный',
        'не работает', 'сломан', 'верните деньги', 'возмутительно', 'хамство',
    ];

    private const POSITIVE_MARKERS = [
        'thanks', 'thank you', 'great', 'awesome', 'perfect', 'excellent', 'amazing', 'love it', 'good job',
        'спасибо', 'благодар', 'отлично', 'супер', 'класс', 'прекрасно', 'молодцы', 'рад', 'здорово', 'восхитительно',
    ];

    public function __construct(
        private readonly LanguageDetector $languageDetector,
        private readonly TajikTransliterator $transliterator,
    ) {
    }

    public function detect(string $text): string
    {
        $matched = $this->match($text);

        if ($matched !== 'neutral') {
            return $matched;
        }

        if ($this->languageDetector->isLatinOnly($text)) {
            $retry = $this->match($this->transliterator->toCyrillic($text));

            if ($retry !== 'neutral') {
                return $retry;
            }
        }

        return 'neutral';
    }

    private function match(string $text): string
    {
        $lower = mb_strtolower($text);

        if ($this->containsAny($lower, self::NEGATIVE_MARKERS)) {
            return 'negative';
        }

        if ($this->containsAny($lower, self::POSITIVE_MARKERS)) {
            return 'positive';
        }

        return 'neutral';
    }

    private function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }
}
