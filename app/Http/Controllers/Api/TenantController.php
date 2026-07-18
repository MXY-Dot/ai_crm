<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TenantController extends Controller
{
    public function index(): JsonResponse
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);

        return response()->json(Tenant::query()->latest()->paginate(20));
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);

        $tenant = Tenant::query()->create($request->validate($this->rules('store')));

        return response()->json($tenant, 201);
    }

    public function show(Tenant $tenant): JsonResponse
    {
        Gate::authorize('view', $tenant);

        return response()->json($tenant);
    }

    public function update(Request $request, Tenant $tenant): JsonResponse
    {
        Gate::authorize('update', $tenant);

        $tenant->update($request->validate($this->rules('update', $tenant)));

        return response()->json($tenant->refresh());
    }

    private function rules(string $action, ?Tenant $tenant = null): array
    {
        $required = $action === 'store' ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:160'],
            'slug' => ['nullable', 'string', 'max:160', Rule::unique('tenants')->ignore($tenant)],
            'status' => ['nullable', Rule::in(['trial', 'active', 'paused', 'blocked'])],
            'plan_id' => ['nullable', 'integer'],
            'trial_ends_at' => ['nullable', 'date'],
            'settings' => ['nullable', 'array'],
        ];
    }
}