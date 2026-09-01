<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['table_reservation_id', 'old_status', 'new_status', 'changed_by_user_id', 'comment'])]
class TableReservationStatusHistory extends Model
{
    public const UPDATED_AT = null;

    public function tableReservation(): BelongsTo
    {
        return $this->belongsTo(TableReservation::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
