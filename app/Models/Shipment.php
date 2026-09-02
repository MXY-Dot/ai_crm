<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ТЗ раздел 9 (Логистическая компания) -- "Отправления и трек-номера".
 * The lightest module this session -- a shipment doesn't compete for a
 * shared resource/teacher/room/seat the way every other reservation-shaped
 * model does, so there's no conflict guard anywhere in this module, and no
 * customer-matching logic either (sender/recipient are plain fields --
 * `customer_id` is an optional link to an existing CRM customer, not a
 * requirement, since a one-time sender is the common case for a courier).
 * `tracking_number` is globally unique (not just per-tenant) so the public
 * tracking lookup (see TrackShipmentController) needs no tenant context at
 * all to resolve one, the same way a real courier's tracking number works
 * regardless of which internal branch handled it.
 */
#[Fillable(['tenant_id', 'company_id', 'branch_id', 'customer_id', 'tracking_number', 'sender_name', 'sender_phone', 'recipient_name', 'recipient_phone', 'origin_address', 'destination_address', 'service_type', 'weight_kg', 'price', 'status', 'estimated_delivery_at', 'delivered_at', 'notes', 'created_by_user_id'])]
class Shipment extends Model
{
    use BelongsToTenant;

    public const SERVICE_TYPES = ['standard', 'express'];

    public const STATUS_RECEIVED = 'received';
    public const STATUS_IN_TRANSIT = 'in_transit';
    public const STATUS_OUT_FOR_DELIVERY = 'out_for_delivery';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_RETURNED = 'returned';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_RECEIVED, self::STATUS_IN_TRANSIT, self::STATUS_OUT_FOR_DELIVERY,
        self::STATUS_DELIVERED, self::STATUS_RETURNED, self::STATUS_CANCELLED,
    ];

    /** Statuses that still represent a live, in-progress shipment. */
    public const ACTIVE_STATUSES = [self::STATUS_RECEIVED, self::STATUS_IN_TRANSIT, self::STATUS_OUT_FOR_DELIVERY];

    protected function casts(): array
    {
        return [
            'weight_kg' => 'float',
            'price' => 'float',
            'estimated_delivery_at' => 'datetime',
            'delivered_at' => 'datetime',
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function trackingEvents(): HasMany
    {
        return $this->hasMany(ShipmentTrackingEvent::class)->latest('id');
    }
}
