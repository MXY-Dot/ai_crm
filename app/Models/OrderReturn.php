<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'tenant_id', 'company_id', 'customer_id', 'reason', 'status', 'refund_amount', 'requested_by_user_id', 'reviewed_by_user_id', 'reviewed_at', 'comment'])]
class OrderReturn extends Model
{
    use BelongsToTenant;

    public const STATUS_REQUESTED = 'requested';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_REFUNDED = 'refunded';

    public const STATUSES = [
        self::STATUS_REQUESTED, self::STATUS_APPROVED, self::STATUS_REJECTED,
        self::STATUS_RECEIVED, self::STATUS_REFUNDED,
    ];

    protected function casts(): array
    {
        return [
            'refund_amount' => 'float',
            'reviewed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
