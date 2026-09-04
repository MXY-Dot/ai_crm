<?php

namespace App\Support\Hotel;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\RoomReservation;
use App\Models\Tenant;
use App\Support\Ai\AiDecision;
use App\Support\Chat\ChatButtons;
use App\Support\Payments\AlifPayClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ТЗ раздел 9/12 — "бронь/перенос/отмена номера через AI-чат". Called from
 * AiWorkflow::process() right after TableReservationChatAssistant's own
 * maybeHandle(), chaining safely the same way the other two already do:
 * whichever module a tenant actually has configured is the only one whose
 * isAvailableFor() returns true in practice, and each one no-ops instantly
 * for every tenant it doesn't apply to.
 *
 * Same correctness rule as AiChatBookingAssistant/TableReservationChatAssistant:
 * a specific free room NEVER reaches the customer unless it came out of
 * RoomAvailabilityCalculator via RoomReservationChatContext — never out of
 * the LLM's own free-text generation. Closer to AiChatBookingAssistant than
 * to TableReservationChatAssistant in one respect (it carries real money
 * directly on itself, same as Booking — see RoomReservation's own
 * docblock), so a successful new reservation gets the same real Alif
 * checkout-link payment note. Deliberately deferred this round, matching
 * how Booking's own rollout was paced (booking-via-chat first, payment-
 * SCREENSHOT-via-chat as a separate later round, v1.159.0): no photo/
 * document handling here yet, only the payment note + link.
 *
 * Reschedule is intentionally simpler than Booking's/Table's: a stay has no
 * day-stepping "next available slot" search (RoomAvailabilityCalculator only
 * answers "is this exact range free", see RoomReservationChatContext's own
 * docblock), so a reschedule attempt is tried directly against the
 * customer-requested new dates for the SAME room rather than offering
 * alternatives; on conflict the customer is handed off to a human instead
 * of an automatic re-search, a disclosed scope cut for this round.
 *
 * IMPORTANT — every `flow` value below is deliberately prefixed/unique
 * across ALL THREE chat assistants (Booking/Table/Room), not just the
 * offered_slots meta key: while building this class, found that
 * TableReservationChatAssistant and this class both wanted to use the bare
 * string 'new_reservation'/'disambiguate', and (separately, pre-existing)
 * that AiChatBookingAssistant and TableReservationChatAssistant both used
 * the bare string 'reschedule' — either collision would let a stale offer
 * from ONE assistant be silently treated as "own" by a DIFFERENT assistant's
 * ownFlow gate, since that gate only compares the flow string, not which
 * class wrote it. Fixed here with 'new_room_reservation'/
 * 'room_reschedule_awaiting_dates'/'disambiguate_room', and correspondingly
 * renamed Table's 'reschedule' to 'table_reschedule' and Booking's to
 * 'booking_reschedule' in the same deploy.
 */
class RoomReservationChatAssistant
{
    public function __construct(
        private readonly RoomReservationChatContext $context,
        private readonly RoomReservationIntentExtractor $extractor,
        private readonly RoomReservationService $reservations,
        private readonly AlifPayClient $alif,
    ) {
    }

    public function maybeHandle(Tenant $tenant, Company $company, Conversation $conversation, Message $message, AiDecision $decision): AiDecision
    {
        if (! $conversation->customer_id || ! $this->context->isAvailableFor($company)) {
            return $decision;
        }

        try {
            return $this->handle($tenant, $company, $conversation, $message, $decision);
        } catch (Throwable $error) {
            Log::warning('RoomReservationChatAssistant failed, falling back to the original reply', [
                'tenant_id' => $tenant->id,
                'conversation_id' => $conversation->id,
                'error' => $error->getMessage(),
            ]);

            return $decision;
        }
    }

