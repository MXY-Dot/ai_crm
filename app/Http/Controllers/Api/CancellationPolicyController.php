<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CancellationPolicy;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** ТЗ раздел 17 -- единая компанийная политика переноса/отмены (per-service override поддерживается в модели, но UI этого раунда управляет только политикой по умолчанию). */
class CancellationPolicyController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $company = Company::query()->where('tenant_id', $request->user()->tenant_id)->firstOrFail();

        $policy = CancellationPolicy::query()->where('company_id', $company->id)->whereNull('service_id')->first()
            ?? CancellationPolicy::query()->create([
                'tenant_id' => $request->user()->tenant_id,
                ...CancellationPolicy::defaultFor($company->id),
            ]);

        return response()->json($policy);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(in_array($user->role, [User::ROLE_OWNER, User::ROLE_MANAGER, User::ROLE_SUPER_ADMIN], true), 403);

        $data = $request->validate([
            'free_reschedule_hours' => ['required', 'integer', 'min:0', 'max:720'],
            'late_reschedule_hours' => ['required', 'integer', 'min:0', 'lte:free_reschedule_hours'],
            'max_client_reschedules' => ['required', 'integer', 'min:0', 'max:20'],
            'no_show_forfeits_prepayment' => ['required', 'boolean'],
        ]);

        $company = Company::query()->where('tenant_id', $user->tenant_id)->firstOrFail();

        $policy = CancellationPolicy::query()->updateOrCreate(
            ['company_id' => $company->id, 'service_id' => null],
            [...$data, 'tenant_id' => $user->tenant_id]
        );

        return response()->json($policy);
    }
}
