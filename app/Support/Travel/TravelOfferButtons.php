<?php

namespace App\Support\Travel;

use App\Support\Business\Currency;

/** Same role as App\Support\Booking\BookingOfferButtons, for TravelChatAssistant's own `offered_departures` shape (see TravelChatContext::openDeparturesForTour()). See that class's own docblock for why this stays a separate file per module, and for why this returns raw picks only (no assistant option, no pagination). */
class TravelOfferButtons
{
    /**
     * @param array<int, array{departure_date:string, return_date:string, price:float, seats_remaining:?int}> $departures
     * @return array<int, array{id:string, title:string, description?:string}>
     */
    public static function build(array $departures, ?string $currencyCode): array
    {
        return collect($departures)->map(fn (array $d, int $i): array => [
            'id' => (string) ($i + 1),
            'title' => date('d.m', strtotime($d['departure_date'])).'–'.date('d.m', strtotime($d['return_date'])),
            'description' => Currency::format($d['price'], $currencyCode).($d['seats_remaining'] !== null ? ', мест: '.$d['seats_remaining'] : ''),
        ])->values()->all();
    }

    /**
     * Same button treatment for TravelChatAssistant::startCancel()'s own
     * "which of your existing bookings do you mean" prompt -- a single
     * pre-formatted label (tour + date + pax), not split fields.
     *
     * @param array<int, array{label:string}> $bookings
     * @return array<int, array{id:string, title:string}>
     */
    public static function forExistingBookings(array $bookings): array
    {
        return collect($bookings)->map(fn (array $b, int $i): array => [
            'id' => (string) ($i + 1),
            'title' => mb_strimwidth($b['label'], 0, 24, '…'),
        ])->values()->all();
    }
}
