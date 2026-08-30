<?php

namespace App\Support\Booking;

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
 * ТЗ раздел 12 — "запись через AI-чат". Called from AiWorkflow::process()
 * right after enforceBusinessRules(), so it can override whatever the main
 * reply engine (Dify/direct-LLM/local-mvp) said with a booking-aware reply —
 * but only for tenants where BookingChatContext::isAvailableFor() is true, so
 * every tenant without the booking module configured pays zero extra cost.
 *
 * Correctness rule this whole class exists to enforce: a specific appointment
 * time NEVER reaches the customer unless it came out of
 * AvailabilityCalculator via BookingChatContext — never out of the LLM's own
 * free-text generation. BookingIntentExtractor only ever extracts which
 * service/date the customer meant and which previously-offered slot (if any)
 * they picked; this class is the only place real slots get computed and the
 * only place BookingService::create() is ever called from a chat context.
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
        // lose the reply entirely. BookingConflictException from attemptBooking() is
        // NOT caught here -- it's already handled inside attemptBooking() itself with
        // a proper apology-and-reoffer reply, so it never needs to reach this net.
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
        $offeredSlots = $this->lastOfferedSlots($conversation);

        $intent = $this->extractor->extract($tenant, $conversation, $message, $services, $offeredSlots);

        if ($intent === null || ! $intent['wants_booking']) {
            return $decision;
        }

        if ($offeredSlots !== [] && $intent['selected_offer_index'] !== null && isset($offeredSlots[$intent['selected_offer_index']])) {
            return $this->attemptBooking($tenant, $company, $conversation, $offeredSlots[$intent['selected_offer_index']], $decision);
        }

        $service = $this->resolveService($services, $intent['service_name']);

        if (! $service) {
            // Let the main reply stand -- it now has the real service list injected via
            // BookingChatContext::promptSection(), so it should naturally ask which
            // service the customer means rather than us guessing.
            return $decision;
        }

        $from = $intent['preferred_date'] ? $this->parsePreferredDate($intent['preferred_date']) : Carbon::now();

        return $this->offerSlots($company, $service, $from, $decision);
    }

    private function offerSlots(Company $company, Service $service, Carbon $from, AiDecision $decision): AiDecision
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

        return $this->withReply($decision, 'booking_offer', $text, meta: ['offered_slots' => $slots]);
    }

    /** @param array{employee_id:int, employee_name:string, service_id:int, service_name:string, starts_at:string, ends_at:string} $slot */
    private function attemptBooking(Tenant $tenant, Company $company, Conversation $conversation, array $slot, AiDecision $decision): AiDecision
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
            // Someone else took it between offering and confirming -- recompute fresh
            // real slots for the same service/day rather than telling the customer
            // "done" for a booking that doesn't exist.
            $service = Service::withoutGlobalScopes()->find($slot['service_id']);

            if (! $service) {
                return $this->withReply($decision, 'booking_conflict', 'Извините, это время только что заняли, и услугу не удалось найти повторно. Оператор свяжется с вами.', handoff: true);
            }

            $apology = $this->offerSlots($company, $service, Carbon::parse($slot['starts_at'])->startOfDay(), $decision);

            return new AiDecision(
                confidence: $apology->confidence,
                intent: 'booking_conflict',
                summary: $apology->summary,
                nextAction: $apology->nextAction,
                handoffRequired: $apology->handoffRequired,
                replyText: 'Ой, это время только что заняли. '.$apology->replyText,
                meta: $apology->meta,
            );
        }

        $when = $this->formatWhen($slot['starts_at']);
        $paymentNote = $booking->prepayment_amount > 0
            ? ' Для подтверждения потребуется предоплата '.number_format((float) $booking->prepayment_amount, 0, ',', ' ').' смн — с вами свяжется администратор.'
            : '';

        $text = "Готово! Записал(а) вас на «{$slot['service_name']}» — {$when}, мастер {$slot['employee_name']}.{$paymentNote} Если нужно перенести или отменить запись — просто напишите об этом здесь.";

        return $this->withReply($decision, 'booking_confirmed', $text);
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

    /** Carbon's translatedFormat() with month/weekday tokens (MMMM/EEEE) was found broken on this server (repeated/garbled month names) -- a hand-built map is the safe alternative, same reasoning as BookingReminderSender's own numeric-only translatedFormat() usage. */
    private function formatWhen(string $isoDateTime): string
    {
        $weekdays = ['Mon' => 'пн', 'Tue' => 'вт', 'Wed' => 'ср', 'Thu' => 'чт', 'Fri' => 'пт', 'Sat' => 'сб', 'Sun' => 'вс'];
        $date = Carbon::parse($isoDateTime);
        $weekday = $weekdays[$date->format('D')] ?? $date->format('D');

        return $weekday.', '.$date->format('d.m в H:i');
    }

    /** @return array<int, array{employee_id:int, employee_name:string, starts_at:string, ends_at:string}> */
    private function lastOfferedSlots(Conversation $conversation): array
    {
        $lastAiMessage = Message::withoutGlobalScopes()
            ->where('conversation_id', $conversation->id)
            ->where('sender_type', 'ai')
            ->latest('id')
            ->first();

        $offered = $lastAiMessage?->meta['offered_slots'] ?? [];

        return is_array($offered) ? $offered : [];
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
