<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** No tenant_id column, same as BookingGatewayPayment -- tenant scoping comes through the room_reservation_id relation. */
#[Fillable(['room_reservation_id', 'gateway', 'external_id', 'amount', 'currency', 'status', 'checkout_url', 'raw_response', 'webhook_payload', 'paid_at'])]
class RoomReservationGatewayPayment extends Model
{
    public const STATUSES = ['pending', 'succeeded', 'failed', 'expired', 'cancelled'];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'raw_response' => 'array',
            'webhook_payload' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    public function roomReservation(): BelongsTo
    {
        return $this->belongsTo(RoomReservation::class);
    }
}
