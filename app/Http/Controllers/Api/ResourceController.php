<?php

namespace App\Http\Controllers\Api;

use App\Models\Resource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
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
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'name' => [$required, 'string', 'max:120'],
            'type' => ['nullable', Rule::in(Resource::TYPES)],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Restaurant settings (Столики tab) needs just the `type=table` rows -- optional
     * filter, unused calls keep today's unfiltered behavior. Signature must match
     * TenantResourceController::index(): JsonResponse exactly (no params) -- PHP
     * enforces override compatibility even though Laravel would happily inject a
     * Request parameter here; request() reads the current request without one.
     */
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Resource::class);

        $data = request()->validate(['type' => ['nullable', Rule::in(Resource::TYPES)]]);

        return response()->json(
            Resource::query()
                ->when($data['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
                ->latest()
                ->paginate(20)
        );
    }
}
