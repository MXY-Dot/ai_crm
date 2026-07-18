<?php

namespace App\Http\Controllers\Api;

use App\Models\Customer;

class CustomerController extends TenantResourceController
{
    protected function model(): string
    {
        return Customer::class;
    }

    protected function rules(string $action): array
    {
        $required = $action === 'store' ? 'required' : 'sometimes';

        return [
            'company_id' => [$required, 'integer', 'exists:companies,id'],
            'name' => [$required, 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:160'],
            'source' => ['nullable', 'string', 'max:80'],
            'tags' => ['nullable', 'array'],
            'meta' => ['nullable', 'array'],
        ];
    }
}