    private function handle(Tenant $tenant, Company $company, Conversation $conversation, Message $message, AiDecision $decision): AiDecision
    {
        $activeReservations = $this->activeReservationsFor($tenant, $conversation);
        $lastMeta = $this->lastAiMeta($conversation);
        // See this class's own docblock -- only trust offered_slots when the last
        // turn's flow is one of OUR OWN, uniquely-named values.
        $ownFlow = in_array($lastMeta['flow'] ?? null, ['new_room_reservation'], true);
        $offeredRooms = $ownFlow && is_array($lastMeta['offered_slots'] ?? null) ? $lastMeta['offered_slots'] : [];

        $intent = $this->extractor->extract($tenant, $conversation, $message, $offeredRooms, $activeReservations);

        if ($intent !== null) {
            $continued = $this->continueFlow($lastMeta, $intent, $decision);

            if ($continued !== null) {
                return $continued;
            }

            if ($intent['wants_cancel']) {
                return $this->startCancel($activeReservations, $intent, $decision);
            }

            if ($intent['wants_reschedule']) {
                return $this->startReschedule($activeReservations, $intent, $decision);
            }

            if ($intent['wants_reservation']) {
                return $this->startNewReservation($tenant, $company, $conversation, $intent, $decision);
            }
        }

        // Safety net -- same reasoning as AiChatBookingAssistant::handle()'s own
        // comment: never let the underlying reply engine's own free text stand
        // in once real options (or a pending question) are already on the
        // table from the previous turn. Real bug found live testing
        // (2026-09-02) and fixed identically in AiChatBookingAssistant/
        // TableReservationChatAssistant -- see AiChatBookingAssistant::handle()'s
        // own docblock for the full story: this used to fire even when the
        // customer's message was plainly about a different module, clobbering
        // a correct reply an EARLIER assistant in AiWorkflow's chain had
        // already produced.
        if ($this->alreadyClaimedByAnotherModule($decision)) {
            return $decision;
        }

        return $this->reofferForPendingFlow($lastMeta, $decision) ?? $decision;
    }

    /** @see AiChatBookingAssistant::handle()'s own docblock on why this guard exists. */
    private function alreadyClaimedByAnotherModule(AiDecision $decision): bool
    {
        return in_array($decision->nextAction, ['booking_flow', 'table_reservation_flow', 'handoff_operator'], true);
    }

    private function reofferForPendingFlow(array $lastMeta, AiDecision $decision): ?AiDecision
    {
        $flow = $lastMeta['flow'] ?? null;

        if ($flow === 'new_room_reservation') {
            $offeredRooms = is_array($lastMeta['offered_slots'] ?? null) ? $lastMeta['offered_slots'] : [];

            if ($offeredRooms === []) {
                return null;
            }

            return $this->withReply($decision, 'room_reoffer', 'Уточните, пожалуйста, какой из предложенных номеров вам подходит:'."\n".$this->formatOffers($offeredRooms), meta: $lastMeta);
        }

        if ($flow === 'room_reschedule_awaiting_dates') {
            return $this->withReply($decision, 'room_reoffer', 'Уточните, пожалуйста, новые даты заезда и выезда для переноса брони.', meta: $lastMeta);
        }

        if ($flow === 'disambiguate_room') {
            $offeredReservations = is_array($lastMeta['offered_reservations'] ?? null) ? $lastMeta['offered_reservations'] : [];

            if ($offeredReservations === []) {
                return null;
            }

            $lines = collect($offeredReservations)->map(fn (array $r, int $i): string => ($i + 1).') '.$r['resource_name'].' — '.$this->formatDateRange($r['starts_at'], $r['ends_at']).', гостей: '.$r['guests_count']);
            $text = 'Уточните, пожалуйста, какую бронь вы имеете в виду:'."\n".$lines->implode("\n");

            return $this->withReply($decision, 'room_reoffer', $text, meta: $lastMeta);
        }

        return null;
    }

