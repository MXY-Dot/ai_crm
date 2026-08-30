<?php

namespace App\Support\Analytics;

use App\Models\Conversation;
use App\Models\ConversationAnalysis;
use App\Support\Ai\LlmClient;
use App\Support\Integrations\PlatformSettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ТЗ «Отчётность...» разделы 3-6/14 — один AI-вызов на диалог, реального
 * стороннего провайдера (тот же LlmClient/PlatformSettings, что и
 * AiWorkflow::directLlmReply — primary с fallback на backup), а не эвристика.
 * AI не просто считает цифры, а объясняет результат: статус, тональность,
 * оценка качества, причина недовольства (если есть), рекомендация.
 */
class ConversationAnalyzer
{
    private const MAX_MESSAGES = 60;

    private const MAX_BODY_LENGTH = 500;

    // The response packs 9 fields, several free-text (summary, unhappy_reason,
    // customer_wanted, ai_action, operator_action, recommendation) — LlmClient's
    // default 600 (tuned for a short chat reply) is tight for that, especially
    // in Russian/Tajik where Cyrillic tokenizes less densely than English. Seen
    // live: "ConversationAnalyzer: could not parse LLM JSON" on tenant 5's
    // conversation #58, most likely the response getting cut off mid-string
    // (both json_decode attempts in parseJson() below failed, which a
    // truncated/unterminated string would explain) rather than a quoting issue.
    private const MAX_RESPONSE_TOKENS = 1500;

    public function __construct(
        private readonly LlmClient $llm,
        private readonly PlatformSettings $platform,
    ) {
    }

    public function analyze(Conversation $conversation): ?ConversationAnalysis
    {
        $tenant = $conversation->tenant;

        if (! $tenant) {
            return null;
        }

        $messages = $conversation->messages()
            ->whereNull('deleted_at')
            ->orderBy('sent_at')
            ->limit(self::MAX_MESSAGES)
            ->get(['sender_type', 'sender_name', 'body', 'sent_at']);

        if ($messages->isEmpty()) {
            return null;
        }

        $transcript = $messages->map(fn ($m) => sprintf(
            '[%s] %s: %s',
            $m->sent_at?->format('H:i') ?? '--:--',
            $this->roleLabel($m->sender_type),
            Str::limit((string) $m->body, self::MAX_BODY_LENGTH),
        ))->implode("\n");

        $systemPrompt = $this->systemPrompt();
        $userPrompt = "Диалог (хронологически, клиент/AI/оператор):\n\n{$transcript}";

        $provider = $this->platform->primaryLlmProvider();
        $model = $this->platform->defaultModel();
        $result = $this->llm->complete($tenant, $provider, $model, $systemPrompt, $userPrompt, self::MAX_RESPONSE_TOKENS);

        if ($result === null) {
            $backupProvider = $this->platform->backupLlmProvider();
            if ($backupProvider) {
                $backupModel = $this->platform->defaultModelFor($backupProvider);
                $result = $this->llm->complete($tenant, $backupProvider, $backupModel, $systemPrompt, $userPrompt, self::MAX_RESPONSE_TOKENS);
                $provider = $backupProvider;
                $model = $backupModel;
            }
        }

        if ($result === null) {
            Log::info('ConversationAnalyzer: no LLM result, skipping', ['conversation_id' => $conversation->id]);

            return null;
        }

        $data = $this->parseJson($result['text']);

        if ($data === null) {
            // Kept short before (300 chars) purely for log-noise reasons, but that
            // meant every past failure's log entry was itself too truncated to ever
            // diagnose why parsing failed — json_last_error_msg() plus a much longer
            // preview turns the next occurrence into an actual, fixable finding.
            Log::warning('ConversationAnalyzer: could not parse LLM JSON', [
                'conversation_id' => $conversation->id,
                'provider' => $provider,
                'model' => $model,
                'json_error' => json_last_error_msg(),
                'text_length' => mb_strlen($result['text']),
                'raw' => Str::limit($result['text'], 4000),
            ]);

            return null;
        }

        return ConversationAnalysis::query()->updateOrCreate(
            ['conversation_id' => $conversation->id],
            [
                'tenant_id' => $tenant->id,
                'company_id' => $conversation->company_id,
                'outcome' => $this->pickEnum($data['outcome'] ?? null, ConversationAnalysis::OUTCOMES, 'other'),
                'sentiment' => $this->pickEnum($data['sentiment'] ?? null, ConversationAnalysis::SENTIMENTS, 'neutral'),
                'sentiment_start' => $this->pickEnum($data['sentiment_start'] ?? null, ConversationAnalysis::SENTIMENTS, 'neutral'),
                'quality_score' => $this->clampScore($data['quality_score'] ?? null, 50),
                'is_resolved' => (bool) ($data['is_resolved'] ?? false),
                'unhappy_reason' => $this->shortText($data['unhappy_reason'] ?? null),
                'summary' => $this->shortText($data['summary'] ?? null, 1000),
                'customer_wanted' => $this->shortText($data['customer_wanted'] ?? null),
                'ai_action' => $this->shortText($data['ai_action'] ?? null),
                'operator_action' => $this->shortText($data['operator_action'] ?? null),
                'return_probability' => $this->clampScore($data['return_probability'] ?? null, null),
                'sale_probability' => $this->clampScore($data['sale_probability'] ?? null, null),
                'recommendation' => $this->shortText($data['recommendation'] ?? null),
                'model_used' => $model,
                'analyzed_at' => now(),
            ],
        );
    }

