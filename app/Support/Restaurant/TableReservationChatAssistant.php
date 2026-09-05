<?php

namespace App\Support\Restaurant;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\TableReservation;
use App\Support\Ai\AiDecision;
use App\Support\Chat\ChatButtons;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ТЗ раздел 9/12 — "бронь/перенос/отмена столика через AI-чат". Called from
 * AiWorkflow::process() right after AiChatBookingAssistant's own
 * maybeHandle(), so the two chain safely: whichever module a tenant
 * actually has configured is the only one whose isAvailableFor() returns
 * true in practice (a business is a salon OR a restaurant, not both), and
 * each one no-ops immediately for every tenant it doesn't apply to.
 *
 * Mirrors AiChatBookingAssistant's own correctness rule exactly: a specific
 * free table/time NEVER reaches the customer unless it came out of
 * TableAvailabilityCalculator via TableReservationChatContext -- never out
 * of the LLM's own free-text generation. Deliberately simpler than its
 * Booking counterpart -- no payment note (TableReservation carries no
 * prepayment of its own, see TableReservation's own docblock), no restore/
 * explain-cancellation flows, no payment-screenshot handling.
 */
class TableReservationChatAssistant
{
    public function __construct(
        private readonly TableReservationChatContext $context,
        private readonly TableReservationIntentExtractor $extractor,
        private readonly TableReservationService $reservations,
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
            Log::warning('TableReservationChatAssistant failed, falling back to the original reply', [
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
        // Mirrors the identical, live-found fix in AiChatBookingAssistant::handle()'s
        // own docblock comment: on a tenant with BOTH this module and Booking
        // enabled, `flow`/`offered_slots` are shared Message.meta keys between the
        // two assistants but with incompatible slot shapes (resource_name here vs.
        // employee_name there) -- only trust offered_slots when the last turn's
        // flow actually belongs to US. Also found while building the hotel module's
        // own chat assistant (v1.168.0): the bare 'reschedule' flow value itself was
        // shared verbatim between THIS class and AiChatBookingAssistant (both used
        // the exact same string), so even this ownFlow check could be fooled by a
        // stale Booking reschedule offer -- renamed to 'table_reschedule' below to
        // close that gap for good, same reasoning applied to Booking's own
        // 'booking_reschedule' rename.
        $ownFlow = in_array($lastMeta['flow'] ?? null, ['new_reservation', 'table_reschedule'], true);
        $offeredSlots = $ownFlow && is_array($lastMeta['offered_slots'] ?? null) ? $lastMeta['offered_slots'] : [];

        $intent = $this->extractor->extract($tenant, $conversation, $message, $offeredSlots, $activeReservations);

        if ($intent !== null) {
            $continued = $this->continueFlow($lastMeta, $intent, $decision);

            if ($continued !== null) {
                return $continued;
            }

            if ($intent['wants_cancel']) {
                return $this->startCancel($activeReservations, $intent, $decision);
            }

            if ($intent['wants_reschedule']) {
                return $this->startReschedule($company, $activeReservations, $intent, $decision);
            }

            if ($intent['wants_reservation']) {
                return $this->startNewReservation($tenant, $company, $conversation, $intent, $decision);
            }
        }

        // Safety net -- same reasoning as AiChatBookingAssistant::handle()'s own
        // comment: never let the underlying reply engine's own free text stand in
        // once real table/time options are already on the table from the previous
        // turn. Real bug found live testing (2026-09-02) and fixed identically in
        // AiChatBookingAssistant -- see that class's own handle()'s docblock for
        // the full story: this used to fire even when the customer's message was
        // plainly about a different module, clobbering a correct reply an
        // EARLIER assistant in AiWorkflow's chain had already produced.
        if ($this->alreadyClaimedByAnotherModule($decision)) {
            return $decision;
        }

        return $this->reofferForPendingFlow($lastMeta, $decision) ?? $decision;
    }

    /** @see AiChatBookingAssistant::handle()'s own docblock on why this guard exists. */
    private function alreadyClaimedByAnotherModule(AiDecision $decision): bool
    {
        return in_array($decision->nextAction, ['booking_flow', 'room_reservation_flow', 'handoff_operator'], true);
    }

    private function reofferForPendingFlow(array $lastMeta, AiDecision $decision): ?AiDecision
    {
        $flow = $lastMeta['flow'] ?? null;

        if (in_array($flow, ['new_reservation', 'table_reschedule'], true)) {
            $offeredSlots = is_array($lastMeta['offered_slots'] ?? null) ? $lastMeta['offered_slots'] : [];

            if ($offeredSlots === []) {
                return null;
            }

            $intro = $flow === 'table_reschedule'
                ? 'Уточните, пожалуйста, какой из предложенных вариантов переноса вам подходит:'
                : 'Уточните, пожалуйста, какой из предложенных вариантов вам подходит:';

            return $this->withReply($decision, 'table_reoffer', $intro."\n".$this->formatOffers($offeredSlots), meta: $lastMeta);
        }

        if ($flow === 'disambiguate') {
            $offeredReservations = is_array($lastMeta['offered_reservations'] ?? null) ? $lastMeta['offered_reservations'] : [];

            if ($offeredReservations === []) {
                return null;
            }

            $lines = collect($offeredReservations)->map(fn (array $r, int $i): string => ($i + 1).') '.$r['resource_name'].' — '.$this->formatWhen($r['starts_at']).', гостей: '.$r['party_size']);
            $text = 'Уточните, пожалуйста, какую бронь вы имеете в виду:'."\n".$lines->implode("\n");

            return $this->withReply($decision, 'table_reoffer', $text, meta: $lastMeta);
        }

        return null;
    }

    private function continueFlow(array $lastMeta, array $intent, AiDecision $decision): ?AiDecision
    {
        $flow = $lastMeta['flow'] ?? null;
        $offeredSlots = is_array($lastMeta['offered_slots'] ?? null) ? $lastMeta['offered_slots'] : [];
        $offeredReservations = is_array($lastMeta['offered_reservations'] ?? null) ? $lastMeta['offered_reservations'] : [];

        if ($flow === 'new_reservation' && $intent['selected_offer_index'] !== null && isset($offeredSlots[$intent['selected_offer_index']])) {
            return $this->attemptCreate($lastMeta, $offeredSlots[$intent['selected_offer_index']], $decision);
        }

        if ($flow === 'table_reschedule' && $intent['selected_offer_index'] !== null && isset($offeredSlots[$intent['selected_offer_index']])) {
            $reservation = TableReservation::withoutGlobalScopes()->find($lastMeta['reschedule_reservation_id'] ?? null);

            return $reservation ? $this->attemptReschedule($reservation, $offeredSlots[$intent['selected_offer_index']], $decision) : null;
        }

        if ($flow === 'disambiguate' && $intent['selected_reservation_index'] !== null && isset($offeredReservations[$intent['selected_reservation_index']])) {
            $reservation = TableReservation::withoutGlobalScopes()->find($offeredReservations[$intent['selected_reservation_index']]['id']);

            if (! $reservation) {
                return null;
            }

            return match ($lastMeta['disambiguate_for'] ?? null) {
                'cancel' => $this->attemptCancel($reservation, $intent['cancel_reason'], $decision),
                'reschedule' => $this->offerRescheduleSlots($reservation, $intent['preferred_date'] ? $this->parsePreferredDate($intent['preferred_date']) : Carbon::now(), $decision),
                default => null,
            };
        }

        return null;
    }

    /** @param Collection<int, TableReservation> $activeReservations */
    private function startCancel(Collection $activeReservations, array $intent, AiDecision $decision): AiDecision
    {
        if ($activeReservations->isEmpty()) {
            return $this->withReply($decision, 'table_cancel_none', 'У вас нет активных броней столика для отмены.');
        }

        if ($activeReservations->count() === 1) {
            return $this->attemptCancel($activeReservations->first(), $intent['cancel_reason'], $decision);
        }

        return $this->offerReservationsForDisambiguation($activeReservations, 'cancel', $decision, 'Уточните, пожалуйста, какую бронь отменить:');
    }

    /** @param Collection<int, TableReservation> $activeReservations */
    private function startReschedule(Company $company, Collection $activeReservations, array $intent, AiDecision $decision): AiDecision
    {
        if ($activeReservations->isEmpty()) {
            return $this->withReply($decision, 'table_reschedule_none', 'У вас нет активных броней столика для переноса.');
        }

        if ($activeReservations->count() === 1) {
            $pastDate = $this->pastDateNotice($intent['preferred_date'], $decision);
            if ($pastDate !== null) {
                return $pastDate;
            }

            $from = $intent['preferred_date'] ? $this->parsePreferredDate($intent['preferred_date']) : Carbon::now();

            return $this->offerRescheduleSlots($activeReservations->first(), $from, $decision);
        }

        return $this->offerReservationsForDisambiguation($activeReservations, 'reschedule', $decision, 'Уточните, пожалуйста, какую бронь перенести:');
    }

    private function startNewReservation(Tenant $tenant, Company $company, Conversation $conversation, array $intent, AiDecision $decision): AiDecision
    {
        if (! $intent['party_size']) {
            // Let the main reply stand -- TableReservationChatContext::promptSection()
            // already tells the model to ask for the party size when unclear.
            return $decision;
        }

        $pastDate = $this->pastDateNotice($intent['preferred_date'], $decision);
        if ($pastDate !== null) {
            return $pastDate;
        }

        $from = $intent['preferred_date'] ? $this->parsePreferredDate($intent['preferred_date']) : Carbon::now();

        return $this->offerNewReservationSlots($tenant, $company, $conversation->customer_id, $intent['party_size'], $from, $decision);
    }

    private function offerNewReservationSlots(Tenant $tenant, Company $company, int $customerId, int $partySize, Carbon $from, AiDecision $decision): AiDecision
    {
        $slots = $this->context->nextAvailableSlots($company, $partySize, $from->copy()->startOfDay());

        if ($slots === []) {
            return $this->withReply(
                $decision,
                'table_no_slots',
                "К сожалению, на ближайшее время нет свободных столиков на {$partySize} гостей. Оператор подберёт удобное время вручную и свяжется с вами.",
                handoff: true,
            );
        }

        $text = "Вот ближайшие свободные столики на {$partySize} гостей:\n"
            .$this->formatOffers($slots)
            ."\nНапишите номер варианта, который вам подходит, и я вас забронирую.";

        $rawButtons = TableReservationOfferButtons::build($slots);

        return $this->withReply($decision, 'table_offer', $text, meta: [
            'flow' => 'new_reservation',
            'offered_slots' => $slots,
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'customer_id' => $customerId,
            'party_size' => $partySize,
            'raw_buttons' => $rawButtons,
            'buttons' => ChatButtons::forOffer($rawButtons),
        ]);
    }

    private function offerRescheduleSlots(TableReservation $reservation, Carbon $from, AiDecision $decision): AiDecision
    {
        $company = $reservation->company ?? Company::withoutGlobalScopes()->find($reservation->company_id);

        if (! $company) {
            return $this->withReply($decision, 'table_reschedule_error', 'Не получилось найти бронь. Оператор свяжется с вами.', handoff: true);
        }

        $slots = $this->context->nextAvailableSlots($company, $reservation->party_size, $from->copy()->startOfDay());

        if ($slots === []) {
            return $this->withReply(
                $decision,
                'table_no_slots',
                'К сожалению, нет свободных столиков на ближайшее время для переноса. Оператор подберёт удобное время вручную.',
                handoff: true,
            );
        }

        $text = "Вот ближайшие свободные столики для переноса:\n"
            .$this->formatOffers($slots)
            ."\nНапишите номер подходящего варианта.";

        $rawButtons = TableReservationOfferButtons::build($slots);

        return $this->withReply($decision, 'table_reschedule_offer', $text, meta: [
            'flow' => 'table_reschedule',
            'reschedule_reservation_id' => $reservation->id,
            'offered_slots' => $slots,
            'raw_buttons' => $rawButtons,
            'buttons' => ChatButtons::forOffer($rawButtons),
        ]);
    }

    /** @param Collection<int, TableReservation> $activeReservations */
    private function offerReservationsForDisambiguation(Collection $activeReservations, string $for, AiDecision $decision, string $intro): AiDecision
    {
        $offered = $activeReservations->values()->map(fn (TableReservation $r): array => [
            'id' => $r->id,
            'resource_name' => $r->resource?->name ?? 'столик',
            'party_size' => $r->party_size,
            'starts_at' => $this->localIso($r),
        ])->all();

        $lines = collect($offered)->map(fn (array $r, int $i): string => ($i + 1).') '.$r['resource_name'].' — '.$this->formatWhen($r['starts_at']).', гостей: '.$r['party_size']);

        $text = $intro."\n".$lines->implode("\n");
        $rawButtons = TableReservationOfferButtons::forExistingReservations($offered);

        return $this->withReply($decision, 'table_disambiguate', $text, meta: [
            'flow' => 'disambiguate',
            'disambiguate_for' => $for,
            'offered_reservations' => $offered,
            'raw_buttons' => $rawButtons,
            'buttons' => ChatButtons::forOffer($rawButtons),
        ]);
    }

    /** @param array{resource_id:int, resource_name:string, capacity:int, starts_at:string, ends_at:string} $slot */
    private function attemptCreate(array $lastMeta, array $slot, AiDecision $decision): AiDecision
    {
        try {
            $reservation = $this->reservations->create([
                'tenant_id' => $lastMeta['tenant_id'],
                'company_id' => $lastMeta['company_id'],
                'customer_id' => $lastMeta['customer_id'],
                'resource_id' => $slot['resource_id'],
                'party_size' => $lastMeta['party_size'],
                'starts_at' => $slot['starts_at'],
                'notes' => 'Создано через AI-чат',
            ], null);
        } catch (TableReservationConflictException) {
            $company = Company::withoutGlobalScopes()->find($lastMeta['company_id']);

            if (! $company) {
                return $this->withReply($decision, 'table_conflict', 'Извините, этот столик только что заняли. Оператор свяжется с вами.', handoff: true);
            }

            $apology = $this->offerNewReservationSlots(
                Tenant::query()->findOrFail($lastMeta['tenant_id']),
                $company,
                $lastMeta['customer_id'],
                $lastMeta['party_size'],
                Carbon::parse($slot['starts_at'])->startOfDay(),
                $decision,
            );

            return $this->prefixApology($apology, 'Ой, этот столик только что заняли. ');
        }

        $when = $this->formatWhen($slot['starts_at']);
        $text = "Готово! Забронировал(а) вам {$slot['resource_name']} — {$when}, гостей: {$lastMeta['party_size']}. Если нужно перенести или отменить бронь — просто напишите об этом здесь.";

        return $this->withReply($decision, 'table_confirmed', $text);
    }

    /** @param array{resource_id:int, resource_name:string, capacity:int, starts_at:string, ends_at:string} $slot */
    private function attemptReschedule(TableReservation $reservation, array $slot, AiDecision $decision): AiDecision
    {
        try {
            $reservation = $this->reservations->reschedule($reservation, Carbon::parse($slot['starts_at']), null, comment: 'Перенесено клиентом через AI-чат');
        } catch (TableReservationConflictException $error) {
            return $this->withReply($decision, 'table_reschedule_conflict', $error->getMessage().' Оператор свяжется с вами, чтобы уточнить перенос.', handoff: true);
        }

        $when = $this->formatWhen($this->localIso($reservation));
        $text = "Готово! Перенёс(ла) бронь на {$reservation->resource?->name} — {$when}.";

        return $this->withReply($decision, 'table_rescheduled', $text);
    }

    private function attemptCancel(TableReservation $reservation, ?string $reason, AiDecision $decision): AiDecision
    {
        try {
            $reservation = $this->reservations->cancel($reservation, null, $reason ?? 'Отменено клиентом через AI-чат');
        } catch (TableReservationConflictException $error) {
            return $this->withReply($decision, 'table_cancel_conflict', $error->getMessage(), handoff: true);
        }

        $when = $this->formatWhen($this->localIso($reservation));
        $text = "Хорошо, отменил(а) бронь на {$reservation->resource?->name} — {$when}. Будем рады видеть вас снова!";

        return $this->withReply($decision, 'table_cancelled', $text);
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

    private function parsePreferredDate(string $date): Carbon
    {
        try {
            $parsed = Carbon::parse($date);
        } catch (Throwable) {
            return Carbon::now();
        }

        return $parsed->isPast() && ! $parsed->isToday() ? Carbon::now() : $parsed;
    }

    /** Same reasoning as AiChatBookingAssistant::pastDateNotice()'s own docblock -- a genuinely past date always gets an explicit, honest answer instead of being silently reinterpreted. */
    private function pastDateNotice(?string $preferredDate, AiDecision $decision): ?AiDecision
    {
        if ($preferredDate === null) {
            return null;
        }

        try {
            $parsed = Carbon::parse($preferredDate);
        } catch (Throwable) {
            return null;
        }

        if (! $parsed->isPast() || $parsed->isToday()) {
            return null;
        }

        return $this->withReply(
            $decision,
            'table_past_date',
            'Эта дата уже прошла — не могу забронировать на неё. Подскажите, пожалуйста, дату начиная с сегодняшнего дня.',
        );
    }

    /** @param array<int, array{resource_id:int, resource_name:string, capacity:int, starts_at:string, ends_at:string}> $slots */
    private function formatOffers(array $slots): string
    {
        return collect($slots)
            ->map(fn (array $slot, int $i): string => ($i + 1).') '.$this->formatWhen($slot['starts_at']).' — '.$slot['resource_name'])
            ->implode("\n");
    }

    /** Same UTC→local conversion concern as AiChatBookingAssistant::localIso()'s own docblock. */
    private function localIso(TableReservation $reservation): string
    {
        $timezone = $reservation->company?->timezone ?: config('app.timezone');

        return $reservation->starts_at->copy()->setTimezone($timezone)->toIso8601String();
    }

    /** Same translatedFormat()-is-broken-on-this-server workaround as AiChatBookingAssistant::formatWhen(). */
    private function formatWhen(string $isoDateTime): string
    {
        $weekdays = ['Mon' => 'пн', 'Tue' => 'вт', 'Wed' => 'ср', 'Thu' => 'чт', 'Fri' => 'пт', 'Sat' => 'сб', 'Sun' => 'вс'];
        $date = Carbon::parse($isoDateTime);
        $weekday = $weekdays[$date->format('D')] ?? $date->format('D');

        return $weekday.', '.$date->format('d.m в H:i');
    }

    /** @return Collection<int, TableReservation> */
    private function activeReservationsFor(Tenant $tenant, Conversation $conversation): Collection
    {
        return TableReservation::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('customer_id', $conversation->customer_id)
            ->whereIn('status', TableReservation::ACTIVE_STATUSES)
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
            nextAction: $handoff ? 'handoff_operator' : 'table_reservation_flow',
            handoffRequired: $handoff,
            replyText: $text,
            meta: $meta,
        );
    }
}
