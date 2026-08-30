<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** No tenant_id column, same as BookingPaymentProof -- tenant scoping comes through the booking_id relation, not this table directly. */
#[Fillable(['booking_id', 'gateway', 'external_id', 'amount', 'currency', 'status', 'checkout_url', 'raw_response', 'webhook_payload', 'paid_at'])]
class BookingGatewayPayment extends Model
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

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
