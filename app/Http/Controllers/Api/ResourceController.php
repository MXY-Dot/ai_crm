<?php

namespace App\Http\Controllers\Api;

use App\Models\Resource;
use Illuminate\Validation\Rule;

class ResourceController extends TenantResourceController
{
    protected function model(): string
    {
        return Resource::class;
    }

    protected function rules(string $action): array
    {
        $required = $action === 'store' ? 'required' : 'sometimes';

        return [
            'company_id' => [$required, 'integer', 'exists:companies,id'],
            'name' => [$required, 'string', 'max:120'],
            'type' => ['nullable', Rule::in(Resource::TYPES)],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
