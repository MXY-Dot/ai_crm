<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ТЗ раздел 9 (Учебный центр) -- a cohort of students taking a Course
 * together on a fixed weekly schedule. `schedule` is a small JSON array of
 * {weekday, start_time, end_time} entries (same field names as
 * EmployeeSchedule, just not a child table -- a group's weekly pattern is
 * set once at creation and rarely edited slot-by-slot the way an
 * individual employee's working week is). Deliberately no generated,
 * individually-dated Lesson rows or attendance tracking this round -- see
 * this module's plan for the disclosed scope cut.
 */
#[Fillable(['tenant_id', 'company_id', 'branch_id', 'course_id', 'employee_id', 'resource_id', 'name', 'capacity', 'schedule', 'starts_on', 'ends_on', 'status', 'notes', 'created_by_user_id'])]
class CourseGroup extends Model
{
    use BelongsToTenant;

    public const STATUS_RECRUITING = 'recruiting';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_RECRUITING, self::STATUS_ACTIVE, self::STATUS_COMPLETED, self::STATUS_CANCELLED];

    /** Statuses that still hold a real weekly slot (block a teacher/room double-booking). */
    public const ACTIVE_STATUSES = [self::STATUS_RECRUITING, self::STATUS_ACTIVE];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'schedule' => 'array',
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class)->latest('id');
    }
}
