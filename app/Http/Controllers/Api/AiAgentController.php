<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiAgent;
use App\Models\Tenant;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AiAgentController extends Controller
{
    public function update(Request $request, AiAgent $aiAgent, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($context->id());
        Gate::authorize('update', $tenant);

        if ((int) $aiAgent->tenant_id !== (int) $tenant->id) {
            abort(404);
        }

        $oldValues = $aiAgent->only(['name', 'status', 'handoff_threshold', 'instructions', 'settings']);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', Rule::in(['active', 'paused', 'disabled'])],
            'handoff_threshold' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'instructions' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'settings' => ['sometimes', 'array'],
        ]);

        $aiAgent->forceFill($data)->save();
        $audit->record('ai_agent.updated', $aiAgent, $aiAgent->only(['name', 'status', 'handoff_threshold', 'instructions', 'settings']), $oldValues, $request);

        return response()->json($aiAgent->refresh());
    }
}
