<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $data = $request->validate($this->rules('update', $tenant));

        // `settings` is a single JSON blob several other controllers read-modify-write
        // independently (IntegrationSettingsController, SuperAdminCompanyController::
        // updatePlan()) — this endpoint used to just `$tenant->update($data)` the whole
        // thing, which meant saving anything through this route (e.g. a company-profile
        // form that only ever held `{billing: {...}}` in its own local state) silently
        // wiped every other key nobody told it about — a real incident: a tenant's
        // Telegram bot token, Dify config, and auto-reply mode all vanished this way
        // with zero trace, since this route has no audit log call unlike the others.
        // Same lockForUpdate+merge pattern as those other controllers now, instead.
        if (array_key_exists('settings', $data)) {
            DB::transaction(function () use ($tenant, &$data): void {
                $locked = Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
                $data['settings'] = array_replace_recursive($locked->settings ?? [], $data['settings'] ?? []);
                $locked->update($data);
            });
        } else {
            $tenant->update($data);
        }

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