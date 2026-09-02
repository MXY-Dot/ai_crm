<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ТЗ раздел 9 (Учебный центр) -- "запись учеников": a student (Customer)
 * enrolled in a CourseGroup. Deliberately no prepayment fields of its own --
 * tuition billing reuses Order as-is (Order::enrollment_id), same pattern
 * as the auto service module's repair-order invoicing.
 */
#[Fillable(['tenant_id', 'company_id', 'course_group_id', 'customer_id', 'status', 'enrolled_at', 'notes', 'cancelled_reason', 'created_by_user_id'])]
class Enrollment extends Model
{
    use BelongsToTenant;

    public const STATUS_ENROLLED = 'enrolled';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_ENROLLED, self::STATUS_COMPLETED, self::STATUS_CANCELLED];

    /** Counts toward a group's capacity and blocks a duplicate enrollment. */
    public const ACTIVE_STATUSES = [self::STATUS_ENROLLED];

    protected function casts(): array
    {
        return ['enrolled_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function courseGroup(): BelongsTo
    {
        return $this->belongsTo(CourseGroup::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(EnrollmentStatusHistory::class)->latest('id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