    private function continueFlow(array $lastMeta, array $intent, AiDecision $decision): ?AiDecision
    {
        $flow = $lastMeta['flow'] ?? null;
        $offeredRooms = is_array($lastMeta['offered_slots'] ?? null) ? $lastMeta['offered_slots'] : [];
        $offeredReservations = is_array($lastMeta['offered_reservations'] ?? null) ? $lastMeta['offered_reservations'] : [];

        if ($flow === 'new_room_reservation' && $intent['selected_offer_index'] !== null && isset($offeredRooms[$intent['selected_offer_index']])) {
            return $this->attemptCreate($lastMeta, $offeredRooms[$intent['selected_offer_index']], $decision);
        }

        if ($flow === 'room_reschedule_awaiting_dates' && $intent['checkin_date'] && $intent['checkout_date']) {
            $reservation = RoomReservation::withoutGlobalScopes()->find($lastMeta['reschedule_reservation_id'] ?? null);

            if (! $reservation) {
                return null;
            }

            $pastDate = $this->pastDateNotice($intent['checkin_date'], $decision);
            if ($pastDate !== null) {
                return $pastDate;
            }

            return $this->attemptReschedule($reservation, $intent['checkin_date'], $intent['checkout_date'], $decision);
        }

        if ($flow === 'disambiguate_room' && $intent['selected_reservation_index'] !== null && isset($offeredReservations[$intent['selected_reservation_index']])) {
            $reservation = RoomReservation::withoutGlobalScopes()->find($offeredReservations[$intent['selected_reservation_index']]['id']);

            if (! $reservation) {
                return null;
            }

            return match ($lastMeta['disambiguate_for'] ?? null) {
                'cancel' => $this->attemptCancel($reservation, $intent['cancel_reason'], $decision),
                'reschedule' => $intent['checkin_date'] && $intent['checkout_date']
                    ? $this->attemptReschedule($reservation, $intent['checkin_date'], $intent['checkout_date'], $decision)
                    : $this->askForNewDates($reservation, $decision),
                default => null,
            };
        }

        return null;
    }

    /** @param Collection<int, RoomReservation> $activeReservations */
    private function startCancel(Collection $activeReservations, array $intent, AiDecision $decision): AiDecision
    {
        if ($activeReservations->isEmpty()) {
            return $this->withReply($decision, 'room_cancel_none', 'У вас нет активных броней номера для отмены.');
        }

        if ($activeReservations->count() === 1) {
            return $this->attemptCancel($activeReservations->first(), $intent['cancel_reason'], $decision);
        }

        return $this->offerReservationsForDisambiguation($activeReservations, 'cancel', $decision, 'Уточните, пожалуйста, какую бронь отменить:');
    }

    /** @param Collection<int, RoomReservation> $activeReservations */
    private function startReschedule(Collection $activeReservations, array $intent, AiDecision $decision): AiDecision
    {
        if ($activeReservations->isEmpty()) {
            return $this->withReply($decision, 'room_reschedule_none', 'У вас нет активных броней номера для переноса.');
        }

        if ($activeReservations->count() > 1) {
            return $this->offerReservationsForDisambiguation($activeReservations, 'reschedule', $decision, 'Уточните, пожалуйста, какую бронь перенести:');
        }

        $reservation = $activeReservations->first();

        if (! $intent['checkin_date'] || ! $intent['checkout_date']) {
            return $this->askForNewDates($reservation, $decision);
        }

        $pastDate = $this->pastDateNotice($intent['checkin_date'], $decision);
        if ($pastDate !== null) {
            return $pastDate;
        }

        return $this->attemptReschedule($reservation, $intent['checkin_date'], $intent['checkout_date'], $decision);
    }

    private function askForNewDates(RoomReservation $reservation, AiDecision $decision): AiDecision
    {
        return $this->withReply(
            $decision,
            'room_reschedule_ask_dates',
            'На какие даты заезда и выезда перенести бронь?',
            meta: ['flow' => 'room_reschedule_awaiting_dates', 'reschedule_reservation_id' => $reservation->id],
        );
    }

