<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BusinessType;
use App\Models\IntegrationRequest;
use App\Models\IntegrationRequestMessage;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Business\ModuleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** ТЗ раздел 23 — Super Admin: управление сферами/модулями + очередь заявок на интеграцию. */
class SuperAdminBusinessModulesController extends Controller
{
    public function businessTypes(): JsonResponse
    {
        return response()->json([
            'business_types' => BusinessType::query()->orderBy('sort_order')->get(),
            'modules' => ModuleRegistry::labels(),
        ]);
    }

    public function showBusinessType(BusinessType $businessType): JsonResponse
    {
        return response()->json([
            'business_type' => $businessType,
            'modules' => ModuleRegistry::labels(),
        ]);
    }

    public function updateBusinessType(Request $request, BusinessType $businessType, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'default_modules' => ['required', 'array'],
            'default_modules.*' => ['string', Rule::in(array_keys(ModuleRegistry::MODULES))],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $previous = $businessType->default_modules;
        $businessType->update([
            'default_modules' => $data['default_modules'],
            'is_active' => $data['is_active'] ?? $businessType->is_active,
        ]);

        $audit->record('business_type.updated', $businessType, ['default_modules' => $data['default_modules']], ['default_modules' => $previous], $request);

        return response()->json($businessType);
    }

    public function integrationRequests(): JsonResponse
    {
        $requests = IntegrationRequest::withoutGlobalScopes()
            ->with(['tenant:id,name', 'company:id,name', 'requestedBy:id,name,email', 'assignedAdmin:id,name'])
            ->latest('id')
            ->get();

        return response()->json($requests->map(fn (IntegrationRequest $r): array => [
            'id' => $r->id,
            'tenant_name' => $r->tenant?->name,
            'company_name' => $r->company?->name,
            'requested_by' => $r->requestedBy?->name,
            'platform_name' => $r->platform_name,
            'platform_url' => $r->platform_url,
            'plan_version' => $r->plan_version,
            'tech_contact' => $r->tech_contact,
            'api_docs_url' => $r->api_docs_url,
            'data_to_receive' => $r->data_to_receive,
            'data_to_send' => $r->data_to_send,
            'sync_frequency' => $r->sync_frequency,
            'scenario_description' => $r->scenario_description,
            'comment' => $r->comment,
            'status' => $r->status,
            'assigned_admin' => $r->assignedAdmin?->name,
            'assigned_admin_id' => $r->assigned_admin_id,
            'cost_estimate' => $r->cost_estimate,
            'dev_time_estimate' => $r->dev_time_estimate,
            'created_at' => $r->created_at,
        ]));
    }

    public function showIntegrationRequest(IntegrationRequest $integrationRequest): JsonResponse
    {
        $integrationRequest->load(['messages.user', 'tenant', 'company', 'requestedBy', 'assignedAdmin']);

        return response()->json($integrationRequest);
    }

    public function updateIntegrationRequest(Request $request, IntegrationRequest $integrationRequest, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(IntegrationRequest::STATUSES)],
            'assigned_admin_id' => ['nullable', 'integer', 'exists:users,id'],
            'cost_estimate' => ['nullable', 'numeric', 'min:0'],
            'dev_time_estimate' => ['nullable', 'string', 'max:120'],
        ]);

        $previous = $integrationRequest->only(['status', 'assigned_admin_id', 'cost_estimate', 'dev_time_estimate']);
        $integrationRequest->update(array_filter($data, fn ($v) => $v !== null));

        $audit->record('integration_request.updated', $integrationRequest, $data, $previous, $request);

        return response()->json($integrationRequest->fresh());
    }

    public function storeIntegrationRequestMessage(Request $request, IntegrationRequest $integrationRequest, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);

        $message = IntegrationRequestMessage::query()->create([
            'integration_request_id' => $integrationRequest->id,
            'user_id' => $request->user()->id,
            'is_admin' => true,
            'body' => $data['body'],
        ]);

        $audit->record('integration_request.message_added', $integrationRequest, ['body' => $data['body']], [], $request);

        User::withoutGlobalScopes()->where('tenant_id', $integrationRequest->tenant_id)
            ->where('role', User::ROLE_OWNER)
            ->get()
            ->each(fn (User $owner) => $owner->notify(new \App\Notifications\AppNotification(
                'integration_request',
                'Ответ по заявке на интеграцию: '.$integrationRequest->platform_name,
                \Illuminate\Support\Str::limit($data['body'], 140),
                null,
            )));

        return response()->json($message, 201);
    }
}
