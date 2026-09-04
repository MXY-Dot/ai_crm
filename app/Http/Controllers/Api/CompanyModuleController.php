<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\Tenant;
use App\Support\Audit\AuditLogger;
use App\Support\Business\ModuleRegistry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** ТЗ раздел 8 — "Компания может позднее открыть раздел «Модули» и включить дополнительные функции." */
class CompanyModuleController extends Controller
{
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($context->id());
        $company = Company::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $enabled = CompanyModule::query()
            ->where('company_id', $company->id)
            ->pluck('enabled', 'module_key');

        $labels = ModuleRegistry::labels();
        $allowedKeys = $this->allowedModuleKeys($company);
        if ($allowedKeys !== null) {
            $labels = array_intersect_key($labels, array_flip($allowedKeys));
        }

        $modules = collect($labels)->map(fn (string $label, string $key): array => [
            'key' => $key,
            'label' => $label,
            'enabled' => (bool) ($enabled[$key] ?? false),
        ])->values();

        return response()->json(['modules' => $modules, 'business_type' => $company->businessType]);
    }

    public function toggle(Request $request, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'module_key' => ['required', 'string'],
            'enabled' => ['required', 'boolean'],
        ]);

        abort_unless(ModuleRegistry::isValid($data['module_key']), 422, 'Неизвестный модуль.');

        $tenant = Tenant::query()->findOrFail($context->id());
        $company = Company::query()->where('tenant_id', $tenant->id)->firstOrFail();

        $allowedKeys = $this->allowedModuleKeys($company);
        abort_if($allowedKeys !== null && ! in_array($data['module_key'], $allowedKeys, true), 422, 'Модуль недоступен для вашей сферы бизнеса.');

        $module = CompanyModule::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'company_id' => $company->id, 'module_key' => $data['module_key']],
            ['enabled' => $data['enabled']]
        );

        $audit->record('company_module.toggled', $module, ['enabled' => $data['enabled']], [], $request);

        return response()->json($module);
    }

    /**
     * BusinessType.default_modules is the super-admin-configured set of
     * modules that make sense for that sphere (see
     * SuperAdminBusinessTypeDetailPage) -- not just a one-time onboarding
     * default. It doubles as the allowlist here: a company only ever sees
     * (and can only toggle) modules within its own sphere's set. Returns
     * null when there's no sphere to constrain by (custom "other" sphere,
     * or an empty default_modules list), meaning every module stays
     * available -- there's nothing to filter against.
     */
    private function allowedModuleKeys(Company $company): ?array
    {
        $keys = $company->businessType?->default_modules;

        return (is_array($keys) && $keys !== []) ? $keys : null;
    }
}