    private function startNewReservation(Tenant $tenant, Company $company, Conversation $conversation, array $intent, AiDecision $decision): AiDecision
    {
        if (! $intent['guests_count'] || ! $intent['checkin_date'] || ! $intent['checkout_date']) {
            // Let the main reply stand -- RoomReservationChatContext::promptSection()
            // already tells the model to ask for whatever's still missing.
            return $decision;
        }

        $pastDate = $this->pastDateNotice($intent['checkin_date'], $decision);
        if ($pastDate !== null) {
            return $pastDate;
        }

        // Same class of bug as feedback_carbon_timezone_save_bug -- a bare
        // Carbon::parse('2026-09-10')->startOfDay() resolves in the app's own
        // ambient (UTC) timezone, not the company's local calendar day. For a
        // positive-offset tenant this only shifts the instant a few hours later
        // WITHIN the same local date (no visible bug), but a negative-offset
        // tenant would see it roll back to the PREVIOUS local day once
        // RoomReservationService normalizes it with ->utc(). Parse explicitly in
        // the company's own timezone instead, same discipline
        // BookingChatContext::nextAvailableSlots()/AvailabilityCalculator already
        // use.
        $timezone = $company->timezone ?: config('app.timezone');

        try {
            $checkIn = Carbon::parse($intent['checkin_date'], $timezone)->startOfDay();
            $checkOut = Carbon::parse($intent['checkout_date'], $timezone)->startOfDay();
        } catch (Throwable) {
            return $decision;
        }

        if ($checkOut->lte($checkIn)) {
            return $this->withReply($decision, 'room_invalid_dates', 'Дата выезда должна быть позже даты заезда. Уточните, пожалуйста, даты ещё раз.');
        }

        return $this->offerNewReservationRooms($tenant, $company, $conversation->customer_id, $intent['guests_count'], $checkIn, $checkOut, $decision);
    }

    private function offerNewReservationRooms(Tenant $tenant, Company $company, int $customerId, int $guests, Carbon $checkIn, Carbon $checkOut, AiDecision $decision): AiDecision
    {
        $rooms = $this->context->availableRooms($company, $checkIn, $checkOut, $guests);

        if ($rooms === []) {
            return $this->withReply(
                $decision,
                'room_no_rooms',
                'К сожалению, на эти даты нет свободных номеров на нужное количество гостей. Оператор подберёт вариант вручную и свяжется с вами.',
                handoff: true,
            );
        }

        $text = 'Вот свободные номера на выбранные даты:'."\n"
            .$this->formatOffers($rooms)
            ."\nНапишите номер варианта, который вам подходит, и я вас забронирую.";

        $rawButtons = RoomReservationOfferButtons::build($rooms, $company->currency);

        return $this->withReply($decision, 'room_offer', $text, meta: [
            'flow' => 'new_room_reservation',
            'offered_slots' => $rooms,
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'customer_id' => $customerId,
            'guests_count' => $guests,
            'raw_buttons' => $rawButtons,
            'buttons' => ChatButtons::forOffer($rawButtons),
        ]);
    }

    /** @param Collection<int, RoomReservation> $activeReservations */
    private function offerReservationsForDisambiguation(Collection $activeReservations, string $for, AiDecision $decision, string $intro): AiDecision
    {
        $offered = $activeReservations->values()->map(fn (RoomReservation $r): array => [
            'id' => $r->id,
            'resource_name' => $r->resource?->name ?? 'номер',
            'guests_count' => $r->guests_count,
            'starts_at' => $this->localIso($r, $r->starts_at),
            'ends_at' => $this->localIso($r, $r->ends_at),
        ])->all();

        $lines = collect($offered)->map(fn (array $r, int $i): string => ($i + 1).') '.$r['resource_name'].' — '.$this->formatDateRange($r['starts_at'], $r['ends_at']).', гостей: '.$r['guests_count']);

        $text = $intro."\n".$lines->implode("\n");

        return $this->withReply($decision, 'room_disambiguate', $text, meta: ['flow' => 'disambiguate_room', 'disambiguate_for' => $for, 'offered_reservations' => $offered]);
    }

