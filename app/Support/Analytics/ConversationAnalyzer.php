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
        $result = $this->llm->complete($tenant, $provider, $model, $systemPrompt, $userPrompt);

        if ($result === null) {
            $backupProvider = $this->platform->backupLlmProvider();
            if ($backupProvider) {
                $backupModel = $this->platform->defaultModelFor($backupProvider);
                $result = $this->llm->complete($tenant, $backupProvider, $backupModel, $systemPrompt, $userPrompt);
                $model = $backupModel;
            }
        }

        if ($result === null) {
            Log::info('ConversationAnalyzer: no LLM result, skipping', ['conversation_id' => $conversation->id]);

            return null;
        }

        $data = $this->parseJson($result['text']);

        if ($data === null) {
            Log::warning('ConversationAnalyzer: could not parse LLM JSON', ['conversation_id' => $conversation->id, 'raw' => Str::limit($result['text'], 300)]);

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

        if (preg_match('/\{.*\}/s', $text, $matches)) {
            $decoded = json_decode($matches[0], true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
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
