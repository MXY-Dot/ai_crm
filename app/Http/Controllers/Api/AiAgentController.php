<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiAgent;
use App\Models\Company;
use App\Models\Tenant;
use App\Support\Audit\AuditLogger;
use App\Support\Integrations\PlatformSettings;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AiAgentController extends Controller
{
    public function store(Request $request, TenantContext $context, AuditLogger $audit, PlatformSettings $platform): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($context->id());
        Gate::authorize('update', $tenant);

        $company = Company::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'status' => ['sometimes', Rule::in(['active', 'paused', 'disabled'])],
            'handoff_threshold' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'goal' => ['nullable', 'string', 'max:60'],
            'persona' => ['nullable', 'string', 'max:30'],
            'max_discount_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'forbidden_topics' => ['nullable', 'array'],
            'forbidden_topics.*' => ['string', 'max:120'],
            'instructions' => ['nullable', 'string', 'max:4000'],
            'model' => ['nullable', 'string', 'max:60'],
            'channels' => ['sometimes', 'array'],
            'channels.*' => [Rule::in(['telegram', 'whatsapp', 'instagram', 'facebook', 'website'])],
        ]);

        // A brand-new agent with no model picked used to sit silently answering with
        // the dumb keyword-matching fallback forever — new tenants shouldn't have to
        // know a model even needs picking for AI to actually work.
        $data['model'] = $data['model'] ?: $platform->defaultModel();

        $agent = AiAgent::query()->create($data + [
            'company_id' => $company->id,
            'provider' => 'dify',
            'status' => $data['status'] ?? 'active',
            'handoff_threshold' => $data['handoff_threshold'] ?? 70,
            'channels' => $data['channels'] ?? [],
        ]);

        $audit->record('ai_agent.created', $agent, $agent->only(['name', 'status', 'handoff_threshold', 'goal', 'persona', 'max_discount_percent', 'forbidden_topics', 'instructions', 'model', 'channels']), [], $request);

        return response()->json($agent, 201);
    }

    public function update(Request $request, AiAgent $aiAgent, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($context->id());
        Gate::authorize('update', $tenant);

        if ((int) $aiAgent->tenant_id !== (int) $tenant->id) {
            abort(404);
        }

        $oldValues = $aiAgent->only(['name', 'status', 'handoff_threshold', 'goal', 'persona', 'max_discount_percent', 'forbidden_topics', 'instructions', 'model', 'channels', 'settings']);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', Rule::in(['active', 'paused', 'disabled'])],
            'handoff_threshold' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'goal' => ['sometimes', 'nullable', 'string', 'max:60'],
            'persona' => ['sometimes', 'nullable', 'string', 'max:30'],
            'max_discount_percent' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            'forbidden_topics' => ['sometimes', 'nullable', 'array'],
            'forbidden_topics.*' => ['string', 'max:120'],
            'instructions' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'model' => ['sometimes', 'nullable', 'string', 'max:60'],
            'channels' => ['sometimes', 'array'],
            'channels.*' => [Rule::in(['telegram', 'whatsapp', 'instagram', 'facebook', 'website'])],
            'settings' => ['sometimes', 'array'],
        ]);

        $aiAgent->forceFill($data)->save();
        $audit->record('ai_agent.updated', $aiAgent, $aiAgent->only(['name', 'status', 'handoff_threshold', 'goal', 'persona', 'max_discount_percent', 'forbidden_topics', 'instructions', 'model', 'channels', 'settings']), $oldValues, $request);

        return response()->json($aiAgent->refresh());
    }
}
