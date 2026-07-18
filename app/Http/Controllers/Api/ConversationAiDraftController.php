<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Support\Ai\AiWorkflow;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ConversationAiDraftController extends Controller
{
    public function __invoke(Request $request, Conversation $conversation, TenantContext $context, AiWorkflow $aiWorkflow): JsonResponse
    {
        $tenant = Tenant::query()->findOrFail($context->id());
        Gate::authorize('update', $tenant);

        if ((int) $conversation->tenant_id !== (int) $tenant->id) {
            abort(404);
        }

        $conversation->load(['lead', 'messages']);
        $company = Company::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->findOrFail($conversation->company_id);
        $lead = $conversation->lead;

        if (! $lead) {
            throw ValidationException::withMessages(['conversation' => 'Conversation has no linked lead.']);
        }

        $message = $conversation->messages
            ->where('sender_type', 'customer')
            ->sortByDesc(fn ($item) => $item->sent_at?->timestamp ?? $item->id)
            ->first();

        if (! $message) {
            throw ValidationException::withMessages(['conversation' => 'Conversation has no customer message to analyze.']);
        }

        $ai = $aiWorkflow->process($tenant, $company, $conversation->fresh(), $lead, $message);

        return response()->json([
            'ok' => true,
            'ai_agent' => $ai['agent'],
            'ai_run' => $ai['run'],
            'draft_message' => $ai['draft_message'],
            'conversation' => $conversation->fresh(['channel', 'customer', 'lead']),
        ], 201);
    }
}