<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Doubles as both this module's StatusHistory (every other reservation-shaped
 * model this session has one) AND the customer-facing tracking timeline --
 * the two are the same concept for a shipment (a status change IS a tracking
 * event), so one table instead of two. `location` is the one field none of
 * this session's other *StatusHistory models have.
 */
#[Fillable(['shipment_id', 'status', 'location', 'description', 'changed_by_user_id'])]
class ShipmentTrackingEvent extends Model
{
    public const UPDATED_AT = null;

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
