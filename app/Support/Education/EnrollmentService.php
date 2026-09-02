<?php

namespace App\Support\Education;

use App\Models\CourseGroup;
use App\Models\Enrollment;
use App\Models\EnrollmentStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ТЗ раздел 9 (Учебный центр) — "запись учеников". Mirrors
 * TableReservationService's own simplicity (no prepayment lifecycle here
 * either -- tuition billing reuses Order as-is, see Enrollment's docblock):
 * enroll()/updateStatus()/cancel(), row-locked against the group's own
 * capacity.
 */
class EnrollmentService
{
    public function enroll(array $data, ?User $actor): Enrollment
    {
        return DB::transaction(function () use ($data, $actor) {
            $group = CourseGroup::query()->lockForUpdate()->findOrFail($data['course_group_id']);

            $alreadyEnrolled = Enrollment::query()
                ->where('course_group_id', $group->id)
                ->where('customer_id', $data['customer_id'])
                ->whereIn('status', Enrollment::ACTIVE_STATUSES)
                ->exists();

            if ($alreadyEnrolled) {
                throw new EducationConflictException('Этот ученик уже записан в эту группу.');
            }

            if ($group->capacity !== null) {
                $activeCount = Enrollment::query()
                    ->where('course_group_id', $group->id)
                    ->whereIn('status', Enrollment::ACTIVE_STATUSES)
                    ->count();

                if ($activeCount >= $group->capacity) {
                    throw new EducationConflictException('В этой группе уже нет свободных мест.');
                }
            }

            $tenantId = $actor?->tenant_id ?? $data['tenant_id'] ?? null;

            $enrollment = Enrollment::create([
                'tenant_id' => $tenantId,
                'company_id' => $data['company_id'],
                'course_group_id' => $group->id,
                'customer_id' => $data['customer_id'],
                'status' => Enrollment::STATUS_ENROLLED,
                'enrolled_at' => now(),
                'notes' => $data['notes'] ?? null,
                'created_by_user_id' => $actor?->id,
            ]);

            $this->logStatus($enrollment, null, Enrollment::STATUS_ENROLLED, $actor, 'Ученик записан в группу');

            return $enrollment;
        });
    }

    public function updateStatus(Enrollment $enrollment, string $newStatus, User $actor, ?string $comment = null): Enrollment
    {
        abort_unless($newStatus === Enrollment::STATUS_COMPLETED, 422, 'Для этого статуса используйте отдельное действие.');

        return DB::transaction(function () use ($enrollment, $newStatus, $actor, $comment) {
            if (! in_array($enrollment->status, Enrollment::ACTIVE_STATUSES, true)) {
                throw new EducationConflictException('Эта запись уже завершена или отменена.');
            }

            $oldStatus = $enrollment->status;
            $enrollment->update(['status' => $newStatus]);
            $this->logStatus($enrollment, $oldStatus, $newStatus, $actor, $comment);

            return $enrollment->refresh();
        });
    }

    // $actor nullable for the same reason as RepairOrderService::cancel()'s own
    // fix -- a customer-initiated cancel from AI-chat has no staff user behind
    // it (see EducationChatAssistant).
    public function cancel(Enrollment $enrollment, ?User $actor, string $reason): Enrollment
    {
        return DB::transaction(function () use ($enrollment, $actor, $reason) {
            if (! in_array($enrollment->status, Enrollment::ACTIVE_STATUSES, true)) {
                throw new EducationConflictException('Эта запись уже завершена или отменена.');
            }

            $oldStatus = $enrollment->status;
            $enrollment->update(['status' => Enrollment::STATUS_CANCELLED, 'cancelled_reason' => $reason]);
            $this->logStatus($enrollment, $oldStatus, Enrollment::STATUS_CANCELLED, $actor, $reason);

            return $enrollment->refresh();
        });
    }

    private function logStatus(Enrollment $enrollment, ?string $old, string $new, ?User $actor, ?string $comment): void
    {
        EnrollmentStatusHistory::create([
            'enrollment_id' => $enrollment->id,
            'old_status' => $old,
            'new_status' => $new,
            'changed_by_user_id' => $actor?->id,
            'comment' => $comment,
        ]);
    }
}
