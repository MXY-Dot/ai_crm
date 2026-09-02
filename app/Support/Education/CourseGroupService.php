<?php

namespace App\Support\Education;

use App\Models\CourseGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ТЗ раздел 9 (Учебный центр) — create/update for course groups, mirroring
 * this session's established write-service discipline (DB::transaction()
 * per write, row-locked, a real conflict guard) but shaped around a
 * recurring WEEKLY pattern rather than a single dated slot: a teacher or
 * classroom can't be double-booked at the same weekday+time by two
 * different active groups. No StatusHistory table here (unlike every
 * reservation-shaped model this session) -- a group's status changes
 * rarely and AuditLogger already covers it at the controller layer; a
 * dedicated log would track almost nothing.
 */
class CourseGroupService
{
    /**
     * @param array{tenant_id?:int, company_id:int, branch_id?:?int, course_id:int, employee_id?:?int, resource_id?:?int, name:string, capacity?:?int, schedule:array, starts_on?:?string, ends_on?:?string, notes?:?string} $data
     */
    public function create(array $data, ?User $actor): CourseGroup
    {
        return DB::transaction(function () use ($data, $actor) {
            $this->assertNoConflict($data['employee_id'] ?? null, $data['resource_id'] ?? null, $data['schedule'], null);

            $tenantId = $actor?->tenant_id ?? $data['tenant_id'] ?? null;

            return CourseGroup::create([
                'tenant_id' => $tenantId,
                'company_id' => $data['company_id'],
                'branch_id' => $data['branch_id'] ?? null,
                'course_id' => $data['course_id'],
                'employee_id' => $data['employee_id'] ?? null,
                'resource_id' => $data['resource_id'] ?? null,
                'name' => $data['name'],
                'capacity' => $data['capacity'] ?? null,
                'schedule' => $data['schedule'],
                'starts_on' => $data['starts_on'] ?? null,
                'ends_on' => $data['ends_on'] ?? null,
                'status' => CourseGroup::STATUS_RECRUITING,
                'notes' => $data['notes'] ?? null,
                'created_by_user_id' => $actor?->id,
            ]);
        });
    }

    /** General edit, including status -- a group's fields (teacher/room/schedule/capacity/status) are all administrative settings changed together, unlike a reservation's workflow-critical status transitions. */
    public function update(CourseGroup $group, array $data, User $actor): CourseGroup
    {
        return DB::transaction(function () use ($group, $data, $actor) {
            $this->lockRow($group->id);

            $employeeId = array_key_exists('employee_id', $data) ? $data['employee_id'] : $group->employee_id;
            $resourceId = array_key_exists('resource_id', $data) ? $data['resource_id'] : $group->resource_id;
            $schedule = $data['schedule'] ?? $group->schedule;

            if (in_array($data['status'] ?? $group->status, CourseGroup::ACTIVE_STATUSES, true)) {
                $this->assertNoConflict($employeeId, $resourceId, $schedule, $group->id);
            }

            $group->update(array_intersect_key($data, array_flip([
                'name', 'employee_id', 'resource_id', 'capacity', 'schedule', 'starts_on', 'ends_on', 'status', 'notes',
            ])));

            return $group->refresh();
        });
    }

    /** @param array<int, array{weekday:int, start_time:string, end_time:string}> $schedule */
    private function assertNoConflict(?int $employeeId, ?int $resourceId, array $schedule, ?int $excludeGroupId): void
    {
        if (! $employeeId && ! $resourceId) {
            return;
        }

        $candidates = CourseGroup::query()
            ->where(function ($q) use ($employeeId, $resourceId) {
                if ($employeeId) {
                    $q->where('employee_id', $employeeId);
                }
                if ($resourceId) {
                    $q->orWhere('resource_id', $resourceId);
                }
            })
            ->whereIn('status', CourseGroup::ACTIVE_STATUSES)
            ->when($excludeGroupId, fn ($q) => $q->where('id', '!=', $excludeGroupId))
            ->lockForUpdate()
            ->get();

        foreach ($candidates as $candidate) {
            $sharesEmployee = $employeeId && $candidate->employee_id === $employeeId;
            $sharesResource = $resourceId && $candidate->resource_id === $resourceId;

            if (! $sharesEmployee && ! $sharesResource) {
                continue;
            }

            foreach ($schedule as $slot) {
                foreach ($candidate->schedule ?? [] as $existingSlot) {
                    if ((int) $slot['weekday'] !== (int) $existingSlot['weekday']) {
                        continue;
                    }

                    // 'HH:MM' strings compare lexicographically the same as chronologically.
                    if ($slot['start_time'] < $existingSlot['end_time'] && $existingSlot['start_time'] < $slot['end_time']) {
                        throw new EducationConflictException("Это время уже занято группой «{$candidate->name}».");
                    }
                }
            }
        }
    }

    private function lockRow(int $id): void
    {
        DB::table('course_groups')->where('id', $id)->lockForUpdate()->first();
    }
}
