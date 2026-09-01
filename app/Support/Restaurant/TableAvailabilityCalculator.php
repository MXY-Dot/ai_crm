<?php

namespace App\Support\Restaurant;

use App\Models\Company;
use App\Models\Resource;
use App\Models\TableReservation;
use Illuminate\Support\Carbon;

/**
 * ТЗ раздел 9 (Ресторан и кафе) — real free tables from the calendar, same
 * "never let AI/staff invent a slot" discipline as
 * App\Support\Booking\AvailabilityCalculator, deliberately simpler: no
 * per-employee schedule to honor, just the company's own opening hours
 * (Company::working_hours) plus already-reserved tables.
 */
class TableAvailabilityCalculator
{
    private const SLOT_STEP_MINUTES = 15;

    public const DEFAULT_DURATION_MINUTES = 90;

    public const DEFAULT_TURNOVER_MINUTES = 15;

    /**
     * @return array<int, array{resource_id:int, resource_name:string, capacity:int, starts_at:string, ends_at:string}>
     */
    public function slotsForDay(Company $company, Carbon $date, int $partySize, ?int $branchId, string $timezone): array
    {
        $tables = Resource::query()
            ->where('company_id', $company->id)
            ->where('type', 'table')
            ->where('is_active', true)
            ->where('capacity', '>=', $partySize)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->get();

        [$dayStart, $dayEnd] = $this->dayBounds($company, $date, $timezone);
        $duration = (int) (data_get($company->brand_settings, 'restaurant.reservation_duration_minutes') ?: self::DEFAULT_DURATION_MINUTES);
        $turnover = (int) (data_get($company->brand_settings, 'restaurant.turnover_minutes') ?: self::DEFAULT_TURNOVER_MINUTES);

        $slots = [];
        foreach ($tables as $table) {
            array_push($slots, ...$this->slotsForTable($table, $dayStart, $dayEnd, $duration, $turnover));
        }

        usort($slots, fn (array $a, array $b) => $a['starts_at'] <=> $b['starts_at']);

        return $slots;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function dayBounds(Company $company, Carbon $date, string $timezone): array
    {
        $start = $company->working_hours['start'] ?? '09:00';
        $end = $company->working_hours['end'] ?? '22:00';

        return [
            $date->copy()->setTimezone($timezone)->setTimeFromTimeString($start),
            $date->copy()->setTimezone($timezone)->setTimeFromTimeString($end),
        ];
    }

    /** @return array<int, array{resource_id:int, resource_name:string, capacity:int, starts_at:string, ends_at:string}> */
    private function slotsForTable(Resource $table, Carbon $dayStart, Carbon $dayEnd, int $duration, int $turnover): array
    {
        $busy = $this->busyIntervals($table->id, $dayStart, $dayEnd, $turnover);
        $slots = [];
        $cursor = $dayStart->copy();
        $now = Carbon::now();

        while ($cursor->copy()->addMinutes($duration)->lte($dayEnd)) {
            $slotEnd = $cursor->copy()->addMinutes($duration);
            $blockedEnd = $slotEnd->copy()->addMinutes($turnover);

            if ($cursor->gt($now) && ! $this->overlapsAny($cursor, $blockedEnd, $busy)) {
                $slots[] = [
                    'resource_id' => $table->id,
                    'resource_name' => $table->name,
                    'capacity' => (int) $table->capacity,
                    'starts_at' => $cursor->toIso8601String(),
                    'ends_at' => $slotEnd->toIso8601String(),
                ];
            }

            $cursor->addMinutes(self::SLOT_STEP_MINUTES);
        }

        return $slots;
    }

    /** @return array<int, array{0: Carbon, 1: Carbon}> */
    private function busyIntervals(int $resourceId, Carbon $windowStart, Carbon $windowEnd, int $turnover): array
    {
        // Same UTC-binding care as AvailabilityCalculator::busyIntervals() -- windowStart/
        // windowEnd arrive here in the company's local timezone, but starts_at/ends_at are
        // naive (UTC) columns; comparing local wall-clock strings against them silently
        // drops real conflicts (the exact bug fixed in the booking module's own calculator).
        $utcStart = $windowStart->copy()->utc();
        $utcEnd = $windowEnd->copy()->utc();

        $reservations = TableReservation::query()
            ->where('resource_id', $resourceId)
            ->whereIn('status', TableReservation::ACTIVE_STATUSES)
            ->where('starts_at', '<', $utcEnd)
            ->where('ends_at', '>', $utcStart)
            ->get();

        return $reservations->map(fn (TableReservation $r) => [
            $r->starts_at,
            $r->ends_at->copy()->addMinutes($turnover),
        ])->all();
    }

    /** @param array<int, array{0: Carbon, 1: Carbon}> $intervals */
    private function overlapsAny(Carbon $start, Carbon $end, array $intervals): bool
    {
        foreach ($intervals as [$busyStart, $busyEnd]) {
            if ($start->lt($busyEnd) && $end->gt($busyStart)) {
                return true;
            }
        }

        return false;
    }
}
