<?php

namespace App\Support\Booking;

use App\Models\Booking;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Service;
use App\Models\Tenant;
use App\Support\Ai\AiDecision;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ТЗ раздел 12 — "запись/перенос/отмена через AI-чат". Called from
 * AiWorkflow::process() right after enforceBusinessRules(), so it can
 * override whatever the main reply engine (Dify/direct-LLM/local-mvp) said
 * with a booking-aware reply — but only for tenants where
 * BookingChatContext::isAvailableFor() is true, so every tenant without the
 * booking module configured pays zero extra cost.
 *
 * Correctness rule this whole class exists to enforce: a specific
 * appointment time NEVER reaches the customer unless it came out of
 * AvailabilityCalculator via BookingChatContext — never out of the LLM's own
 * free-text generation. BookingIntentExtractor only ever extracts which
 * service/date the customer meant, whether they mean an existing booking to
 * reschedule/cancel, and which previously-offered slot/booking (if any) they
 * picked; this class is the only place real slots get computed and the only
 * place BookingService's write methods are ever called from a chat context.
 *
 * Multi-turn state (which slots or which of the customer's bookings were
 * just offered) rides on the AI's own Message.meta — no new table. Each
 * offer tags a `flow` (new_booking/reschedule/disambiguate_cancel/
 * disambiguate_reschedule) so the next turn knows what a picked index means.
 */
class AiChatBookingAssistant
{
    public function __construct(
        private readonly BookingChatContext $context,
        private readonly BookingIntentExtractor $extractor,
        private readonly BookingService $bookings,
    ) {
    }

    public function maybeHandle(Tenant $tenant, Company $company, Conversation $conversation, Message $message, AiDecision $decision): AiDecision
    {
        if (! $conversation->customer_id || ! $this->context->isAvailableFor($company)) {
            return $decision;
        }

        // This sits in the hot path of every AI reply for booking-enabled tenants and
        // is new, more involved code (LLM extraction + real availability + a real DB
        // write) than the rest of this pipeline -- an unexpected exception here must
        // degrade to the customer still getting the main engine's ordinary reply, not
        // lose the reply entirely. BookingConflictException is NOT caught here -- every
        // call site that can throw it already handles it with a proper reply of its own.
        try {
            return $this->handle($tenant, $company, $conversation, $message, $decision);
        } catch (Throwable $error) {
            Log::warning('AiChatBookingAssistant failed, falling back to the original reply', [
                'tenant_id' => $tenant->id,
                'conversation_id' => $conversation->id,
                'error' => $error->getMessage(),
            ]);

            return $decision;
        }
    }

    private function handle(Tenant $tenant, Company $company, Conversation $conversation, Message $message, AiDecision $decision): AiDecision
    {
        $services = $this->context->activeServices($company);
        $activeBookings = $this->activeBookingsFor($tenant, $conversation);
        $lastMeta = $this->lastAiMeta($conversation);
        $offeredSlots = is_array($lastMeta['offered_slots'] ?? null) ? $lastMeta['offered_slots'] : [];

        $intent = $this->extractor->extract($tenant, $conversation, $message, $services, $offeredSlots, $activeBookings);

        if ($intent === null) {
            return $decision;
        }

        $continued = $this->continueFlow($tenant, $company, $conversation, $lastMeta, $intent, $decision);

        if ($continued !== null) {
            return $continued;
        }

        if ($intent['wants_cancel']) {
            return $this->startCancel($activeBookings, $intent, $decision);
        }

        if ($intent['wants_reschedule']) {
            return $this->startReschedule($company, $activeBookings, $intent, $decision);
        }

        if ($intent['wants_booking']) {
            return $this->startNewBooking($tenant, $company, $conversation, $services, $intent, $decision);
        }

        return $decision;
    }

