<?php

namespace App\Support\Language;

/**
 * ЭТАП 6.5 — DRAFT, NOT VERIFIED BY A NATIVE SPEAKER. Best-effort Latin→Tajik-
 * Cyrillic mapping by standard alphabet correspondence, built by a non-native
 * assistant — treat as a starting point to review, not a finished linguistic
 * asset. Known ambiguous cases deliberately picked ONE reading rather than
 * guessing contextually (that would need real fluency this doesn't have):
 *   - "gh" → ғ (could also be a plain g+h sequence in rare loanwords)
 *   - "kh" → х (could also be ҳ — Tajik distinguishes these, Latin often doesn't)
 *   - "j"  → ҷ (Tajik's own affricate, not just soft j)
 *   - "'"/"ʼ" (apostrophe) → ъ (glottal stop marker, sometimes omitted entirely in casual typing)
 * Only used internally for keyword-matching in LocalConversationAnalyzer — never
 * shown to the customer or sent to an LLM, so a wrong mapping here degrades a
 * local heuristic's recall, not anything customer-facing.
 */
class TajikTransliterator
{
    /** Multi-character sequences first (longest match wins), checked before single letters. */
    private const DIGRAPHS = [
        'gh' => 'ғ',
        'kh' => 'х',
        'sh' => 'ш',
        'ch' => 'ч',
        'ts' => 'ц',
        'yo' => 'ё',
        'yu' => 'ю',
        'ya' => 'я',
        "'" => 'ъ',
        'ʼ' => 'ъ',
    ];

    private const SINGLE_LETTERS = [
        'a' => 'а', 'b' => 'б', 'v' => 'в', 'g' => 'г', 'd' => 'д', 'e' => 'е',
        'j' => 'ҷ', 'z' => 'з', 'i' => 'и', 'k' => 'к', 'l' => 'л', 'm' => 'м',
        'n' => 'н', 'o' => 'о', 'p' => 'п', 'r' => 'р', 's' => 'с', 't' => 'т',
        'u' => 'у', 'f' => 'ф', 'h' => 'ҳ', 'c' => 'к', 'q' => 'қ', 'w' => 'в',
        'x' => 'х', 'y' => 'й',
    ];

    public function toCyrillic(string $latin): string
    {
        $text = mb_strtolower($latin);

        foreach (self::DIGRAPHS as $needle => $replacement) {
            $text = str_replace($needle, $replacement, $text);
        }

        $result = '';

        foreach (mb_str_split($text) as $char) {
            $result .= self::SINGLE_LETTERS[$char] ?? $char;
        }

        return $result;
    }
}
