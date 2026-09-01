<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['tenant_id', 'company_id', 'branch_id', 'customer_id', 'status', 'subtotal', 'delivery_fee', 'discount_amount', 'total', 'payment_status', 'notes', 'cancelled_reason', 'created_by_user_id', 'placed_via'])]
class Order extends Model
{
    use BelongsToTenant;

    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SHIPPED = 'shipped';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING, self::STATUS_CONFIRMED, self::STATUS_PROCESSING,
        self::STATUS_SHIPPED, self::STATUS_DELIVERED, self::STATUS_COMPLETED, self::STATUS_CANCELLED,
    ];

    /** Statuses that still represent a live, in-progress order (not yet a terminal outcome). */
    public const ACTIVE_STATUSES = [
        self::STATUS_PENDING, self::STATUS_CONFIRMED, self::STATUS_PROCESSING,
        self::STATUS_SHIPPED, self::STATUS_DELIVERED,
    ];

    public const PAYMENT_STATUSES = ['unpaid', 'paid', 'refunded'];

    protected function casts(): array
    {
        return [
            'subtotal' => 'float',
            'delivery_fee' => 'float',
            'discount_amount' => 'float',
            'total' => 'float',
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

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->latest('id');
    }

    public function delivery(): HasOne
    {
        return $this->hasOne(OrderDelivery::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(OrderReturn::class)->latest('id');
    }
}
