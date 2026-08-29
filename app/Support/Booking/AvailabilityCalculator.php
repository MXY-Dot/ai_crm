<?php

namespace App\Support\Booking;

use App\Models\Booking;
use App\Models\Employee;
use App\Models\Service;
use Illuminate\Support\Carbon;

/**
 * ТЗ раздел 8 (модуль салона) — реальные свободные окна из календаря WERO,
 * а не выдуманные AI/сотрудником. Учитывает: рабочий график специалиста по
 * дню недели, его перерывы/отпуска/выходные, уже существующие брони
 * специалиста и брони требуемого ресурса (кабинет/кресло), с буфером после
 * услуги. Слот короче конца рабочего дня и не в прошлом.
 */
class AvailabilityCalculator
{
    private const SLOT_STEP_MINUTES = 15;

    /**
     * @return array<int, array{employee_id:int, employee_name:string, starts_at:string, ends_at:string}>
     */
    public function slotsForDay(Service $service, Carbon $date, ?int $employeeId, string $timezone): array
    {
        $employees = Employee::query()
            ->where('is_active', true)
            ->whereHas('services', fn ($q) => $q->where('services.id', $service->id))
            ->when($employeeId, fn ($q) => $q->where('id', $employeeId))
            ->with(['schedules', 'timeOff'])
            ->get();

        $slots = [];
        foreach ($employees as $employee) {
            array_push($slots, ...$this->slotsForEmployee($service, $employee, $date, $timezone));
        }

        usort($slots, fn (array $a, array $b) => $a['starts_at'] <=> $b['starts_at']);

        return $slots;
    }

    /** @return array<int, array{employee_id:int, employee_name:string, starts_at:string, ends_at:string}> */
    private function slotsForEmployee(Service $service, Employee $employee, Carbon $date, string $timezone): array
    {
        $weekday = $date->copy()->setTimezone($timezone)->dayOfWeekIso - 1; // 0=Mon..6=Sun
        $schedule = $employee->schedules->firstWhere('weekday', $weekday);

        if (! $schedule) {
            return [];
        }

        $dayStart = $date->copy()->setTimezone($timezone)->setTimeFromTimeString((string) $schedule->start_time);
        $dayEnd = $date->copy()->setTimezone($timezone)->setTimeFromTimeString((string) $schedule->end_time);

        $busy = $this->busyIntervals($employee->id, $service->required_resource_id, $dayStart, $dayEnd);
        foreach ($employee->timeOff as $off) {
            if ($off->ends_at->gt($dayStart) && $off->starts_at->lt($dayEnd)) {
                $busy[] = [$off->starts_at, $off->ends_at];
            }
        }

        $slots = [];
        $cursor = $dayStart->copy();
        $now = Carbon::now();

        while ($cursor->copy()->addMinutes($service->duration_minutes)->lte($dayEnd)) {
            $slotEnd = $cursor->copy()->addMinutes($service->duration_minutes);
            $blockedEnd = $slotEnd->copy()->addMinutes($service->buffer_after_minutes);

            if ($cursor->gt($now) && ! $this->overlapsAny($cursor, $blockedEnd, $busy)) {
                $slots[] = [
                    'employee_id' => $employee->id,
                    'employee_name' => $employee->name,
                    'starts_at' => $cursor->toIso8601String(),
                    'ends_at' => $slotEnd->toIso8601String(),
                ];
            }

            $cursor->addMinutes(self::SLOT_STEP_MINUTES);
        }

        return $slots;
    }

    /** @return array<int, array{0: Carbon, 1: Carbon}> */
    private function busyIntervals(int $employeeId, ?int $resourceId, Carbon $windowStart, Carbon $windowEnd): array
    {
        // Query bindings are formatted using whatever timezone the Carbon instance currently
        // carries, not normalized to UTC -- and the "starts_at"/"ends_at" columns are naive
        // (UTC) timestamps. $windowStart/$windowEnd arrive here in the company's local timezone
        // (needed by the caller for correct calendar-day boundaries), so bind UTC copies instead
        // of comparing company-local wall-clock strings against UTC-stored values.
        $utcStart = $windowStart->copy()->utc();
        $utcEnd = $windowEnd->copy()->utc();

        $bookings = Booking::query()
            ->with('service:id,buffer_after_minutes')
            ->where(function ($q) use ($employeeId, $resourceId) {
                $q->where('employee_id', $employeeId);
                if ($resourceId) {
                    $q->orWhere('resource_id', $resourceId);
                }
            })
            ->where(function ($q) {
                $q->whereIn('status', array_values(array_diff(Booking::ACTIVE_STATUSES, [Booking::STATUS_TEMP_HOLD])))
                    ->orWhere(fn ($q2) => $q2->where('status', Booking::STATUS_TEMP_HOLD)->where('hold_expires_at', '>', now()));
            })
            ->where('starts_at', '<', $utcEnd)
            ->where('ends_at', '>', $utcStart)
            ->get();

        return $bookings->map(fn (Booking $b) => [
            $b->starts_at,
            $b->ends_at->copy()->addMinutes($b->service?->buffer_after_minutes ?? 0),
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
