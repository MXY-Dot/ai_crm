<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'company_id', 'customer_id', 'service_id', 'employee_id', 'resource_id', 'starts_at', 'ends_at', 'status', 'price', 'prepayment_amount', 'prepayment_status', 'hold_expires_at', 'notes', 'cancelled_reason', 'created_by_user_id', 'reschedule_count', 'reminders_sent'])]
class Booking extends Model
{
    use BelongsToTenant;

    // ТЗ раздел 11 — статусы записи.
    public const STATUS_TEMP_HOLD = 'temp_hold';
    public const STATUS_AWAITING_PAYMENT = 'awaiting_payment';
    public const STATUS_PAYMENT_REVIEW = 'payment_review';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_CLIENT_ARRIVED = 'client_arrived';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_RESCHEDULED = 'rescheduled';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_NO_SHOW = 'no_show';

    public const STATUSES = [
        self::STATUS_TEMP_HOLD, self::STATUS_AWAITING_PAYMENT, self::STATUS_PAYMENT_REVIEW,
        self::STATUS_CONFIRMED, self::STATUS_CLIENT_ARRIVED, self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED, self::STATUS_RESCHEDULED, self::STATUS_CANCELLED, self::STATUS_NO_SHOW,
    ];

    /** Statuses that still hold a real place on the calendar (block double-booking). */
    public const ACTIVE_STATUSES = [
        self::STATUS_TEMP_HOLD, self::STATUS_AWAITING_PAYMENT, self::STATUS_PAYMENT_REVIEW,
        self::STATUS_CONFIRMED, self::STATUS_CLIENT_ARRIVED, self::STATUS_IN_PROGRESS,
    ];

    // ТЗ раздел 19 -- 'rejected' means a submitted payment PROOF was rejected (customer still
    // owes payment); the two refund-specific statuses below are deliberately distinct so a
    // rejected refund request is never confused with a rejected payment screenshot.
    public const PREPAYMENT_STATUSES = ['none', 'pending', 'review', 'confirmed', 'refund_pending', 'refund_processing', 'refunded', 'refund_rejected', 'rejected', 'kept'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'hold_expires_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'reminders_sent' => 'array',
            'price' => 'float',
            'prepayment_amount' => 'float',
            'reschedule_count' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
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

    public function statusHistory(): HasMany
    {
        return $this->hasMany(BookingStatusHistory::class)->latest('id');
    }

    public function paymentProofs(): HasMany
    {
        return $this->hasMany(BookingPaymentProof::class)->latest('id');
    }
}
