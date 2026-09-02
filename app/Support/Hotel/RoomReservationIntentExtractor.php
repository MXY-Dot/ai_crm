<?php

namespace App\Support\Hotel;

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
 * App\Support\Restaurant\TableReservationIntentExtractor's exact shape
 * (same primary→backup provider pattern, same JSON-only response
 * contract), adapted for a stay's two-date shape: `checkin_date`/
 * `checkout_date` instead of a single `preferred_date`, `guests_count`
 * instead of `party_size`. No restore/cancellation-reason flows this
 * round, same precedent as TableReservationIntentExtractor. Only ever
 * invoked when RoomReservationChatContext::isAvailableFor() is true, so
 * tenants without this module configured never pay for this call.
 */
class RoomReservationIntentExtractor
{
    private const MAX_RESPONSE_TOKENS = 350;

    public function __construct(
        private readonly LlmClient $llm,
        private readonly PlatformSettings $platform,
        private readonly DifyClient $dify,
    ) {
    }

    /**
     * @param array<int, array{resource_id:int, resource_name:string, capacity:int|null, price_per_night:float, starts_at:string, ends_at:string, nights:int, total_amount:float}> $offeredRooms
     * @param Collection<int, \App\Models\RoomReservation> $activeReservations
     * @return array{wants_reservation:bool, wants_reschedule:bool, wants_cancel:bool, guests_count:?int, checkin_date:?string, checkout_date:?string, selected_offer_index:?int, selected_reservation_index:?int, cancel_reason:?string}|null
     */
    public function extract(Tenant $tenant, Conversation $conversation, Message $message, array $offeredRooms, Collection $activeReservations): ?array
    {
        $system = $this->systemPrompt($offeredRooms, $activeReservations);
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
            'guests_count' => is_numeric($data['guests_count'] ?? null) ? (int) $data['guests_count'] : null,
            'checkin_date' => is_string($data['checkin_date'] ?? null) && trim($data['checkin_date']) !== '' ? trim($data['checkin_date']) : null,
            'checkout_date' => is_string($data['checkout_date'] ?? null) && trim($data['checkout_date']) !== '' ? trim($data['checkout_date']) : null,
            'selected_offer_index' => is_numeric($data['selected_offer_index'] ?? null) ? (int) $data['selected_offer_index'] : null,
            'selected_reservation_index' => is_numeric($data['selected_reservation_index'] ?? null) ? (int) $data['selected_reservation_index'] : null,
            'cancel_reason' => is_string($data['cancel_reason'] ?? null) && trim($data['cancel_reason']) !== '' ? trim($data['cancel_reason']) : null,
        ];
    }

    /** @param array<int, array{resource_id:int, resource_name:string, capacity:int|null, price_per_night:float, starts_at:string, ends_at:string, nights:int, total_amount:float}> $offeredRooms @param Collection<int, \App\Models\RoomReservation> $activeReservations */
    private function systemPrompt(array $offeredRooms, Collection $activeReservations): string
    {
        $offeredText = $offeredRooms === []
            ? 'Клиенту ничего не предлагалось.'
            : collect($offeredRooms)->map(fn (array $room, int $i): string => $i.': '.$room['resource_name'].', '.Carbon::parse($room['starts_at'])->format('d.m').' — '.Carbon::parse($room['ends_at'])->format('d.m').', '.$room['nights'].' ноч., '.number_format($room['total_amount'], 0, ',', ' ').' смн')->implode("\n");

        $reservationsText = $activeReservations->isEmpty()
            ? 'У клиента нет активных броней номера.'
            : $activeReservations->values()->map(function ($reservation, int $i): string {
                $timezone = $reservation->company?->timezone ?: config('app.timezone');
                $checkin = $reservation->starts_at->copy()->setTimezone($timezone)->format('d.m.Y');
                $checkout = $reservation->ends_at->copy()->setTimezone($timezone)->format('d.m.Y');

                return $i.': '.$reservation->resource?->name.', '.$checkin.' — '.$checkout.', гостей: '.$reservation->guests_count;
            })->implode("\n");

        return <<<PROMPT
Ты определяешь намерение клиента насчёт брони номера в отеле/хостеле, читая последнее сообщение в переписке.

Ранее клиенту могли быть предложены конкретные свободные номера (для новой брони):
{$offeredText}

Активные брони номеров клиента (используются, если клиент хочет перенести/отменить и нужно понять, какую именно, при нескольких бронях):
{$reservationsText}

Верни СТРОГО валидный JSON без пояснений и markdown-обрамления:
{
  "wants_reservation": true если клиент хочет ЗАБРОНИРОВАТЬ номер (новая бронь) или подтверждает предложенный вариант номера для новой брони, иначе false,
  "wants_reschedule": true если клиент хочет ПЕРЕНЕСТИ даты существующей брони, иначе false,
  "wants_cancel": true если клиент хочет ОТМЕНИТЬ существующую бронь, иначе false,
  "guests_count": число гостей, если клиент назвал его для новой брони, иначе null,
  "checkin_date": "YYYY-MM-DD" дата заезда, если клиент назвал её (переведи "завтра"/"с пятницы" и т.п. в дату сам), для новой брони или переноса, иначе null,
  "checkout_date": "YYYY-MM-DD" дата выезда, если клиент назвал её, для новой брони или переноса, иначе null,
  "selected_offer_index": число -- индекс номера из списка выше, который клиент только что выбрал, или null,
  "selected_reservation_index": число -- индекс брони из списка АКТИВНЫХ броней выше, если у клиента их несколько и понятно какую он имеет в виду, иначе null,
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
