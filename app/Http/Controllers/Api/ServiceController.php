<?php

namespace App\Http\Controllers\Api;

use App\Models\Service;
use Illuminate\Validation\Rule;

class ServiceController extends TenantResourceController
{
    protected function model(): string
    {
        return Service::class;
    }

    protected function rules(string $action): array
    {
        $required = $action === 'store' ? 'required' : 'sometimes';

        return [
            'company_id' => [$required, 'integer', 'exists:companies,id'],
            'name' => [$required, 'string', 'max:180'],
            'category' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'duration_minutes' => [$required, 'integer', 'min:5', 'max:720'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'prepayment_type' => ['nullable', Rule::in(Service::PREPAYMENT_TYPES)],
            'prepayment_value' => ['nullable', 'numeric', 'min:0'],
            'buffer_after_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'required_resource_id' => ['nullable', 'integer', 'exists:resources,id'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
