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

    /**
     * Same button treatment for TableReservationChatAssistant::offerReservationsForDisambiguation()'s
     * own "which of your existing reservations do you mean" prompt.
     *
     * @param array<int, array{resource_name:string, party_size:int, starts_at:string}> $reservations
     * @return array<int, array{id:string, title:string, description?:string}>
     */
    public static function forExistingReservations(array $reservations): array
    {
        return collect($reservations)->map(fn (array $r, int $i): array => [
            'id' => (string) ($i + 1),
            'title' => Carbon::parse($r['starts_at'])->format('d.m H:i'),
            'description' => $r['resource_name'].', гостей: '.$r['party_size'],
        ])->values()->all();
    }
}
