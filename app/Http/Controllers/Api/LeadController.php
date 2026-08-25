<?php

namespace App\Http\Controllers\Api;

use App\Models\Lead;
use Illuminate\Validation\Rule;

class LeadController extends TenantResourceController
{
    protected function model(): string
    {
        return Lead::class;
    }

    protected function rules(string $action): array
    {
        $required = $action === 'store' ? 'required' : 'sometimes';

        return [
            'company_id' => [$required, 'integer', 'exists:companies,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'title' => [$required, 'string', 'max:180'],
            'status' => ['nullable', Rule::in(['new', 'qualified', 'won', 'lost'])],
            'source' => ['nullable', 'string', 'max:80'],
            'score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'assigned_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'ai_summary' => ['nullable', 'string'],
            'lost_reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}