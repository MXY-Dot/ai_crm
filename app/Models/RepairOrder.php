<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ТЗ раздел 9 (Автосервис/автомойка) -- "Автомобили клиентов и статусы
 * ремонта". Deliberately its own model rather than an Order or a Booking:
 * a repair job isn't a fixed-duration time slot (Booking) and isn't itself
 * a purchase of catalog items (Order) -- it's an open-ended job that MAY
 * later be billed. Billing reuses Order as-is (parts AND labor are both
 * just Products in the catalog -- a shop adds a "labor" line item the same
 * way it adds a part) via the new nullable Order::repair_order_id, same
 * linkage pattern as the restaurant module's предзаказ
 * (Order::table_reservation_id) -- zero new payment code needed.
 */
#[Fillable(['tenant_id', 'company_id', 'branch_id', 'customer_id', 'channel_id', 'vehicle_id', 'employee_id', 'status', 'problem_description', 'diagnosis_notes', 'estimated_total', 'promised_at', 'completed_at', 'notes', 'cancelled_reason', 'created_by_user_id'])]
class RepairOrder extends Model
{
    use BelongsToTenant;

    public const STATUS_RECEIVED = 'received';
    public const STATUS_DIAGNOSING = 'diagnosing';
    public const STATUS_AWAITING_APPROVAL = 'awaiting_approval';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_AWAITING_PARTS = 'awaiting_parts';
    public const STATUS_READY_FOR_PICKUP = 'ready_for_pickup';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_RECEIVED, self::STATUS_DIAGNOSING, self::STATUS_AWAITING_APPROVAL,
        self::STATUS_IN_PROGRESS, self::STATUS_AWAITING_PARTS, self::STATUS_READY_FOR_PICKUP,
        self::STATUS_COMPLETED, self::STATUS_CANCELLED,
    ];

    /** Statuses that still represent a live, in-progress job. */
    public const ACTIVE_STATUSES = [
        self::STATUS_RECEIVED, self::STATUS_DIAGNOSING, self::STATUS_AWAITING_APPROVAL,
        self::STATUS_IN_PROGRESS, self::STATUS_AWAITING_PARTS, self::STATUS_READY_FOR_PICKUP,
    ];

    protected function casts(): array
    {
        return [
            'estimated_total' => 'float',
            'promised_at' => 'datetime',
            'completed_at' => 'datetime',
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

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(RepairOrderStatusHistory::class)->latest('id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
