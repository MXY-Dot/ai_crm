<?php

namespace App\Support\Hotel;

use App\Models\Company;
use App\Models\Resource;
use App\Models\RoomReservation;
use Illuminate\Support\Carbon;

/**
 * ТЗ раздел 9 (Гостиница/хостел) — real free rooms for a date range, same
 * "never let AI/staff invent availability" discipline as
 * App\Support\Booking\AvailabilityCalculator, but simpler in shape: a
 * multi-night stay has no time-of-day slots to step through, just "is this
 * room free for the whole [check_in, check_out) range".
 */
class RoomAvailabilityCalculator
{
    /**
     * @return array<int, array{resource_id:int, resource_name:string, capacity:int|null, price_per_night:float}>
     */
    public function availableRooms(Company $company, Carbon $checkIn, Carbon $checkOut, int $guests, ?int $branchId): array
    {
        $rooms = Resource::query()
            ->where('company_id', $company->id)
            ->where('type', 'room')
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('capacity')->orWhere('capacity', '>=', $guests))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->get();

        if ($rooms->isEmpty()) {
            return [];
        }

        // Same UTC-binding care as AvailabilityCalculator/TableAvailabilityCalculator's
        // own busyIntervals() -- $checkIn/$checkOut arrive already normalized by the
        // caller, but defend here too rather than trust it silently.
        $utcStart = $checkIn->copy()->utc();
        $utcEnd = $checkOut->copy()->utc();

        $busyRoomIds = RoomReservation::query()
            ->whereIn('resource_id', $rooms->pluck('id'))
            ->whereIn('status', RoomReservation::ACTIVE_STATUSES)
            ->where('starts_at', '<', $utcEnd)
            ->where('ends_at', '>', $utcStart)
            ->pluck('resource_id')
            ->all();

        return $rooms->reject(fn (Resource $room) => in_array($room->id, $busyRoomIds, true))
            ->map(fn (Resource $room) => [
                'resource_id' => $room->id,
                'resource_name' => $room->name,
                'capacity' => $room->capacity,
                'price_per_night' => (float) ($room->price_per_night ?? 0),
            ])
            ->values()
            ->all();
    }
}
