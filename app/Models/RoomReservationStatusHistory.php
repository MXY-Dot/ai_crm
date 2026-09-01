<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['room_reservation_id', 'old_status', 'new_status', 'changed_by_user_id', 'comment'])]
class RoomReservationStatusHistory extends Model
{
    public const UPDATED_AT = null;

    public function roomReservation(): BelongsTo
    {
        return $this->belongsTo(RoomReservation::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
