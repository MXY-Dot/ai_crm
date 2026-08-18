<?php

namespace App\Support\Security;

/**
 * ЭТАП 10.5 — cheap keyword-based heuristic, not a real classifier. Detects
 * common prompt-injection phrasing in customer messages and KB content so
 * it's visible (AiRun.payload.detected_prompt_injection for messages,
 * KnowledgeDocument.meta.contains_injection_markers for indexed pages — same
 * non-blocking, visibility-only pattern as SentimentDetector/LanguageDetector,
 * see their own docblocks) — never blocks or alters the AI's reply, or refuses
 * to index a document. There's nothing dangerous for an injection to trigger
 * today (the AI can only draft/send text, see wero_pending_tasks.md's Stage
 * 10 entry), so this is a signal for staff to notice a pattern, not a hard
 * gate — revisit once the AI can take real actions. RU/EN phrasing only —
 * Tajik injection phrasing isn't included, same terminology-accuracy caution
 * as TajikTransliterator (needs native-speaker review before being trusted).
 */
class PromptInjectionDetector
{
    private const MARKERS = [
        'ignore previous instructions', 'ignore all previous instructions', 'ignore the above',
        'ignore your instructions', 'disregard your instructions', 'disregard previous instructions',
        'forget your instructions', 'forget all previous instructions', 'you are now', 'act as if',
        'system prompt', 'new instructions:', 'override your instructions', 'reveal your instructions',
        'show me your prompt', 'what are your instructions',
        'игнорируй предыдущие инструкции', 'игнорируй все инструкции', 'игнорируй свои инструкции',
        'забудь свои инструкции', 'забудь предыдущие инструкции', 'теперь ты', 'новые инструкции',
        'системный промпт', 'покажи свои инструкции', 'какие у тебя инструкции',
    ];

    public function detect(string $text): bool
    {
        $lower = mb_strtolower($text);

        foreach (self::MARKERS as $marker) {
            if (str_contains($lower, $marker)) {
                return true;
            }
        }

        return false;
    }
}