    /** Resolves a pending pick from the PREVIOUS turn's offer, based on what that offer was for. Returns null when there's nothing to continue -- a fresh intent this turn, handled by the caller. */
    private function continueFlow(Tenant $tenant, Company $company, Conversation $conversation, array $lastMeta, array $intent, AiDecision $decision): ?AiDecision
    {
        $flow = $lastMeta['flow'] ?? null;
        $offeredSlots = is_array($lastMeta['offered_slots'] ?? null) ? $lastMeta['offered_slots'] : [];
        $offeredBookings = is_array($lastMeta['offered_bookings'] ?? null) ? $lastMeta['offered_bookings'] : [];

        if ($flow === 'new_booking' && $intent['selected_offer_index'] !== null && isset($offeredSlots[$intent['selected_offer_index']])) {
            return $this->attemptCreate($tenant, $company, $conversation, $offeredSlots[$intent['selected_offer_index']], $decision);
        }

        if ($flow === 'reschedule' && $intent['selected_offer_index'] !== null && isset($offeredSlots[$intent['selected_offer_index']])) {
            $booking = Booking::withoutGlobalScopes()->find($lastMeta['reschedule_booking_id'] ?? null);

            return $booking ? $this->attemptReschedule($booking, $offeredSlots[$intent['selected_offer_index']], $decision) : null;
        }

        if ($flow === 'disambiguate_cancel' && $intent['selected_booking_index'] !== null && isset($offeredBookings[$intent['selected_booking_index']])) {
            $booking = Booking::withoutGlobalScopes()->find($offeredBookings[$intent['selected_booking_index']]['id']);

            return $booking ? $this->attemptCancel($booking, $intent['cancel_reason'], $decision) : null;
        }

        if ($flow === 'disambiguate_reschedule' && $intent['selected_booking_index'] !== null && isset($offeredBookings[$intent['selected_booking_index']])) {
            $booking = Booking::withoutGlobalScopes()->find($offeredBookings[$intent['selected_booking_index']]['id']);

            if (! $booking) {
                return null;
            }

            $from = $intent['preferred_date'] ? $this->parsePreferredDate($intent['preferred_date']) : Carbon::now();

            return $this->offerRescheduleSlots($company, $booking, $from, $decision);
        }

        return null;
    }

    /** @param Collection<int, Booking> $activeBookings */
    private function startCancel(Collection $activeBookings, array $intent, AiDecision $decision): AiDecision
    {
        if ($activeBookings->isEmpty()) {
            return $this->withReply($decision, 'booking_cancel_none', 'У вас нет активных записей для отмены.');
        }

        if ($activeBookings->count() === 1) {
            return $this->attemptCancel($activeBookings->first(), $intent['cancel_reason'], $decision);
        }

        return $this->offerBookingsForDisambiguation($activeBookings, 'disambiguate_cancel', $decision, 'Уточните, пожалуйста, какую запись отменить:');
    }

    /** @param Collection<int, Booking> $activeBookings */
    private function startReschedule(Company $company, Collection $activeBookings, array $intent, AiDecision $decision): AiDecision
    {
        if ($activeBookings->isEmpty()) {
            return $this->withReply($decision, 'booking_reschedule_none', 'У вас нет активных записей для переноса.');
        }

        if ($activeBookings->count() === 1) {
            $from = $intent['preferred_date'] ? $this->parsePreferredDate($intent['preferred_date']) : Carbon::now();

            return $this->offerRescheduleSlots($company, $activeBookings->first(), $from, $decision);
        }

        return $this->offerBookingsForDisambiguation($activeBookings, 'disambiguate_reschedule', $decision, 'Уточните, пожалуйста, какую запись перенести:');
    }

    /** @param Collection<int, Service> $services */
    private function startNewBooking(Tenant $tenant, Company $company, Conversation $conversation, Collection $services, array $intent, AiDecision $decision): AiDecision
    {
        $service = $this->resolveService($services, $intent['service_name']);

        if (! $service) {
            // Let the main reply stand -- it now has the real service list injected via
            // BookingChatContext::promptSection(), so it should naturally ask which
            // service the customer means rather than us guessing.
            return $decision;
        }

        $from = $intent['preferred_date'] ? $this->parsePreferredDate($intent['preferred_date']) : Carbon::now();

        return $this->offerNewBookingSlots($company, $service, $from, $decision);
    }

    private function offerNewBookingSlots(Company $company, Service $service, Carbon $from, AiDecision $decision): AiDecision
    {
        $slots = $this->context->nextAvailableSlots($company, $service, $from->copy()->startOfDay());

        if ($slots === []) {
            return $this->withReply(
                $decision,
                'booking_no_slots',
                "К сожалению, на ближайшее время нет свободных окон на «{$service->name}». Оператор подберёт удобное время вручную и свяжется с вами.",
                handoff: true,
            );
        }

        $text = "Вот ближайшее свободное время на «{$service->name}»:\n"
            .$this->formatOffers($slots)
            ."\nНапишите номер варианта, который вам подходит, и я вас запишу.";

        return $this->withReply($decision, 'booking_offer', $text, meta: ['flow' => 'new_booking', 'offered_slots' => $slots]);
    }

