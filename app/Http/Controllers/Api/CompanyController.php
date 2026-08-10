<?php

namespace App\Http\Controllers\Api;

use App\Models\Company;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CompanyController extends TenantResourceController
{
    protected function model(): string
    {
        return Company::class;
    }

    protected function rules(string $action): array
    {
        $required = $action === 'store' ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:160'],
            'industry' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:160'],
            'website' => ['nullable', 'url', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'timezone' => ['nullable', 'string', 'max:80'],
            'working_hours' => ['nullable', 'array'],
            'brand_settings' => ['nullable', 'array'],
        ];
    }

    public function uploadLogo(Request $request, Company $company, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('update', $company);

        $data = $request->validate([
            'photo' => ['required', 'image', 'max:4096'],
        ]);

        $path = $data['photo']->store('logos/'.$company->tenant_id, 'public');

        $brandSettings = $company->brand_settings ?? [];
        $brandSettings['logo_path'] = $path;
        $company->update(['brand_settings' => $brandSettings]);

        $audit->record('company.logo_updated', $company, ['logo_path' => $path], [], $request);

        return response()->json($company->refresh());
    }
}
