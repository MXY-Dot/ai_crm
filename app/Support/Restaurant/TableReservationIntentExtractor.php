<?php

namespace App\Support\Restaurant;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Support\Ai\DifyClient;
use App\Support\Ai\LlmClient;
use App\Support\Integrations\PlatformSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A dedicated, single-purpose LLM call mirroring
 * App\Support\Booking\BookingIntentExtractor's exact shape (same primary→
 * backup provider pattern, same JSON-only response contract), simplified
 * for a table reservation's smaller surface: no service to name (a table
 * booking has none), `party_size` instead, and no restore/
 * cancellation-reason flows this round (TableReservation itself has always
 * been deliberately simpler than Booking -- same precedent continued here).
 * Only ever invoked when TableReservationChatContext::isAvailableFor() is
 * true, so tenants without this module configured never pay for this call.
 */
class TableReservationIntentExtractor
{
    private const MAX_RESPONSE_TOKENS = 350;

    public function __construct(
        private readonly LlmClient $llm,
        private readonly PlatformSettings $platform,
        private readonly DifyClient $dify,
    ) {
    }

    /**
     * @param array<int, array{resource_id:int, resource_name:string, capacity:int, starts_at:string, ends_at:string}> $offeredSlots
     * @param Collection<int, \App\Models\TableReservation> $activeReservations
     * @return array{wants_reservation:bool, wants_reschedule:bool, wants_cancel:bool, party_size:?int, selected_offer_index:?int, selected_reservation_index:?int, preferred_date:?string, cancel_reason:?string}|null
     */
    public function extract(Tenant $tenant, Conversation $conversation, Message $message, array $offeredSlots, Collection $activeReservations): ?array
    {
        $system = $this->systemPrompt($offeredSlots, $activeReservations);
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
            'wants_reservation' => filter_var($data['wants_reservation'] ?? false, FILTER_VALIDATE_BOOL),
            'wants_reschedule' => filter_var($data['wants_reschedule'] ?? false, FILTER_VALIDATE_BOOL),
            'wants_cancel' => filter_var($data['wants_cancel'] ?? false, FILTER_VALIDATE_BOOL),
            'party_size' => is_numeric($data['party_size'] ?? null) ? (int) $data['party_size'] : null,
            'selected_offer_index' => is_numeric($data['selected_offer_index'] ?? null) ? (int) $data['selected_offer_index'] : null,
            'selected_reservation_index' => is_numeric($data['selected_reservation_index'] ?? null) ? (int) $data['selected_reservation_index'] : null,
            'preferred_date' => is_string($data['preferred_date'] ?? null) && trim($data['preferred_date']) !== '' ? trim($data['preferred_date']) : null,
            'cancel_reason' => is_string($data['cancel_reason'] ?? null) && trim($data['cancel_reason']) !== '' ? trim($data['cancel_reason']) : null,
        ];
    }

    /** @param array<int, array{resource_id:int, resource_name:string, capacity:int, starts_at:string, ends_at:string}> $offeredSlots @param Collection<int, \App\Models\TableReservation> $activeReservations */
    private function systemPrompt(array $offeredSlots, Collection $activeReservations): string
    {
        $offeredText = $offeredSlots === []
            ? 'Клиенту ничего не предлагалось.'
            : collect($offeredSlots)->map(fn (array $slot, int $i): string => $i.': '.Carbon::parse($slot['starts_at'])->format('d.m в H:i').' ('.$slot['resource_name'].')')->implode("\n");

        $reservationsText = $activeReservations->isEmpty()
            ? 'У клиента нет активных броней столика.'
            : $activeReservations->values()->map(function ($reservation, int $i): string {
                $timezone = $reservation->company?->timezone ?: config('app.timezone');
                $when = $reservation->starts_at->copy()->setTimezone($timezone)->format('d.m в H:i');

                return $i.': '.$reservation->resource?->name.', '.$when.', гостей: '.$reservation->party_size;
            })->implode("\n");

        return <<<PROMPT
Ты определяешь намерение клиента насчёт брони столика в ресторане/кафе, читая последнее сообщение в переписке.

Ранее клиенту могли быть предложены конкретные свободные столики (для новой брони или переноса):
{$offeredText}

Активные брони клиента (используются, если клиент хочет перенести/отменить и нужно понять, какую именно, при нескольких бронях):
{$reservationsText}

Верни СТРОГО валидный JSON без пояснений и markdown-обрамления:
{
  "wants_reservation": true если клиент хочет ЗАБРОНИРОВАТЬ столик (новая бронь) или подтверждает предложенный вариант для новой брони, иначе false,
  "wants_reschedule": true если клиент хочет ПЕРЕНЕСТИ существующую бронь на другое время, иначе false,
  "wants_cancel": true если клиент хочет ОТМЕНИТЬ существующую бронь, иначе false,
  "party_size": число гостей, если клиент назвал его для новой брони, иначе null,
  "selected_offer_index": число -- индекс столика/времени из списка выше, который клиент только что выбрал, или null,
  "selected_reservation_index": число -- индекс брони из списка АКТИВНЫХ броней выше, если у клиента их несколько и понятно какую он имеет в виду, иначе null,
  "preferred_date": "YYYY-MM-DD" если клиент назвал конкретный день для новой брони или переноса (переведи "сегодня"/"завтра"/"в пятницу" в дату сам), иначе null,
  "cancel_reason": короткая причина отмены, если клиент её назвал, иначе null
}

Только одно из wants_reservation/wants_reschedule/wants_cancel может быть true одновременно. Сегодняшняя дата: {$this->today()}.
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
