<?php

namespace App\Support\Language;

/**
 * Not the "приложенный normalizer" the user referenced -- no such file was
 * ever actually attached to the conversation (checked, confirmed absent).
 * Built directly from the folding rules the user specified in that same
 * message: fold away the 6 Tajik-specific Cyrillic letters (ғ ӣ қ ӯ ҳ ҷ,
 * absent from standard Russian Cyrillic) to their nearest plain-Cyrillic
 * equivalent, then normalize further for text matching. The original text
 * is NEVER altered before being sent to the model -- only folded_text/
 * normalized_text exist for internal search/example-matching, per the
 * user's own explicit requirement #3.
 */
class TajikTextNormalizer
{
    /** Longest-Tajik-letter-first is irrelevant here (single-char), but kept as a map for clarity. */
    private const FOLD_MAP = [
        'ғ' => 'г', 'Ғ' => 'Г',
        'ӣ' => 'и', 'Ӣ' => 'И',
        'қ' => 'к', 'Қ' => 'К',
        'ӯ' => 'у', 'Ӯ' => 'У',
        'ҳ' => 'х', 'Ҳ' => 'Х',
        'ҷ' => 'ч', 'Ҷ' => 'Ч',
    ];

    /**
     * @return array{original_text: string, folded_text: string, normalized_text: string}
     */
    public function normalize(string $text): array
    {
        $original = $text;
        $folded = strtr($original, self::FOLD_MAP);
        $normalized = $this->forSearch($folded);

        return [
            'original_text' => $original,
            'folded_text' => $folded,
            'normalized_text' => $normalized,
        ];
    }

    /** Lowercased, punctuation-stripped, whitespace-collapsed -- for word-overlap matching, not for display. */
    private function forSearch(string $foldedText): string
    {
        $lower = mb_strtolower($foldedText);
        $noPunct = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $lower) ?? $lower;

        return trim(preg_replace('/\s+/u', ' ', $noPunct) ?? $noPunct);
    }
}