    private function offerRescheduleSlots(Company $company, Booking $booking, Carbon $from, AiDecision $decision): AiDecision
    {
        $service = $booking->service ?? Service::withoutGlobalScopes()->find($booking->service_id);

        if (! $service) {
            return $this->withReply($decision, 'booking_reschedule_error', 'Не получилось найти услугу для этой записи. Оператор свяжется с вами.', handoff: true);
        }

        $slots = $this->context->nextAvailableSlots($company, $service, $from->copy()->startOfDay(), $booking->employee_id);

        if ($slots === []) {
            return $this->withReply(
                $decision,
                'booking_no_slots',
                "К сожалению, у мастера нет свободных окон на «{$service->name}» на ближайшее время. Оператор подберёт удобное время вручную.",
                handoff: true,
            );
        }

        $text = "Вот ближайшее свободное время для переноса «{$service->name}»:\n"
            .$this->formatOffers($slots)
            ."\nНапишите номер подходящего варианта.";

        return $this->withReply($decision, 'booking_reschedule_offer', $text, meta: [
            'flow' => 'reschedule',
            'reschedule_booking_id' => $booking->id,
            'offered_slots' => $slots,
        ]);
    }

    /** @param Collection<int, Booking> $activeBookings */
    private function offerBookingsForDisambiguation(Collection $activeBookings, string $flow, AiDecision $decision, string $intro): AiDecision
    {
        $offered = $activeBookings->values()->map(fn (Booking $booking): array => [
            'id' => $booking->id,
            'service_name' => $booking->service?->name ?? 'услуга',
            'employee_name' => $booking->employee?->name ?? '',
            'starts_at' => $this->localIso($booking),
        ])->all();

        $lines = collect($offered)->map(fn (array $booking, int $i): string => ($i + 1).') '.$booking['service_name'].' — '.$this->formatWhen($booking['starts_at']).' — '.$booking['employee_name']);

        $text = $intro."\n".$lines->implode("\n");

        return $this->withReply($decision, 'booking_disambiguate', $text, meta: ['flow' => $flow, 'offered_bookings' => $offered]);
    }

    /** @param array{employee_id:int, employee_name:string, service_id:int, service_name:string, starts_at:string, ends_at:string} $slot */
    private function attemptCreate(Tenant $tenant, Company $company, Conversation $conversation, array $slot, AiDecision $decision): AiDecision
    {
        try {
            $booking = $this->bookings->create([
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'customer_id' => $conversation->customer_id,
                'service_id' => $slot['service_id'],
                'employee_id' => $slot['employee_id'],
                'starts_at' => $slot['starts_at'],
                'notes' => 'Создано через AI-чат',
            ], null);
        } catch (BookingConflictException) {
            // Someone else took it between offering and confirming -- pure inventory
            // contention, always safe to recompute fresh real slots for the same
            // service/day rather than telling the customer "done" for a booking that
            // doesn't exist.
            $service = Service::withoutGlobalScopes()->find($slot['service_id']);

            if (! $service) {
                return $this->withReply($decision, 'booking_conflict', 'Извините, это время только что заняли, и услугу не удалось найти повторно. Оператор свяжется с вами.', handoff: true);
            }

            $apology = $this->offerNewBookingSlots($company, $service, Carbon::parse($slot['starts_at'])->startOfDay(), $decision);

            return $this->prefixApology($apology, 'Ой, это время только что заняли. ');
        }

        $when = $this->formatWhen($slot['starts_at']);
        $paymentNote = $booking->prepayment_amount > 0
            ? ' Для подтверждения потребуется предоплата '.number_format((float) $booking->prepayment_amount, 0, ',', ' ').' смн — с вами свяжется администратор.'
            : '';

        $text = "Готово! Записал(а) вас на «{$slot['service_name']}» — {$when}, мастер {$slot['employee_name']}.{$paymentNote} Если нужно перенести или отменить запись — просто напишите об этом здесь.";

        return $this->withReply($decision, 'booking_confirmed', $text);
    }