    private function roleLabel(string $senderType): string
    {
        return match ($senderType) {
            'customer' => 'Клиент',
            'ai' => 'AI',
            'operator' => 'Оператор',
            default => $senderType,
        };
    }

    private function systemPrompt(): string
    {
        $outcomes = implode(', ', ConversationAnalysis::OUTCOMES);
        $sentiments = implode(', ', ConversationAnalysis::SENTIMENTS);

        return <<<PROMPT
Ты — аналитик качества обслуживания в CRM. Тебе дают транскрипт диалога компании с клиентом (сообщения клиента, AI-ассистента и, возможно, оператора). Проанализируй его и верни СТРОГО валидный JSON (без markdown-обрамления, без пояснений до/после) со следующими полями:

{
  "outcome": одно из [{$outcomes}],
  "sentiment": настроение клиента В КОНЦЕ диалога, одно из [{$sentiments}],
  "sentiment_start": настроение клиента В НАЧАЛЕ диалога, одно из [{$sentiments}],
  "quality_score": целое число 0-100 — насколько хорошо AI/оператор обработали диалог,
  "is_resolved": true/false — решён ли вопрос клиента,
  "unhappy_reason": короткая причина недовольства клиента, если оно есть, иначе null,
  "summary": краткое резюме диалога на русском (2-3 предложения),
  "customer_wanted": что хотел клиент (коротко),
  "ai_action": что сделал/ответил AI (коротко),
  "operator_action": что сделал оператор, если он участвовал, иначе null,
  "return_probability": целое число 0-100 — вероятность что клиент вернётся,
  "sale_probability": целое число 0-100 — вероятность продажи/заявки,
  "recommendation": короткая рекомендация владельцу бизнеса, что улучшить (например в базе знаний или в сценарии AI)
}

Отвечай только JSON-объектом, никакого другого текста.
PROMPT;
    }

    private function parseJson(string $text): ?array
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text) ?? $text;

        $decoded = json_decode($text, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        if (! preg_match('/\{.*\}/s', $text, $matches)) {
            return null;
        }

        $candidate = $matches[0];
        $decoded = json_decode($candidate, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        // Models frequently write a literal newline/tab inside a string value
        // (e.g. a multi-sentence "summary") instead of escaping it as \n — fine
        // as plain text, but RFC 8259 requires control characters inside a JSON
        // string to be escaped, so json_decode correctly rejects it as-is.
        // Escaping only the control characters actually inside a string (not the
        // pretty-printing whitespace between tokens) recovers this without
        // touching anything that was already valid.
        $decoded = json_decode($this->escapeControlCharsInStrings($candidate), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function escapeControlCharsInStrings(string $json): string
    {
        $result = '';
        $inString = false;
        $escaped = false;

        foreach (str_split($json) as $char) {
            if ($inString && $escaped) {
                $result .= $char;
                $escaped = false;
                continue;
            }

            if ($inString && $char === '\\') {
                $result .= $char;
                $escaped = true;
                continue;
            }

            if ($char === '"') {
                $inString = ! $inString;
                $result .= $char;
                continue;
            }

            if ($inString && ($char === "\n" || $char === "\r" || $char === "\t")) {
                $result .= match ($char) {
                    "\n" => '\\n',
                    "\r" => '\\r',
                    default => '\\t',
                };
                continue;
            }

            $result .= $char;
        }

        return $result;
    }

    private function pickEnum(mixed $value, array $allowed, string $default): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }

    private function clampScore(mixed $value, ?int $default): ?int
    {
        if (! is_numeric($value)) {
            return $default;
        }

        return max(0, min(100, (int) round((float) $value)));
    }

    private function shortText(mixed $value, int $limit = 500): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Str::limit(trim($value), $limit);
    }
}