    /** @param array{resource_id:int, resource_name:string, capacity:int|null, price_per_night:float, starts_at:string, ends_at:string, nights:int, total_amount:float} $room */
    private function attemptCreate(array $lastMeta, array $room, AiDecision $decision): AiDecision
    {
        try {
            $reservation = $this->reservations->create([
                'tenant_id' => $lastMeta['tenant_id'],
                'company_id' => $lastMeta['company_id'],
                'customer_id' => $lastMeta['customer_id'],
                'resource_id' => $room['resource_id'],
                'guests_count' => $lastMeta['guests_count'],
                'starts_at' => $room['starts_at'],
                'ends_at' => $room['ends_at'],
                'notes' => 'Создано через AI-чат',
            ], null);
        } catch (RoomReservationConflictException) {
            $company = Company::withoutGlobalScopes()->find($lastMeta['company_id']);

            if (! $company) {
                return $this->withReply($decision, 'room_conflict', 'Извините, этот номер только что забронировали. Оператор свяжется с вами.', handoff: true);
            }

            $apology = $this->offerNewReservationRooms(
                Tenant::query()->findOrFail($lastMeta['tenant_id']),
                $company,
                $lastMeta['customer_id'],
                $lastMeta['guests_count'],
                Carbon::parse($room['starts_at']),
                Carbon::parse($room['ends_at']),
                $decision,
            );

            return $this->prefixApology($apology, 'Ой, этот номер только что забронировали. ');
        }

        $when = $this->formatDateRange($room['starts_at'], $room['ends_at']);
        $paymentNote = $reservation->prepayment_amount > 0 ? $this->paymentNote($reservation) : '';

        $text = "Готово! Забронировал(а) вам «{$room['resource_name']}» — {$when} ({$room['nights']} ноч.), гостей: {$lastMeta['guests_count']}.{$paymentNote} Если нужно перенести или отменить бронь — просто напишите об этом здесь.";

        return $this->withReply($decision, 'room_confirmed', $text);
    }

    /** Same reasoning as AiChatBookingAssistant::paymentNote()'s own docblock — never let a failed gateway attempt break the reservation confirmation the customer is actually waiting for. */
    private function paymentNote(RoomReservation $reservation): string
    {
        $amount = number_format((float) $reservation->prepayment_amount, 0, ',', ' ');

        try {
            $payment = $this->reservations->initiateGatewayPayment($reservation, 'alif', $this->alif, null);
        } catch (Throwable $error) {
            Log::info('RoomReservationChatAssistant: gateway payment link unavailable, falling back to manual contact', [
                'reservation_id' => $reservation->id,
                'error' => $error->getMessage(),
            ]);

            return " Для подтверждения потребуется предоплата {$amount} смн — с вами свяжется администратор.";
        }

        return " Для подтверждения нужна предоплата {$amount} смн. Оплатить: {$payment->checkout_url}";
    }

    private function attemptReschedule(RoomReservation $reservation, string $checkinDate, string $checkoutDate, AiDecision $decision): AiDecision
    {
        // Same company-local-timezone parsing as startNewReservation()'s own
        // comment -- $checkinDate/$checkoutDate are bare "YYYY-MM-DD" strings
        // from the intent extractor, not yet carrying any offset.
        $timezone = $reservation->company?->timezone ?: config('app.timezone');

        try {
            $checkIn = Carbon::parse($checkinDate, $timezone)->startOfDay();
            $checkOut = Carbon::parse($checkoutDate, $timezone)->startOfDay();
        } catch (Throwable) {
            return $this->withReply($decision, 'room_invalid_dates', 'Не получилось разобрать даты. Уточните, пожалуйста, даты заезда и выезда ещё раз.', meta: ['flow' => 'room_reschedule_awaiting_dates', 'reschedule_reservation_id' => $reservation->id]);
        }

        if ($checkOut->lte($checkIn)) {
            return $this->withReply($decision, 'room_invalid_dates', 'Дата выезда должна быть позже даты заезда. Уточните, пожалуйста, даты ещё раз.', meta: ['flow' => 'room_reschedule_awaiting_dates', 'reschedule_reservation_id' => $reservation->id]);
        }

        try {
            $reservation = $this->reservations->reschedule($reservation, $checkIn, $checkOut, null, comment: 'Перенесено клиентом через AI-чат');
        } catch (RoomReservationConflictException $error) {
            // Unlike Table's inventory-only conflict, there is no "search forward for
            // the next free range" utility for rooms to silently retry against (see
            // this class's own docblock) -- a human needs to look at alternative
            // dates/rooms instead of the bot guessing.
            return $this->withReply($decision, 'room_reschedule_conflict', $error->getMessage().' Оператор свяжется с вами, чтобы подобрать другие даты.', handoff: true);
        }

        $when = $this->formatDateRange($this->localIso($reservation, $reservation->starts_at), $this->localIso($reservation, $reservation->ends_at));
        $text = "Готово! Перенёс(ла) бронь «{$reservation->resource?->name}» — {$when}.";

        return $this->withReply($decision, 'room_rescheduled', $text);
    }