    /** @param array{employee_id:int, employee_name:string, service_id:int, service_name:string, starts_at:string, ends_at:string} $slot */
    private function attemptReschedule(Booking $booking, array $slot, AiDecision $decision): AiDecision
    {
        try {
            $booking = $this->bookings->reschedule($booking, Carbon::parse($slot['starts_at']), null, isClientInitiated: true, comment: 'Перенесено клиентом через AI-чат');
        } catch (BookingConflictException $error) {
            // Unlike a new booking's conflict (pure inventory contention, always safe to
            // silently re-offer), a reschedule conflict can ALSO mean a CancellationPolicy
            // rule blocked it (e.g. too close to the appointment) -- that would keep
            // failing identically no matter what time is offered next, so a human needs
            // to look at it rather than the bot looping fresh offers against the same
            // policy wall. $error->getMessage() is already a clear, customer-facing
            // Russian sentence either way (see BookingService::reschedule()).
            return $this->withReply($decision, 'booking_reschedule_conflict', $error->getMessage().' Оператор свяжется с вами, чтобы уточнить перенос.', handoff: true);
        }

        $when = $this->formatWhen($this->localIso($booking));
        $text = "Готово! Перенёс(ла) запись на «{$booking->service?->name}» — {$when}, мастер {$booking->employee?->name}.";

        return $this->withReply($decision, 'booking_rescheduled', $text);
    }

    private function attemptCancel(Booking $booking, ?string $reason, AiDecision $decision): AiDecision
    {
        try {
            $booking = $this->bookings->cancel($booking, null, $reason ?? 'Отменено клиентом через AI-чат', isClientInitiated: true);
        } catch (BookingConflictException $error) {
            return $this->withReply($decision, 'booking_cancel_conflict', $error->getMessage(), handoff: true);
        }

        $when = $this->formatWhen($this->localIso($booking));
        $text = "Хорошо, отменил(а) запись на «{$booking->service?->name}» — {$when}. Будем рады видеть вас снова!";

        return $this->withReply($decision, 'booking_cancelled', $text);
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

    /** @param Collection<int, Service> $services */
    private function resolveService(Collection $services, ?string $name): ?Service
    {
        if (! $name) {
            return null;
        }

        return $services->first(fn (Service $service): bool => mb_strtolower(trim($service->name)) === mb_strtolower(trim($name)));
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

    /** @param array<int, array{employee_id:int, employee_name:string, starts_at:string, ends_at:string}> $slots */
    private function formatOffers(array $slots): string
    {
        return collect($slots)
            ->map(fn (array $slot, int $i): string => ($i + 1).') '.$this->formatWhen($slot['starts_at']).' — '.$slot['employee_name'])
            ->implode("\n");
    }

    /**
     * Booking.starts_at casts to a Carbon in the app's storage timezone (UTC) --
     * unlike a slot straight out of AvailabilityCalculator (already ISO8601 with the
     * company's own offset embedded), a booking's own attribute must be converted
     * back to the company's local timezone before it's shown to the customer, same
     * conversion BookingReminderSender::message() already does. Found live: without
     * this, a reschedule/cancel confirmation showed the booking's raw UTC time
     * (e.g. "04:00" for a real 09:00 Asia/Dushanbe appointment).
     */
    private function localIso(Booking $booking): string
    {
        $timezone = $booking->company?->timezone ?: config('app.timezone');

        return $booking->starts_at->copy()->setTimezone($timezone)->toIso8601String();
    }

    /** Carbon's translatedFormat() with month/weekday tokens (MMMM/EEEE) was found broken on this server (repeated/garbled month names) -- a hand-built map is the safe alternative, same reasoning as BookingReminderSender's own numeric-only translatedFormat() usage. */
    private function formatWhen(string $isoDateTime): string
    {
        $weekdays = ['Mon' => 'пн', 'Tue' => 'вт', 'Wed' => 'ср', 'Thu' => 'чт', 'Fri' => 'пт', 'Sat' => 'сб', 'Sun' => 'вс'];
        $date = Carbon::parse($isoDateTime);
        $weekday = $weekdays[$date->format('D')] ?? $date->format('D');

        return $weekday.', '.$date->format('d.m в H:i');
    }

    /** @return Collection<int, Booking> */
    private function activeBookingsFor(Tenant $tenant, Conversation $conversation): Collection
    {
        return Booking::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('customer_id', $conversation->customer_id)
            ->whereIn('status', Booking::ACTIVE_STATUSES)
            ->with(['service:id,name', 'employee:id,name', 'company:id,timezone'])
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
            nextAction: $handoff ? 'handoff_operator' : 'booking_flow',
            handoffRequired: $handoff,
            replyText: $text,
            meta: $meta,
        );
    }
}
