<?php

namespace App\Http\Controllers\Api;

use App\Models\Product;

class ProductController extends TenantResourceController
{
    protected function model(): string
    {
        return Product::class;
    }

    protected function rules(string $action): array
    {
        $required = $action === 'store' ? 'required' : 'sometimes';

        return [
            'company_id' => [$required, 'integer', 'exists:companies,id'],
            'name' => [$required, 'string', 'max:180'],
            'category' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'sku' => ['nullable', 'string', 'max:80'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'stock_quantity' => ['nullable', 'integer', 'min:0'],
            'track_stock' => ['nullable', 'boolean'],
            'image_path' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
