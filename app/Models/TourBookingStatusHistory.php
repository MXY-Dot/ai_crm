<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tour_booking_id', 'old_status', 'new_status', 'changed_by_user_id', 'comment'])]
class TourBookingStatusHistory extends Model
{
    public const UPDATED_AT = null;

    public function tourBooking(): BelongsTo
    {
        return $this->belongsTo(TourBooking::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
