<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * ЭТАП 12.2 — "company sets own criteria" (after N purchases, OR after X
 * revenue, OR Score above N, OR a combination). Stored in
 * tenants.settings.vip.* — same lockForUpdate read-modify-write pattern as
 * EmergencySettingsController::update()/IntegrationSettingsController::update().
 *
 * VIP moved to super_admin-only — Tenant's own policy stays untouched (it's
 * shared with unrelated tenant settings), so the role check lives here instead.
 */
class VipSettingsController extends Controller
{
    public function show(TenantContext $context): JsonResponse
    {
        abort_unless(auth()->user()?->role === User::ROLE_SUPER_ADMIN, 403);
        $tenant = $this->tenant($context);
        Gate::authorize('view', $tenant);

        return response()->json($this->payload($tenant));
    }

    public function update(Request $request, TenantContext $context): JsonResponse
    {
        abort_unless(auth()->user()?->role === User::ROLE_SUPER_ADMIN, 403);
        $tenant = $this->tenant($context);
        Gate::authorize('update', $tenant);

        $data = $request->validate([
            'min_purchases' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'min_revenue' => ['nullable', 'numeric', 'min:0'],
            'min_score' => ['nullable', 'integer', 'min:1', 'max:100'],
            'revenue_scale' => ['nullable', 'numeric', 'min:1'],
        ]);

        DB::transaction(function () use ($tenant, $data): void {
            $locked = Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
            $settings = $locked->settings ?? [];

            foreach (['min_purchases', 'min_revenue', 'min_score', 'revenue_scale'] as $key) {
                if (array_key_exists($key, $data)) {
                    Arr::set($settings, 'vip.'.$key, $data[$key]);
                }
            }

            $locked->forceFill(['settings' => $settings])->save();
        });

        return response()->json($this->payload($tenant->fresh()));
    }

    private function payload(Tenant $tenant): array
    {
        $settings = $tenant->settings ?? [];

        return [
            'min_purchases' => Arr::get($settings, 'vip.min_purchases', 5),
            'min_revenue' => Arr::get($settings, 'vip.min_revenue'),
            'min_score' => Arr::get($settings, 'vip.min_score', 80),
            'revenue_scale' => Arr::get($settings, 'vip.revenue_scale', 10000),
        ];
    }

    private function tenant(TenantContext $context): Tenant
    {
        return Tenant::query()->findOrFail($context->id());
    }
}
