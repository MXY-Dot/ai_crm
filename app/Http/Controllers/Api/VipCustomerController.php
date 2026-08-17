<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Vip\VipScoreCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * ЭТАП 12.2 — the "VIP-клиенты" admin section. Customer rows already carry the
 * cached scoring output (see VipScoreCalculator), so this is a plain read + one
 * manual-recalculate action, not a heavy aggregation endpoint.
 */
class VipCustomerController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Customer::class);

        $customers = Customer::query()
            ->orderByDesc('vip_score')
            ->paginate(20);

        $customers->getCollection()->transform(function (Customer $customer): array {
            $lastAssignedLead = Lead::withoutGlobalScopes()
                ->where('customer_id', $customer->id)
                ->whereNotNull('assigned_user_id')
                ->latest('updated_at')
                ->with('assignedUser:id,name')
                ->first();

            return [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'vip_score' => $customer->vip_score,
                'vip_status' => $customer->vip_status,
                'vip_reason' => $customer->vip_reason,
                'segment' => $customer->segment,
                'purchases_count' => $customer->purchases_count,
                'total_revenue' => $customer->total_revenue,
                'average_check' => $customer->purchases_count > 0 ? round($customer->total_revenue / $customer->purchases_count, 2) : 0,
                'last_purchase_at' => $customer->last_purchase_at,
                'responsible_manager' => $lastAssignedLead?->assignedUser?->name,
                'vip_calculated_at' => $customer->vip_calculated_at,
            ];
        });

        return response()->json($customers);
    }

    public function recalculate(TenantContext $context, VipScoreCalculator $calculator): JsonResponse
    {
        Gate::authorize('viewAny', Customer::class);

        $tenant = Tenant::query()->findOrFail($context->id());
        $count = $calculator->recalculateAll($tenant);

        return response()->json(['recalculated' => $count]);
    }
}
