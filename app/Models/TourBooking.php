<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ТЗ раздел 9 (Туристическая компания) -- "заявка на тур": a customer's
 * request/booking for a TourDeparture, for `pax_count` travelers (unlike
 * Enrollment, one booking can consume more than one seat). No prepayment
 * fields of its own -- billing reuses Order as-is (Order::tour_booking_id),
 * same pattern as every other module this session.
 */
#[Fillable(['tenant_id', 'company_id', 'tour_departure_id', 'customer_id', 'pax_count', 'status', 'notes', 'cancelled_reason', 'created_by_user_id'])]
class TourBooking extends Model
{
    use BelongsToTenant;

    public const STATUS_REQUESTED = 'requested';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_REQUESTED, self::STATUS_CONFIRMED, self::STATUS_COMPLETED, self::STATUS_CANCELLED];

    /** Both still hold real seats on the departure. */
    public const ACTIVE_STATUSES = [self::STATUS_REQUESTED, self::STATUS_CONFIRMED];

    protected function casts(): array
    {
        return ['pax_count' => 'integer'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function tourDeparture(): BelongsTo
    {
        return $this->belongsTo(TourDeparture::class);
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
        return $this->hasMany(TourBookingStatusHistory::class)->latest('id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
