<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'tenant_id', 'company_id', 'method', 'address', 'tracking_number', 'carrier', 'status', 'estimated_at', 'delivered_at', 'notes'])]
class OrderDelivery extends Model
{
    use BelongsToTenant;

    public const METHODS = ['courier', 'pickup', 'post'];

    public const STATUSES = ['pending', 'in_transit', 'delivered', 'failed'];

    protected function casts(): array
    {
        return [
            'estimated_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
