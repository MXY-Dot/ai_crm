<?php

namespace App\Support\Travel;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\Tour;
use App\Models\TourBooking;
use App\Support\Ai\AiDecision;
use App\Support\Chat\ChatButtons;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ТЗ раздел 9/12 — "заявка на тур через AI-чат". Runs in AiWorkflow's
 * chat-assistant chain after Booking/Table/Room/RepairOrder/Education,
 * same chaining reasoning every other assistant's own docblock already
 * gives. Applies both feedback_chat_assistant_flow_naming lessons from
 * the start (unique `travel_*` flow prefixes,
 * alreadyClaimedByAnotherModule() guard), same as every assistant added
 * this round.
 *
 * Shape-wise a close sibling of EducationChatAssistant: Tour is the named
 * catalog thing (like Course), TourDeparture is the real offered option
 * (like a CourseGroup) -- except a booking here can consume more than one
 * seat (`pax_count`, mirrors TableReservationChatAssistant's own
 * `party_size`), so the capacity math sums pax rather than counting
 * bookings, same arithmetic TravelChatContext::openDeparturesForTour()
 * and TourBookingService::book() both already use. No reschedule flow --
 * a departure has fixed dates set by the company, not something a
 * customer's own booking can move.
 */
class TravelChatAssistant
{
    public function __construct(
        private readonly TravelChatContext $context,
        private readonly TravelIntentExtractor $extractor,
        private readonly TourBookingService $bookings,
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
            Log::warning('TravelChatAssistant failed, falling back to the original reply', [
                'tenant_id' => $tenant->id,
                'conversation_id' => $conversation->id,
                'error' => $error->getMessage(),
            ]);

            return $decision;
        }
    }

    private function handle(Tenant $tenant, Company $company, Conversation $conversation, Message $message, AiDecision $decision): AiDecision
    {
        $tours = $this->context->activeTours($company);
        $activeBookings = $this->activeBookingsFor($tenant, $conversation);
        $lastMeta = $this->lastAiMeta($conversation);

        $ownFlow = in_array($lastMeta['flow'] ?? null, ['travel_offer_departures'], true);
        $offeredDepartures = $ownFlow && is_array($lastMeta['offered_departures'] ?? null) ? $lastMeta['offered_departures'] : [];

        $intent = $this->extractor->extract($tenant, $conversation, $message, $tours, $offeredDepartures, $activeBookings);

        if ($intent !== null) {
            $continued = $this->continueFlow($tenant, $company, $conversation, $lastMeta, $intent, $decision);

            if ($continued !== null) {
                return $continued;
            }

            if ($intent['wants_cancel']) {
                return $this->startCancel($activeBookings, $intent, $decision);
            }

            if ($intent['wants_book']) {
                return $this->startBooking($tenant, $company, $conversation, $tours, $intent, $decision);
            }
        }

        if ($this->alreadyClaimedByAnotherModule($decision)) {
            return $decision;
        }

        return $this->reofferForPendingFlow($lastMeta, $decision) ?? $decision;
    }

    /** @see AiChatBookingAssistant::handle()'s own docblock on why this guard exists. */
    private function alreadyClaimedByAnotherModule(AiDecision $decision): bool
    {
        return in_array($decision->nextAction, ['booking_flow', 'table_reservation_flow', 'room_reservation_flow', 'repair_order_flow', 'education_flow', 'handoff_operator'], true);
    }

    private function reofferForPendingFlow(array $lastMeta, AiDecision $decision): ?AiDecision
    {
        $flow = $lastMeta['flow'] ?? null;

        if ($flow === 'travel_offer_departures') {
            $offered = is_array($lastMeta['offered_departures'] ?? null) ? $lastMeta['offered_departures'] : [];

            if ($offered === []) {
                return null;
            }

            return $this->withReply($decision, 'travel_reoffer', 'Уточните, пожалуйста, какой из предложенных заездов вам подходит:'."\n".$this->formatOffers($offered), meta: $lastMeta);
        }

        if ($flow === 'travel_disambiguate_booking') {
            $offered = is_array($lastMeta['offered_bookings'] ?? null) ? $lastMeta['offered_bookings'] : [];

            if ($offered === []) {
                return null;
            }

            $lines = collect($offered)->map(fn (array $b, int $i): string => ($i + 1).') '.$b['label']);

            return $this->withReply($decision, 'travel_reoffer', 'Уточните, пожалуйста, какую заявку вы имеете в виду:'."\n".$lines->implode("\n"), meta: $lastMeta);
        }

        return null;
    }

    private function continueFlow(Tenant $tenant, Company $company, Conversation $conversation, array $lastMeta, array $intent, AiDecision $decision): ?AiDecision
    {
        $flow = $lastMeta['flow'] ?? null;

        if ($flow === 'travel_offer_departures' && $intent['selected_departure_index'] !== null) {
            $offered = is_array($lastMeta['offered_departures'] ?? null) ? $lastMeta['offered_departures'] : [];
            $paxCount = $lastMeta['pax_count'] ?? null;

            return (isset($offered[$intent['selected_departure_index']]) && $paxCount)
                ? $this->attemptBook($tenant, $company, $conversation, $offered[$intent['selected_departure_index']], $paxCount, $decision)
                : null;
        }

        if ($flow === 'travel_disambiguate_booking' && $intent['selected_booking_index'] !== null) {
            $offered = is_array($lastMeta['offered_bookings'] ?? null) ? $lastMeta['offered_bookings'] : [];

            if (! isset($offered[$intent['selected_booking_index']])) {
                return null;
            }

            $booking = TourBooking::withoutGlobalScopes()->with(['tourDeparture.tour'])->find($offered[$intent['selected_booking_index']]['id']);

            return $booking ? $this->attemptCancel($booking, $intent['cancel_reason'], $decision) : null;
        }

        return null;
    }

    /** @param Collection<int, TourBooking> $activeBookings */
    private function startCancel(Collection $activeBookings, array $intent, AiDecision $decision): AiDecision
    {
        if ($activeBookings->isEmpty()) {
            return $this->withReply($decision, 'travel_cancel_none', 'У вас нет активных заявок на туры для отмены.');
        }

        if ($activeBookings->count() === 1) {
            return $this->attemptCancel($activeBookings->first(), $intent['cancel_reason'], $decision);
        }

        $offered = $activeBookings->values()->map(fn (TourBooking $b): array => [
            'id' => $b->id,
            'label' => ($b->tourDeparture?->tour?->name ?? 'тур').', '.($b->tourDeparture?->departure_date?->toDateString() ?? '').', человек: '.$b->pax_count,
        ])->all();

        $lines = collect($offered)->map(fn (array $b, int $i): string => ($i + 1).') '.$b['label']);
        $text = 'Уточните, пожалуйста, какую заявку отменить:'."\n".$lines->implode("\n");

        $rawButtons = TravelOfferButtons::forExistingBookings($offered);

        return $this->withReply($decision, 'travel_disambiguate', $text, meta: [
            'flow' => 'travel_disambiguate_booking',
            'offered_bookings' => $offered,
            'raw_buttons' => $rawButtons,
            'buttons' => ChatButtons::forOffer($rawButtons),
        ]);
    }

    /** @param Collection<int, Tour> $tours */
    private function startBooking(Tenant $tenant, Company $company, Conversation $conversation, Collection $tours, array $intent, AiDecision $decision): AiDecision
    {
        $tour = $this->resolveTour($tours, $intent['tour_name']);

        if (! $tour || ! $intent['pax_count']) {
            // Let the main reply stand -- TravelChatContext::promptSection()
            // already tells the model to ask which tour + how many people.
            return $decision;
        }

        $departures = $this->context->openDeparturesForTour($company, $tour, $intent['pax_count']);

        if ($departures === []) {
            return $this->withReply(
                $decision,
                'travel_no_departures',
                "К сожалению, сейчас нет открытых заездов на тур «{$tour->name}» на {$intent['pax_count']} чел. Оператор подберёт вариант вручную и свяжется с вами.",
                handoff: true,
            );
        }

        if (count($departures) === 1) {
            return $this->attemptBook($tenant, $company, $conversation, $departures[0], $intent['pax_count'], $decision);
        }

        $text = "Вот открытые заезды на тур «{$tour->name}»:\n"
            .$this->formatOffers($departures)
            ."\nНапишите номер варианта, который вам подходит, и я оформлю заявку.";

        $rawButtons = TravelOfferButtons::build($departures, $company->currency);

        return $this->withReply($decision, 'travel_offer', $text, meta: [
            'flow' => 'travel_offer_departures',
            'offered_departures' => $departures,
            'pax_count' => $intent['pax_count'],
            'raw_buttons' => $rawButtons,
            'buttons' => ChatButtons::forOffer($rawButtons),
        ]);
    }

    /** @param array{departure_id:int, tour_name:string, departure_date:string, return_date:string, price:float, seats_remaining:?int} $departure */
    private function attemptBook(Tenant $tenant, Company $company, Conversation $conversation, array $departure, int $paxCount, AiDecision $decision): AiDecision
    {
        try {
            $this->bookings->book([
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'tour_departure_id' => $departure['departure_id'],
                'customer_id' => $conversation->customer_id,
                'pax_count' => $paxCount,
                'notes' => 'Создано через AI-чат',
            ], null);
        } catch (TravelConflictException $error) {
            return $this->withReply($decision, 'travel_conflict', $error->getMessage().' Если хотите, я поищу другой заезд — просто напишите об этом.');
        }

        $when = $this->formatRange($departure['departure_date'], $departure['return_date']);
        $text = "Готово! Оформил(а) заявку на тур «{$departure['tour_name']}» — {$when}, человек: {$paxCount}. Оператор свяжется с вами для подтверждения и оплаты.";

        return $this->withReply($decision, 'travel_booked', $text);
    }

    private function attemptCancel(TourBooking $booking, ?string $reason, AiDecision $decision): AiDecision
    {
        $tourName = $booking->tourDeparture?->tour?->name ?? 'тур';

        try {
            $this->bookings->cancel($booking, null, $reason ?? 'Отменено клиентом через AI-чат');
        } catch (TravelConflictException $error) {
            return $this->withReply($decision, 'travel_cancel_conflict', $error->getMessage(), handoff: true);
        }

        $text = "Хорошо, отменил(а) вашу заявку на тур «{$tourName}».";

        return $this->withReply($decision, 'travel_cancelled', $text);
    }

    /** @param Collection<int, Tour> $tours */
    private function resolveTour(Collection $tours, ?string $name): ?Tour
    {
        if (! $name) {
            return null;
        }

        return $tours->first(fn (Tour $t): bool => mb_strtolower(trim($t->name)) === mb_strtolower(trim($name)));
    }

    /** @param array<int, array{departure_id:int, tour_name:string, departure_date:string, return_date:string, price:float, seats_remaining:?int}> $departures */
    private function formatOffers(array $departures): string
    {
        return collect($departures)
            ->map(fn (array $d, int $i): string => ($i + 1).') '.$this->formatRange($d['departure_date'], $d['return_date']).' — '.number_format($d['price'], 0, ',', ' ').' смн'.($d['seats_remaining'] !== null ? ' (свободно мест: '.$d['seats_remaining'].')' : ''))
            ->implode("\n");
    }

    private function formatRange(string $departureDate, string $returnDate): string
    {
        $fmt = fn (string $d): string => date('d.m', strtotime($d));

        return $fmt($departureDate).' — '.$fmt($returnDate).'.'.date('Y', strtotime($returnDate));
    }

    /** @return Collection<int, TourBooking> */
    private function activeBookingsFor(Tenant $tenant, Conversation $conversation): Collection
    {
        return TourBooking::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('customer_id', $conversation->customer_id)
            ->whereIn('status', TourBooking::ACTIVE_STATUSES)
            ->with(['tourDeparture.tour'])
            ->orderByDesc('id')
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
            nextAction: $handoff ? 'handoff_operator' : 'travel_flow',
            handoffRequired: $handoff,
            replyText: $text,
            meta: $meta,
        );
    }
}
