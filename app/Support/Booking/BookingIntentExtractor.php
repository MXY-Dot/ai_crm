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
     * @param Collection<int, \App\Models\Booking> $activeBookings
     * @param Collection<int, \App\Models\Booking> $recentlyCancelledBookings
     * @return array{wants_booking:bool, wants_reschedule:bool, wants_cancel:bool, wants_restore:bool, wants_cancellation_reason:bool, service_name:?string, selected_offer_index:?int, selected_booking_index:?int, preferred_date:?string, cancel_reason:?string}|null
     */
    public function extract(Tenant $tenant, Conversation $conversation, Message $message, Collection $services, array $offeredSlots, Collection $activeBookings, Collection $recentlyCancelledBookings): ?array
    {
        $system = $this->systemPrompt($services, $offeredSlots, $activeBookings, $recentlyCancelledBookings);
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
            'wants_reschedule' => filter_var($data['wants_reschedule'] ?? false, FILTER_VALIDATE_BOOL),
            'wants_cancel' => filter_var($data['wants_cancel'] ?? false, FILTER_VALIDATE_BOOL),
            'wants_restore' => filter_var($data['wants_restore'] ?? false, FILTER_VALIDATE_BOOL),
            'wants_cancellation_reason' => filter_var($data['wants_cancellation_reason'] ?? false, FILTER_VALIDATE_BOOL),
            'service_name' => is_string($data['service_name'] ?? null) && trim($data['service_name']) !== '' ? trim($data['service_name']) : null,
            'selected_offer_index' => is_numeric($data['selected_offer_index'] ?? null) ? (int) $data['selected_offer_index'] : null,
            'selected_booking_index' => is_numeric($data['selected_booking_index'] ?? null) ? (int) $data['selected_booking_index'] : null,
            'preferred_date' => is_string($data['preferred_date'] ?? null) && trim($data['preferred_date']) !== '' ? trim($data['preferred_date']) : null,
            'cancel_reason' => is_string($data['cancel_reason'] ?? null) && trim($data['cancel_reason']) !== '' ? trim($data['cancel_reason']) : null,
        ];
    }

    /**
     * @param Collection<int, \App\Models\Booking> $activeBookings
     * @param Collection<int, \App\Models\Booking> $recentlyCancelledBookings
     */
    private function systemPrompt(Collection $services, array $offeredSlots, Collection $activeBookings, Collection $recentlyCancelledBookings): string
    {
        $serviceNames = $services->pluck('name')->implode(', ');
        $offeredText = $offeredSlots === []
            ? 'Клиенту ничего не предлагалось.'
            : collect($offeredSlots)->map(fn (array $slot, int $i): string => $i.': '.Carbon::parse($slot['starts_at'])->format('d.m в H:i').' ('.$slot['employee_name'].')')->implode("\n");

        $formatBookingsList = function (Collection $bookings): string {
            return $bookings->values()->map(function ($booking, int $i): string {
                // Booking.starts_at casts to UTC -- must convert to the company's own
                // timezone before formatting, same as AiChatBookingAssistant::localIso().
                $timezone = $booking->company?->timezone ?: config('app.timezone');
                $when = $booking->starts_at->copy()->setTimezone($timezone)->format('d.m в H:i');

                return $i.': '.$booking->service?->name.', '.$when.' ('.$booking->employee?->name.')';
            })->implode("\n");
        };

        $bookingsText = $activeBookings->isEmpty()
            ? 'У клиента нет активных записей.'
            : $formatBookingsList($activeBookings);

        $cancelledText = $recentlyCancelledBookings->isEmpty()
            ? 'У клиента нет недавно отменённых записей.'
            : $formatBookingsList($recentlyCancelledBookings);

        return <<<PROMPT
Ты определяешь намерение клиента насчёт онлайн-записи, читая последнее сообщение в переписке. Доступные услуги: {$serviceNames}.

Ранее клиенту могли быть предложены конкретные варианты времени (для новой записи или переноса):
{$offeredText}

Активные записи клиента (используются, если клиент хочет перенести/отменить и нужно понять, какую именно, при нескольких записях):
{$bookingsText}

Недавно отменённые записи клиента (используются, если клиент передумал и просит вернуть/восстановить отменённую запись, например "восстанови", "верни запись", "я передумал, отменять не надо"):
{$cancelledText}

Верни СТРОГО валидный JSON без пояснений и markdown-обрамления:
{
  "wants_booking": true если клиент хочет ЗАПИСАТЬСЯ (новая запись) или подтверждает предложенный вариант времени для новой записи, иначе false,
  "wants_reschedule": true если клиент хочет ПЕРЕНЕСТИ существующую запись на другое время, иначе false,
  "wants_cancel": true если клиент хочет ОТМЕНИТЬ существующую запись, иначе false,
  "wants_restore": true если клиент передумал ПОСЛЕ отмены и просит вернуть/восстановить одну из недавно отменённых записей выше, иначе false,
  "wants_cancellation_reason": true если клиент спрашивает, ПОЧЕМУ/за что была отменена его запись (интересуется причиной, не просит восстановить), иначе false,
  "service_name": ТОЧНОЕ название услуги строго из списка выше, если понятно какая нужна для новой записи, иначе null (не выдумывай услугу, которой нет в списке),
  "selected_offer_index": число -- индекс варианта времени из списка выше, который клиент только что выбрал (например "второй вариант" = 1, "давайте на 14:00" = индекс варианта с этим временем), или null,
  "selected_booking_index": число -- если wants_reschedule/wants_cancel: индекс записи из списка АКТИВНЫХ записей выше; если wants_restore/wants_cancellation_reason: индекс записи из списка НЕДАВНО ОТМЕНЁННЫХ записей выше; используй, только если у клиента их несколько и понятно, какую именно он имеет в виду, иначе null,
  "preferred_date": "YYYY-MM-DD" если клиент назвал конкретный день для новой записи или переноса (переведи "завтра"/"в пятницу" и т.п. в дату сам), иначе null,
  "cancel_reason": короткая причина отмены, если клиент её назвал, иначе null
}

Только одно из wants_booking/wants_reschedule/wants_cancel/wants_restore/wants_cancellation_reason может быть true одновременно. Сегодняшняя дата: {$this->today()}.
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
