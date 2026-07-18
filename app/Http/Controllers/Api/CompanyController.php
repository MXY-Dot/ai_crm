<?php

namespace App\Http\Controllers\Api;

use App\Models\Company;

class CompanyController extends TenantResourceController
{
    protected function model(): string
    {
        return Company::class;
    }

    protected function rules(string $action): array
    {
        $required = $action === 'store' ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:160'],
            'industry' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:160'],
            'website' => ['nullable', 'url', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'timezone' => ['nullable', 'string', 'max:80'],
            'working_hours' => ['nullable', 'array'],
            'brand_settings' => ['nullable', 'array'],
        ];
    }
}