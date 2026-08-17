<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tenant_id', 'company_id', 'name', 'phone', 'email', 'source', 'tags', 'meta', 'vip_score', 'vip_status', 'vip_reason', 'segment', 'purchases_count', 'total_revenue', 'last_purchase_at', 'vip_calculated_at', 'is_business'])]
class Customer extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'meta' => 'array',
            'vip_score' => 'integer',
            'purchases_count' => 'integer',
            'total_revenue' => 'float',
            'last_purchase_at' => 'datetime',
            'vip_calculated_at' => 'datetime',
            'is_business' => 'boolean',
        ];
    }
}
