<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Incident;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\TenantAiStatus;
use App\Support\Emergency\EmergencyStateManager;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Tenant-facing half of ЭТАП 16 — current emergency mode (polled by the CRM's
 * red banner, see resources/js/stores/emergency.ts), the manual override toggle
 * (16.20), and the missed-conversations-after-an-incident query (16.19).
 */
class EmergencyStatusController extends Controller
{
    public function __construct(private readonly EmergencyStateManager $emergency)
    {
    }

    public function index(TenantContext $context): JsonResponse
    {
        $tenant = $this->tenant($context);
        $status = TenantAiStatus::query()->where('tenant_id', $tenant->id)->first();

        return response()->json([
            'mode' => $status ? ($status->manual_override ? 'emergency' : $status->mode) : 'normal',
            'reason' => $status?->reason,
            'since' => $status?->since,
            'manual_override' => $status?->manual_override ?? false,
            'incident_id' => $status?->active_incident_id,
        ]);
    }

    public function override(Request $request, TenantContext $context): JsonResponse
    {
        $tenant = $this->tenant($context);
        Gate::authorize('update', $tenant);

        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        $this->emergency->setManualOverride($tenant, $data['enabled']);

        return $this->index($context);
    }

    public function missed(TenantContext $context, Incident $incident): JsonResponse
    {
        $tenant = $this->tenant($context);
        Gate::authorize('view', $tenant);
        abort_if($incident->tenant_id !== $tenant->id, 404);

        $repliedConversationIds = Message::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('sender_type', '!=', 'customer')
            ->where('sent_at', '>=', $incident->started_at)
            ->pluck('conversation_id');

        $conversations = Conversation::query()
            ->where('status', 'pending_operator')
            ->where('last_message_at', '>=', $incident->started_at)
            ->whereNotIn('id', $repliedConversationIds)
            ->with('customer')
            ->orderByDesc('last_message_at')
            ->get(['id', 'customer_id', 'subject', 'last_message_at']);

        return response()->json(['conversations' => $conversations]);
    }

    private function tenant(TenantContext $context): Tenant
    {
        return Tenant::query()->findOrFail($context->id());
    }
}
