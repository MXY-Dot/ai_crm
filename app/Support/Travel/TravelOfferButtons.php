<?php

namespace App\Support\Travel;

use App\Support\Chat\ChatButtons;

/** Same role as App\Support\Booking\BookingOfferButtons, for TravelChatAssistant's own `offered_departures` shape (see TravelChatContext::openDeparturesForTour()). See that class's own docblock for why this stays a separate file per module. */
class TravelOfferButtons
{
    /**
     * @param array<int, array{departure_date:string, return_date:string, price:float, seats_remaining:?int}> $departures
     * @return array<int, array{id:string, title:string, description?:string}>
     */
    public static function build(array $departures): array
    {
        $buttons = collect($departures)->map(fn (array $d, int $i): array => [
            'id' => (string) ($i + 1),
            'title' => date('d.m', strtotime($d['departure_date'])).'–'.date('d.m', strtotime($d['return_date'])),
            'description' => number_format($d['price'], 0, ',', ' ').' смн'.($d['seats_remaining'] !== null ? ', мест: '.$d['seats_remaining'] : ''),
        ])->values()->all();

        return ChatButtons::withAssistantOption($buttons);
    }
}
