<?php

namespace App\Http\Controllers\Api;

use App\Models\Tour;

class TourController extends TenantResourceController
{
    protected function model(): string
    {
        return Tour::class;
    }

    protected function rules(string $action): array
    {
        $required = $action === 'store' ? 'required' : 'sometimes';

        return [
            'company_id' => [$required, 'integer', 'exists:companies,id'],
            'name' => [$required, 'string', 'max:180'],
            'destination' => ['nullable', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['nullable', 'string', 'max:120'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'duration_days' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
