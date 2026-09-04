<?php

namespace App\Support\Restaurant;

use Illuminate\Support\Carbon;

/** Same role as App\Support\Booking\BookingOfferButtons, for TableReservationChatAssistant's own `offered_slots` shape (see TableReservationChatContext::nextAvailableSlots()). See that class's own docblock for why this stays a separate file per module, and for why this returns raw picks only (no assistant option, no pagination). */
class TableReservationOfferButtons
{
    /**
     * @param array<int, array{resource_name:string, starts_at:string}> $slots
     * @return array<int, array{id:string, title:string, description?:string}>
     */
    public static function build(array $slots): array
    {
        return collect($slots)->map(fn (array $slot, int $i): array => [
            'id' => (string) ($i + 1),
            'title' => Carbon::parse($slot['starts_at'])->format('d.m H:i'),
            'description' => $slot['resource_name'],
        ])->values()->all();
    }
}
