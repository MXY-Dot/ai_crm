<?php

namespace App\Support\Hotel;

use App\Support\Business\Currency;
use Illuminate\Support\Carbon;

/** Same role as App\Support\Booking\BookingOfferButtons, for RoomReservationChatAssistant's own `offered_slots` shape (see RoomReservationChatContext::availableRooms()). See that class's own docblock for why this stays a separate file per module, and for why this returns raw picks only (no assistant option, no pagination). */
class RoomReservationOfferButtons
{
    /**
     * @param array<int, array{resource_name:string, starts_at:string, ends_at:string, total_amount:float}> $rooms
     * @return array<int, array{id:string, title:string, description?:string}>
     */
    public static function build(array $rooms, ?string $currencyCode): array
    {
        return collect($rooms)->map(fn (array $room, int $i): array => [
            'id' => (string) ($i + 1),
            'title' => $room['resource_name'],
            'description' => Carbon::parse($room['starts_at'])->format('d.m').'–'.Carbon::parse($room['ends_at'])->format('d.m').', '.Currency::format($room['total_amount'], $currencyCode),
        ])->values()->all();
    }

    /**
     * Same button treatment for RoomReservationChatAssistant::offerReservationsForDisambiguation()'s
     * own "which of your existing reservations do you mean" prompt. No price
     * here (unlike build() above) -- an existing reservation's own
     * total_amount isn't part of this shape, only guest count/dates.
     *
     * @param array<int, array{resource_name:string, guests_count:int, starts_at:string, ends_at:string}> $reservations
     * @return array<int, array{id:string, title:string, description?:string}>
     */
    public static function forExistingReservations(array $reservations): array
    {
        return collect($reservations)->map(fn (array $r, int $i): array => [
            'id' => (string) ($i + 1),
            'title' => $r['resource_name'],
            'description' => Carbon::parse($r['starts_at'])->format('d.m').'–'.Carbon::parse($r['ends_at'])->format('d.m').', гостей: '.$r['guests_count'],
        ])->values()->all();
    }
}
