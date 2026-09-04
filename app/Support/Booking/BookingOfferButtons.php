<?php

namespace App\Support\Booking;

use App\Support\Chat\ChatButtons;
use Illuminate\Support\Carbon;

/**
 * Turns AiChatBookingAssistant's own `offered_slots` shape (see
 * BookingChatContext::nextAvailableSlots()) into real tap buttons, so a
 * customer can pick a time without typing its number. Its own file, not
 * shared with the other 4 modules' own builders -- each module's offered-
 * item shape and label formatting is already its own thing (see each
 * assistant's own formatOffers()); this mirrors that same one-file-per-
 * module convention instead of forcing one generic formatter to know about
 * 5 different array shapes.
 */
class BookingOfferButtons
{
    /**
     * @param array<int, array{employee_name:string, starts_at:string}> $slots
     * @return array<int, array{id:string, title:string, description?:string}>
     */
    public static function build(array $slots): array
    {
        $buttons = collect($slots)->map(fn (array $slot, int $i): array => [
            'id' => (string) ($i + 1),
            'title' => Carbon::parse($slot['starts_at'])->format('d.m H:i'),
            'description' => $slot['employee_name'],
        ])->values()->all();

        return ChatButtons::withAssistantOption($buttons);
    }
}
