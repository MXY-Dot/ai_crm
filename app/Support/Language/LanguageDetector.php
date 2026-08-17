<?php

namespace App\Support\Language;

/**
 * ЭТАП 6.1 — cheap heuristic, not a real language classifier. Tajik-specific
 * Cyrillic letters (ғ ӣ қ ӯ ҳ ҷ, absent from Russian) are checked first so
 * Tajik and Russian Cyrillic text can be told apart; anything with no Cyrillic
 * at all is either English or Tajik written in Latin transliteration — those
 * two are only distinguished by a small hand-picked list of common colloquial
 * Tajik-Latin words, so `tj_latin` here is a best guess, not a confident
 * classification. Good enough for analytics tagging and for
 * LocalConversationAnalyzer's transliteration fallback (see
 * TajikTransliterator) — not something to build hard business logic on.
 */
class LanguageDetector
{
    private const TAJIK_LATIN_MARKERS = [
        'salom', 'rahmat', 'tashakur', 'chi hol', 'khub', 'bale', 'kati',
        'meshavad', 'mekunam', 'mumkin', 'lutfan', 'raftam', 'oedam', 'zakaz',
    ];

    public function detect(string $text): string
    {
        if (preg_match('/[ғӣқӯҳҷ]/ui', $text)) {
            return 'tj';
        }

        if (preg_match('/[а-яё]/ui', $text)) {
            return 'ru';
        }

        $lower = mb_strtolower($text);

        foreach (self::TAJIK_LATIN_MARKERS as $marker) {
            if (str_contains($lower, $marker)) {
                return 'tj_latin';
            }
        }

        return 'en';
    }

    /** True when the text has no Cyrillic at all — used to decide whether it's worth attempting a Latin→Cyrillic transliteration pass. */
    public function isLatinOnly(string $text): bool
    {
        return preg_match('/[а-яёғӣқӯҳҷ]/ui', $text) !== 1 && preg_match('/[a-z]/i', $text) === 1;
    }
}
