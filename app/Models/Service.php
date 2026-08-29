<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['tenant_id', 'company_id', 'name', 'category', 'description', 'duration_minutes', 'price', 'prepayment_type', 'prepayment_value', 'buffer_after_minutes', 'required_resource_id', 'is_active'])]
class Service extends Model
{
    use BelongsToTenant;

    public const PREPAYMENT_TYPES = ['none', 'fixed', 'percent', 'full'];

    protected function casts(): array
    {
        return [
            'price' => 'float',
            'prepayment_value' => 'float',
            'duration_minutes' => 'integer',
            'buffer_after_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function requiredResource(): BelongsTo
    {
        return $this->belongsTo(Resource::class, 'required_resource_id');
    }

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class)->withPivot('custom_price')->withTimestamps();
    }

    /** Prepayment amount for this service's configured price, in company currency. */
    public function prepaymentAmountFor(float $price): float
    {
        return match ($this->prepayment_type) {
            'fixed' => (float) $this->prepayment_value,
            'percent' => round($price * ((float) $this->prepayment_value / 100), 2),
            'full' => $price,
            default => 0.0,
        };
    }
}
