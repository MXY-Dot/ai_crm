<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ТЗ раздел 9 (Ресторан и кафе) -- deliberately simpler than Booking: no
 * service/employee/prepayment of its own. Money, when there is any, flows
 * through an Order (see Order::table_reservation_id) reusing the commerce
 * module's already-built payment system rather than a second one here.
 */
#[Fillable(['tenant_id', 'company_id', 'branch_id', 'customer_id', 'resource_id', 'party_size', 'starts_at', 'ends_at', 'status', 'notes', 'cancelled_reason', 'created_by_user_id', 'reschedule_count', 'reminders_sent'])]
class TableReservation extends Model
{
    use BelongsToTenant;

    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_SEATED = 'seated';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_NO_SHOW = 'no_show';

    public const STATUSES = [
        self::STATUS_PENDING, self::STATUS_CONFIRMED, self::STATUS_SEATED,
        self::STATUS_COMPLETED, self::STATUS_CANCELLED, self::STATUS_NO_SHOW,
    ];

    /** Statuses that still hold a real place on the table calendar (block double-booking). */
    public const ACTIVE_STATUSES = [self::STATUS_PENDING, self::STATUS_CONFIRMED, self::STATUS_SEATED];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'party_size' => 'integer',
            'reschedule_count' => 'integer',
            'reminders_sent' => 'array',
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

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(TableReservationStatusHistory::class)->latest('id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