    private function attemptCancel(RoomReservation $reservation, ?string $reason, AiDecision $decision): AiDecision
    {
        try {
            $reservation = $this->reservations->cancel($reservation, null, $reason ?? 'Отменено клиентом через AI-чат');
        } catch (RoomReservationConflictException $error) {
            return $this->withReply($decision, 'room_cancel_conflict', $error->getMessage(), handoff: true);
        }

        $when = $this->formatDateRange($this->localIso($reservation, $reservation->starts_at), $this->localIso($reservation, $reservation->ends_at));
        $text = "Хорошо, отменил(а) бронь «{$reservation->resource?->name}» — {$when}. Будем рады видеть вас снова!";

        return $this->withReply($decision, 'room_cancelled', $text);
    }

    private function prefixApology(AiDecision $decision, string $prefix): AiDecision
    {
        return new AiDecision(
            confidence: $decision->confidence,
            intent: $decision->intent,
            summary: $decision->summary,
            nextAction: $decision->nextAction,
            handoffRequired: $decision->handoffRequired,
            replyText: $prefix.$decision->replyText,
            meta: $decision->meta,
        );
    }

    /** Same reasoning as AiChatBookingAssistant::pastDateNotice()'s own docblock -- a genuinely past date always gets an explicit, honest answer instead of being silently reinterpreted. */
    private function pastDateNotice(?string $date, AiDecision $decision): ?AiDecision
    {
        if ($date === null) {
            return null;
        }

        try {
            $parsed = Carbon::parse($date);
        } catch (Throwable) {
            return null;
        }

        if (! $parsed->isPast() || $parsed->isToday()) {
            return null;
        }

        return $this->withReply(
            $decision,
            'room_past_date',
            'Эта дата уже прошла — не могу забронировать на неё. Подскажите, пожалуйста, дату заезда начиная с сегодняшнего дня.',
        );
    }

    /** @param array<int, array{resource_id:int, resource_name:string, capacity:int|null, price_per_night:float, starts_at:string, ends_at:string, nights:int, total_amount:float}> $rooms */
    private function formatOffers(array $rooms): string
    {
        return collect($rooms)
            ->map(fn (array $room, int $i): string => ($i + 1).') '.$room['resource_name'].' — '.$this->formatDateRange($room['starts_at'], $room['ends_at']).' ('.$room['nights'].' ноч.) — '.number_format($room['total_amount'], 0, ',', ' ').' смн')
            ->implode("\n");
    }

    private function formatDateRange(string $checkInIso, string $checkOutIso): string
    {
        return Carbon::parse($checkInIso)->format('d.m').' — '.Carbon::parse($checkOutIso)->format('d.m.Y');
    }

    /** Same UTC→local conversion concern as AiChatBookingAssistant::localIso()'s own docblock. */
    private function localIso(RoomReservation $reservation, Carbon $dateTime): string
    {
        $timezone = $reservation->company?->timezone ?: config('app.timezone');

        return $dateTime->copy()->setTimezone($timezone)->toIso8601String();
    }

    /** @return Collection<int, RoomReservation> */
    private function activeReservationsFor(Tenant $tenant, Conversation $conversation): Collection
    {
        return RoomReservation::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('customer_id', $conversation->customer_id)
            ->whereIn('status', RoomReservation::ACTIVE_STATUSES)
            ->with(['resource:id,name', 'company:id,timezone'])
            ->orderBy('starts_at')
            ->limit(10)
            ->get();
    }

    private function lastAiMeta(Conversation $conversation): array
    {
        $lastAiMessage = Message::withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->where('sender_type', 'ai')
            ->latest('id')
            ->first();

        $meta = $lastAiMessage?->meta;

        return is_array($meta) ? $meta : [];
    }

    private function withReply(AiDecision $decision, string $intent, string $text, bool $handoff = false, ?array $meta = null): AiDecision
    {
        return new AiDecision(
            confidence: $decision->confidence,
            intent: $intent,
            summary: $text,
            nextAction: $handoff ? 'handoff_operator' : 'room_reservation_flow',
            handoffRequired: $handoff,
            replyText: $text,
            meta: $meta,
        );
    }
}
