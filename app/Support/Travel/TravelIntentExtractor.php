<?php

namespace App\Support\Travel;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\Tour;
use App\Support\Ai\DifyClient;
use App\Support\Ai\LlmClient;
use App\Support\Integrations\PlatformSettings;
use Illuminate\Support\Collection;

/**
 * A dedicated, single-purpose LLM call mirroring
 * EducationIntentExtractor's own shape (same primary→backup provider
 * pattern, same JSON-only contract, same "match a real catalog name,
 * never invent one" discipline for `tour_name`). `pax_count` mirrors
 * TableReservationIntentExtractor's own `party_size` field -- a booking
 * consumes that many seats on a departure, same arithmetic
 * TourBookingService::book() itself already does. Only ever invoked when
 * TravelChatContext::isAvailableFor() is true.
 */
class TravelIntentExtractor
{
    private const MAX_RESPONSE_TOKENS = 300;

    public function __construct(
        private readonly LlmClient $llm,
        private readonly PlatformSettings $platform,
        private readonly DifyClient $dify,
    ) {
    }

    /**
     * @param Collection<int, Tour> $tours
     * @param array<int, array{departure_id:int, tour_name:string, departure_date:string, return_date:string, price:float, seats_remaining:?int}> $offeredDepartures
     * @param Collection<int, \App\Models\TourBooking> $activeBookings
     * @return array{wants_book:bool, wants_cancel:bool, tour_name:?string, pax_count:?int, selected_departure_index:?int, selected_booking_index:?int, cancel_reason:?string}|null
     */
    public function extract(Tenant $tenant, Conversation $conversation, Message $message, Collection $tours, array $offeredDepartures, Collection $activeBookings): ?array
    {
        $system = $this->systemPrompt($tours, $offeredDepartures, $activeBookings);
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
            'wants_book' => filter_var($data['wants_book'] ?? false, FILTER_VALIDATE_BOOL),
            'wants_cancel' => filter_var($data['wants_cancel'] ?? false, FILTER_VALIDATE_BOOL),
            'tour_name' => is_string($data['tour_name'] ?? null) && trim($data['tour_name']) !== '' ? trim($data['tour_name']) : null,
            'pax_count' => is_numeric($data['pax_count'] ?? null) ? max(1, (int) $data['pax_count']) : null,
            'selected_departure_index' => is_numeric($data['selected_departure_index'] ?? null) ? (int) $data['selected_departure_index'] : null,
            'selected_booking_index' => is_numeric($data['selected_booking_index'] ?? null) ? (int) $data['selected_booking_index'] : null,
            'cancel_reason' => is_string($data['cancel_reason'] ?? null) && trim($data['cancel_reason']) !== '' ? trim($data['cancel_reason']) : null,
        ];
    }

    /**
     * @param Collection<int, Tour> $tours
     * @param array<int, array{departure_id:int, tour_name:string, departure_date:string, return_date:string, price:float, seats_remaining:?int}> $offeredDepartures
     * @param Collection<int, \App\Models\TourBooking> $activeBookings
     */
    private function systemPrompt(Collection $tours, array $offeredDepartures, Collection $activeBookings): string
    {
        $tourNames = $tours->pluck('name')->implode(', ');

        $offeredText = $offeredDepartures === []
            ? 'Клиенту ничего не предлагалось.'
            : collect($offeredDepartures)->map(fn (array $d, int $i): string => $i.': '.$d['tour_name'].', '.$d['departure_date'].' — '.$d['return_date'].', '.number_format($d['price'], 0, ',', ' ').' смн')->implode("\n");

        $bookingsText = $activeBookings->isEmpty()
            ? 'У клиента нет активных заявок на туры.'
            : $activeBookings->values()->map(function ($booking, int $i): string {
                $departure = $booking->tourDeparture;

                return $i.': '.($departure?->tour?->name ?? 'тур').', '.($departure?->departure_date?->toDateString() ?? '').', человек: '.$booking->pax_count;
            })->implode("\n");

        return <<<PROMPT
Ты определяешь намерение клиента насчёт заявки на тур в туристической компании, читая последнее сообщение в переписке. Доступные туры: {$tourNames}.

Ранее клиенту могли быть предложены конкретные заезды для бронирования:
{$offeredText}

Активные заявки клиента на туры (используются, если клиент хочет отменить и нужно понять, какую именно, при нескольких заявках):
{$bookingsText}

Верни СТРОГО валидный JSON без пояснений и markdown-обрамления:
{
  "wants_book": true если клиент хочет ЗАБРОНИРОВАТЬ тур (новая заявка) или подтверждает предложенный вариант заезда, иначе false,
  "wants_cancel": true если клиент хочет ОТМЕНИТЬ существующую заявку на тур, иначе false,
  "tour_name": ТОЧНОЕ название тура строго из списка выше, если понятно какой нужен для новой заявки, иначе null (не выдумывай тур, которого нет в списке),
  "pax_count": число людей, если клиент его назвал для новой заявки, иначе null,
  "selected_departure_index": число -- индекс заезда из списка выше, который клиент только что выбрал, или null,
  "selected_booking_index": число -- индекс заявки из списка АКТИВНЫХ заявок выше, если у клиента их несколько и понятно какую он имеет в виду, иначе null,
  "cancel_reason": короткая причина отмены, если клиент её назвал, иначе null
}

Только одно из wants_book/wants_cancel может быть true одновременно.
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
