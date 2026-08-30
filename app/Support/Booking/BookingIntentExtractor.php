<?php

namespace App\Support\Booking;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Support\Ai\DifyClient;
use App\Support\Ai\LlmClient;
use App\Support\Integrations\PlatformSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A dedicated, single-purpose LLM call (same primary→backup provider pattern
 * as ConversationAnalyzer/AiReportGenerator) that turns the customer's latest
 * message into structured booking intent -- deliberately separate from the
 * main reply-generation call in AiWorkflow rather than folding into it: the
 * main prompt is Dify-or-direct-LLM depending on tenant config and already
 * carefully tuned, while this needs to work identically regardless of which
 * engine answered. Only ever invoked when BookingChatContext::isAvailableFor()
 * is true, so tenants without booking configured never pay for this call.
 */
class BookingIntentExtractor
{
    private const MAX_RESPONSE_TOKENS = 400;

    public function __construct(
        private readonly LlmClient $llm,
        private readonly PlatformSettings $platform,
        private readonly DifyClient $dify,
    ) {
    }

    /**
     * @param Collection<int, \App\Models\Service> $services
     * @param array<int, array{employee_id:int, employee_name:string, starts_at:string, ends_at:string}> $offeredSlots
     * @return array{wants_booking:bool, service_name:?string, selected_offer_index:?int, preferred_date:?string}|null
     */
    public function extract(Tenant $tenant, Conversation $conversation, Message $message, Collection $services, array $offeredSlots): ?array
    {
        $system = $this->systemPrompt($services, $offeredSlots);
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

        return [
            'wants_booking' => filter_var($data['wants_booking'] ?? false, FILTER_VALIDATE_BOOL),
            'service_name' => is_string($data['service_name'] ?? null) && trim($data['service_name']) !== '' ? trim($data['service_name']) : null,
            'selected_offer_index' => is_numeric($data['selected_offer_index'] ?? null) ? (int) $data['selected_offer_index'] : null,
            'preferred_date' => is_string($data['preferred_date'] ?? null) && trim($data['preferred_date']) !== '' ? trim($data['preferred_date']) : null,
        ];
    }

    private function systemPrompt(Collection $services, array $offeredSlots): string
    {
        $serviceNames = $services->pluck('name')->implode(', ');
        $offeredText = $offeredSlots === []
            ? 'Клиенту ничего не предлагалось.'
            : collect($offeredSlots)->map(fn (array $slot, int $i): string => $i.': '.Carbon::parse($slot['starts_at'])->format('d.m в H:i').' ('.$slot['employee_name'].')')->implode("\n");

        return <<<PROMPT
Ты определяешь, хочет ли клиент онлайн-запись на услугу, читая последнее сообщение в переписке. Доступные услуги: {$serviceNames}.

Ранее клиенту могли быть предложены конкретные варианты времени:
{$offeredText}

Верни СТРОГО валидный JSON без пояснений и markdown-обрамления:
{
  "wants_booking": true если клиент хочет записаться/выбирает время/подтверждает один из предложенных вариантов, иначе false,
  "service_name": ТОЧНОЕ название услуги строго из списка выше, если понятно какая нужна, иначе null (не выдумывай услугу, которой нет в списке),
  "selected_offer_index": число -- индекс варианта из списка выше, который клиент только что выбрал (например "второй вариант" = 1, "давайте на 14:00" = индекс варианта с этим временем), или null если клиент не выбирал из предложенных,
  "preferred_date": "YYYY-MM-DD" если клиент назвал конкретный день (переведи "завтра"/"в пятницу" и т.п. в дату сам), иначе null
}

Сегодняшняя дата: {$this->today()}.
PROMPT;
    }

    private function today(): string
    {
        return Carbon::now()->format('Y-m-d (l)');
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
