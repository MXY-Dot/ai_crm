<?php

namespace App\Http\Controllers\Api;

use App\Models\CrmTask;
use Illuminate\Validation\Rule;

class TaskController extends TenantResourceController
{
    protected function model(): string
    {
        return CrmTask::class;
    }

    protected function rules(string $action): array
    {
        $required = $action === 'store' ? 'required' : 'sometimes';

        return [
            'company_id' => [$required, 'integer', 'exists:companies,id'],
            'lead_id' => ['nullable', 'integer', 'exists:leads,id'],
            'assigned_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'title' => [$required, 'string', 'max:180'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(['open', 'in_progress', 'done', 'cancelled'])],
            'due_at' => ['nullable', 'date'],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
        ];
    }
}