<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ТЗ раздел 9 (Гостиница/хостел) — deliberately mirrors Booking's own
 * shape (it has real money attached, unlike TableReservation, whose
 * money flows through an Order instead): starts_at/ends_at here are
 * check-in/check-out, price_per_night is a snapshot off Resource at
 * creation time (rate changes later must not silently reprice an
 * existing stay), total_amount = price_per_night * nights.
 */
#[Fillable(['tenant_id', 'company_id', 'branch_id', 'customer_id', 'channel_id', 'resource_id', 'guests_count', 'starts_at', 'ends_at', 'status', 'price_per_night', 'total_amount', 'prepayment_amount', 'prepayment_status', 'hold_expires_at', 'notes', 'cancelled_reason', 'created_by_user_id', 'reschedule_count', 'reminders_sent'])]
class RoomReservation extends Model
{
    use BelongsToTenant;

    public const STATUS_TEMP_HOLD = 'temp_hold';
    public const STATUS_AWAITING_PAYMENT = 'awaiting_payment';
    public const STATUS_PAYMENT_REVIEW = 'payment_review';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_CHECKED_IN = 'checked_in';
    public const STATUS_CHECKED_OUT = 'checked_out';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_NO_SHOW = 'no_show';

    public const STATUSES = [
        self::STATUS_TEMP_HOLD, self::STATUS_AWAITING_PAYMENT, self::STATUS_PAYMENT_REVIEW,
        self::STATUS_CONFIRMED, self::STATUS_CHECKED_IN, self::STATUS_CHECKED_OUT,
        self::STATUS_CANCELLED, self::STATUS_NO_SHOW,
    ];

    /** Statuses that still hold a real place on the room calendar (block double-booking). */
    public const ACTIVE_STATUSES = [
        self::STATUS_TEMP_HOLD, self::STATUS_AWAITING_PAYMENT, self::STATUS_PAYMENT_REVIEW,
        self::STATUS_CONFIRMED, self::STATUS_CHECKED_IN,
    ];

    // Same set BookingPaymentProof/Booking use — see Booking's own docblock for why
    // 'rejected' (a submitted screenshot was denied) and the refund_* statuses
    // (a confirmed payment being given back) are kept distinct.
    public const PREPAYMENT_STATUSES = ['none', 'pending', 'review', 'confirmed', 'refund_pending', 'refund_processing', 'refunded', 'refund_rejected', 'rejected', 'kept'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'hold_expires_at' => 'datetime',
            'reminders_sent' => 'array',
            'guests_count' => 'integer',
            'price_per_night' => 'float',
            'total_amount' => 'float',
            'prepayment_amount' => 'float',
            'reschedule_count' => 'integer',
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
        return $this->hasMany(RoomReservationStatusHistory::class)->latest('id');
    }

    public function paymentProofs(): HasMany
    {
        return $this->hasMany(RoomReservationPaymentProof::class)->latest('id');
    }

    /** Whole nights between check-in and check-out, never less than 1 (a same-day booking still books one night). */
    public function nights(): int
    {
        return max(1, (int) $this->starts_at->copy()->startOfDay()->diffInDays($this->ends_at->copy()->startOfDay()));
    }
}
