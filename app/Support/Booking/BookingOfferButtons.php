<?php

namespace App\Support\Booking;

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
 *
 * Returns the RAW numbered picks only -- no assistant option, no
 * pagination. The caller wraps this with ChatButtons::forOffer() (which
 * adds both) so the raw list can also be stored on its own and re-sliced
 * later if a "Далее ▶" tap asks for a different page.
 */
class BookingOfferButtons
{
    /**
     * @param array<int, array{employee_name:string, starts_at:string}> $slots
     * @return array<int, array{id:string, title:string, description?:string}>
     */
    public static function build(array $slots): array
    {
        return collect($slots)->map(fn (array $slot, int $i): array => [
            'id' => (string) ($i + 1),
            'title' => Carbon::parse($slot['starts_at'])->format('d.m H:i'),
            'description' => $slot['employee_name'],
        ])->values()->all();
    }
}
