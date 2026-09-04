<?php

namespace App\Support\Hotel;

use App\Support\Chat\ChatButtons;
use Illuminate\Support\Carbon;

/** Same role as App\Support\Booking\BookingOfferButtons, for RoomReservationChatAssistant's own `offered_slots` shape (see RoomReservationChatContext::availableRooms()). See that class's own docblock for why this stays a separate file per module. */
class RoomReservationOfferButtons
{
    /**
     * @param array<int, array{resource_name:string, starts_at:string, ends_at:string, total_amount:float}> $rooms
     * @return array<int, array{id:string, title:string, description?:string}>
     */
    public static function build(array $rooms): array
    {
        $buttons = collect($rooms)->map(fn (array $room, int $i): array => [
            'id' => (string) ($i + 1),
            'title' => $room['resource_name'],
            'description' => Carbon::parse($room['starts_at'])->format('d.m').'–'.Carbon::parse($room['ends_at'])->format('d.m').', '.number_format($room['total_amount'], 0, ',', ' ').' смн',
        ])->values()->all();

        return ChatButtons::withAssistantOption($buttons);
    }
}
