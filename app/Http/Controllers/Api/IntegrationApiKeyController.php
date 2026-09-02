<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IntegrationApiKey;
use App\Support\Audit\AuditLogger;
use App\Support\Erp\IntegrationApiKeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class IntegrationApiKeyController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', IntegrationApiKey::class);

        return response()->json(IntegrationApiKey::query()->with('createdBy:id,name')->latest()->get());
    }

    /** Returns the plaintext token in the response body ONCE -- it is never retrievable again after this call (see IntegrationApiKeyService's own docblock). */
    public function store(Request $request, IntegrationApiKeyService $keys, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('create', IntegrationApiKey::class);

        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'name' => ['required', 'string', 'max:120'],
        ]);

        ['key' => $key, 'token' => $token] = $keys->generate($request->user()->tenant_id, $data['company_id'], $data['name'], $request->user());
        $audit->record('integration_api_key.created', $key, ['name' => $key->name], [], $request);

        return response()->json(['key' => $key, 'token' => $token], 201);
    }

    public function destroy(Request $request, IntegrationApiKey $integrationApiKey, IntegrationApiKeyService $keys, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('delete', $integrationApiKey);

        $before = $integrationApiKey->toArray();
        $integrationApiKey = $keys->revoke($integrationApiKey);
        $audit->record('integration_api_key.revoked', $integrationApiKey, $integrationApiKey->toArray(), $before, $request);

        return response()->json($integrationApiKey);
    }
}
