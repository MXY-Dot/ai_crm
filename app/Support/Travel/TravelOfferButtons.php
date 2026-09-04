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
}
