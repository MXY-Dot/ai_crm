<?php

namespace App\Support\Logistics;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Support\Ai\DifyClient;
use App\Support\Ai\LlmClient;
use App\Support\Integrations\PlatformSettings;

/**
 * A dedicated, single-purpose LLM call mirroring every other module's own
 * IntentExtractor shape (same primary→backup provider pattern, same
 * JSON-only extraction contract) but genuinely the simplest of the six:
 * no catalog list, no offered-options list, no active-records list to
 * inject into the prompt at all -- see LogisticsChatContext's own
 * docblock for why. Just two intents and one field to extract: does the
 * customer want to track or cancel, and what tracking number did they
 * give (if any).
 */
class LogisticsIntentExtractor
{
    private const MAX_RESPONSE_TOKENS = 200;

    public function __construct(
        private readonly LlmClient $llm,
        private readonly PlatformSettings $platform,
        private readonly DifyClient $dify,
    ) {
    }

    /** @return array{wants_track:bool, wants_cancel:bool, tracking_number:?string}|null */
    public function extract(Tenant $tenant, Conversation $conversation, Message $message): ?array
    {
        $system = $this->systemPrompt();
        $user = "Последние сообщения переписки:\n".$this->dify->recentMessages($conversation)
            ."\n\nПоследнее сообщение клиента:\n".$message->body;

        $provider = $this->platform->primaryLlmProvider();
        $model = $this->platform->defaultModel();
        $result = $this->llm->complete($tenant, $provider, $model, $system, $user, self::MAX_RESPONSE_TOKENS);

        if ($result === null) {
            $backupProvider = $this->platform->backupLlmProvider();
            if ($backupProvider) {
                $backupModel = $this->platform->defaultModelFor($backupProvider);
                $result = $this->llm->complete($tenant, $backupProvider, $backupModel, $system, $user, self::MAX_RESPONSE_TOKENS);
            }
        }

        if ($result === null) {
            return null;
        }

        $data = $this->parseJson($result['text']);

        if ($data === null) {
            return null;
        }

        $trackingNumber = is_string($data['tracking_number'] ?? null) ? trim($data['tracking_number']) : '';
        // WERO-XXXXXXXX, same generation format as ShipmentService's own
        // generateTrackingNumber() -- normalize case/whitespace so "wero-a1b2c3d4"
        // or a stray trailing space still matches the real stored value.
        $trackingNumber = $trackingNumber !== '' ? strtoupper(preg_replace('/\s+/', '', $trackingNumber)) : null;

        return [
            'wants_track' => filter_var($data['wants_track'] ?? false, FILTER_VALIDATE_BOOL),
            'wants_cancel' => filter_var($data['wants_cancel'] ?? false, FILTER_VALIDATE_BOOL),
            'tracking_number' => $trackingNumber,
        ];
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Ты определяешь намерение клиента насчёт отслеживания отправления в логистической компании, читая последнее сообщение в переписке.

Верни СТРОГО валидный JSON без пояснений и markdown-обрамления:
{
  "wants_track": true если клиент спрашивает про статус/местонахождение/срок доставки отправления, иначе false,
  "wants_cancel": true если клиент хочет ОТМЕНИТЬ отправление, иначе false,
  "tracking_number": трек-номер отправления, если клиент его назвал (формат обычно WERO-XXXXXXXX, но извлеки то, что похоже на трек-номер, даже если формат не совпадает точно), иначе null
}

Только одно из wants_track/wants_cancel может быть true одновременно.
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

        $decoded = json_decode($matches[0], true);

        return is_array($decoded) ? $decoded : null;
    }
}
