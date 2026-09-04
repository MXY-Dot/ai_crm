<?php

namespace App\Support\Education;

/** Same role as App\Support\Booking\BookingOfferButtons, for EducationChatAssistant's own `offered_groups` shape (see EducationChatContext::openGroupsForCourse()). See that class's own docblock for why this stays a separate file per module, and for why this returns raw picks only (no assistant option, no pagination). */
class EducationOfferButtons
{
    /**
     * @param array<int, array{employee_name:string, schedule_text:string}> $groups
     * @return array<int, array{id:string, title:string, description?:string}>
     */
    public static function build(array $groups): array
    {
        return collect($groups)->map(fn (array $g, int $i): array => [
            'id' => (string) ($i + 1),
            'title' => mb_strimwidth($g['schedule_text'], 0, 24, '…'),
            'description' => $g['employee_name'],
        ])->values()->all();
    }
}
