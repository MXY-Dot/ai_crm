<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ТЗ раздел 9 (Туристическая компания) -- a scheduled "заезд": one specific
 * departure/return date pair for a Tour, with its own seat capacity and an
 * optional price override (seasonal pricing). Simpler than CourseGroup --
 * no weekly recurring schedule and no double-booking guard, since a
 * departure doesn't compete for a shared teacher/room the way a class does;
 * the only real constraint is total seats sold.
 */
#[Fillable(['tenant_id', 'company_id', 'branch_id', 'tour_id', 'departure_date', 'return_date', 'capacity', 'price', 'status', 'notes', 'created_by_user_id'])]
class TourDeparture extends Model
{
    use BelongsToTenant;

    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_DEPARTED = 'departed';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_CLOSED, self::STATUS_DEPARTED, self::STATUS_COMPLETED, self::STATUS_CANCELLED];

    /** Only 'open' departures accept new bookings -- see TourBookingService::book(). */
    public const BOOKABLE_STATUSES = [self::STATUS_OPEN];

    protected function casts(): array
    {
        return [
            'departure_date' => 'date',
            'return_date' => 'date',
            'capacity' => 'integer',
            'price' => 'float',
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

    public function tour(): BelongsTo
    {
        return $this->belongsTo(Tour::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(TourBooking::class)->latest('id');
    }
}
