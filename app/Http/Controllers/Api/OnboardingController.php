<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessType;
use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\IntegrationRequest;
use App\Models\Tenant;
use App\Support\Audit\AuditLogger;
use App\Support\Business\ModuleRegistry;
use App\Support\PlatformTelegramNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ТЗ раздел 3 (Мастер настройки) -- shipped scope: business type selection +
 * module defaults + "existing system?" question -> integration request.
 * Channels (шаг 2) already has its own flow on /integrations; the separate
 * "способ работы" multi-select (шаг 3) was folded into business-type
 * defaults rather than a 4th wizard screen, to keep this a real, usable
 * first slice instead of a half-built multi-step wizard.
 */
class OnboardingController extends Controller
{
    public function businessTypes(): JsonResponse
    {
        $types = BusinessType::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'key', 'name']);

        return response()->json([
            'business_types' => $types,
            'modules' => ModuleRegistry::labels(),
        ]);
    }

    public function complete(Request $request, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->tenant_id, 403);

        $data = $request->validate([
            'business_type_id' => ['nullable', 'integer', 'exists:business_types,id'],
            'business_type_other' => ['nullable', 'string', 'max:120'],
            'existing_system' => ['required', Rule::in(['none', 'crm', '1c', 'warehouse', 'booking', 'own', 'other'])],
        ]);

        if (! $data['business_type_id'] && ! $data['business_type_other']) {
            return response()->json(['message' => 'Выберите сферу деятельности или укажите свою.'], 422);
        }

        $company = Company::withoutGlobalScopes()->where('tenant_id', $user->tenant_id)->firstOrFail();
        $businessType = $data['business_type_id'] ? BusinessType::find($data['business_type_id']) : null;

        $company->forceFill([
            'business_type_id' => $businessType?->id,
            'business_type_other' => $businessType ? null : $data['business_type_other'],
        ])->save();

        foreach ((array) ($businessType?->default_modules ?? []) as $moduleKey) {
            if (! ModuleRegistry::isValid($moduleKey)) {
                continue;
            }

            CompanyModule::query()->updateOrCreate(
                ['tenant_id' => $user->tenant_id, 'company_id' => $company->id, 'module_key' => $moduleKey],
                ['enabled' => true]
            );
        }

        Tenant::query()->where('id', $user->tenant_id)->update(['onboarding_status' => 'completed']);

        $audit->record('onboarding.completed', $company, [
            'business_type' => $businessType?->name ?? $data['business_type_other'],
            'existing_system' => $data['existing_system'],
        ], [], $request);

        return response()->json([
            'ok' => true,
            'needs_integration_form' => $data['existing_system'] !== 'none',
            'existing_system' => $data['existing_system'],
        ]);
    }

    public function storeIntegrationRequest(Request $request, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->tenant_id, 403);

        $data = $request->validate([
            'platform_name' => ['required', 'string', 'max:180'],
            'platform_url' => ['nullable', 'string', 'max:255'],
            'plan_version' => ['nullable', 'string', 'max:180'],
            'tech_contact' => ['nullable', 'string', 'max:255'],
            'api_docs_url' => ['nullable', 'string', 'max:255'],
            'data_to_receive' => ['nullable', 'array'],
            'data_to_receive.*' => ['string', 'max:80'],
            'data_to_send' => ['nullable', 'array'],
            'data_to_send.*' => ['string', 'max:80'],
            'sync_frequency' => ['nullable', 'string', 'max:120'],
            'scenario_description' => ['nullable', 'string', 'max:4000'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $company = Company::withoutGlobalScopes()->where('tenant_id', $user->tenant_id)->firstOrFail();

        $requestRow = IntegrationRequest::query()->create([
            ...$data,
            'tenant_id' => $user->tenant_id,
            'company_id' => $company->id,
            'requested_by' => $user->id,
            'status' => 'new',
        ]);

        $audit->record('integration_request.created', $requestRow, ['platform_name' => $requestRow->platform_name], [], $request);

        PlatformTelegramNotifier::notify(
            'Новая заявка на интеграцию: '.$company->name.PHP_EOL.
            'Платформа: '.$requestRow->platform_name
        );

        return response()->json($requestRow, 201);
    }
}
