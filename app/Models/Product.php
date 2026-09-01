<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'company_id', 'name', 'category', 'description', 'sku', 'price', 'stock_quantity', 'track_stock', 'image_path', 'is_active'])]
class Product extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'price' => 'float',
            'stock_quantity' => 'integer',
            'track_stock' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
