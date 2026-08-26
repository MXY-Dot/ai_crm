<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyModule;
use App\Support\Audit\AuditLogger;
use App\Support\Business\ModuleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** ТЗ раздел 8 — "Компания может позднее открыть раздел «Модули» и включить дополнительные функции." */
class CompanyModuleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $company = Company::query()->where('tenant_id', $request->user()->tenant_id)->firstOrFail();
        $enabled = CompanyModule::query()
            ->where('company_id', $company->id)
            ->pluck('enabled', 'module_key');

        $modules = collect(ModuleRegistry::labels())->map(fn (string $label, string $key): array => [
            'key' => $key,
            'label' => $label,
            'enabled' => (bool) ($enabled[$key] ?? false),
        ])->values();

        return response()->json(['modules' => $modules, 'business_type' => $company->businessType]);
    }

    public function toggle(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'module_key' => ['required', 'string'],
            'enabled' => ['required', 'boolean'],
        ]);

        abort_unless(ModuleRegistry::isValid($data['module_key']), 422, 'Неизвестный модуль.');

        $company = Company::query()->where('tenant_id', $request->user()->tenant_id)->firstOrFail();

        $module = CompanyModule::query()->updateOrCreate(
            ['tenant_id' => $request->user()->tenant_id, 'company_id' => $company->id, 'module_key' => $data['module_key']],
            ['enabled' => $data['enabled']]
        );

        $audit->record('company_module.toggled', $module, ['enabled' => $data['enabled']], [], $request);

        return response()->json($module);
    }
}
