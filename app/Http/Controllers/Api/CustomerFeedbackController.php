<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerFeedback;
use App\Models\CrmTask;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * ЭТАП 17.2/17.3 — Customer Satisfaction Survey / Negative Feedback Recovery.
 * Recorded manually by an operator after a phone/in-person follow-up (see
 * PostServiceFollowUpCommand) — no autonomous AI survey exists, same
 * WhatsApp/Telegram consent constraint as Stage 13's follow-up work.
 */
class CustomerFeedbackController extends Controller
{
    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'lead_id' => ['nullable', 'integer', 'exists:leads,id'],
            'conversation_id' => ['nullable', 'integer', 'exists:conversations,id'],
            'satisfaction' => ['required', Rule::in(['positive', 'neutral', 'negative'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $customer = Customer::withoutGlobalScopes()->findOrFail($data['customer_id']);
        Gate::authorize('update', $customer);

        $feedback = CustomerFeedback::query()->create($data + [
            'recorded_by' => $request->user()?->id,
        ]);

        $audit->record('customer_feedback.recorded', $feedback, $feedback->only(['customer_id', 'satisfaction']), [], $request);

        $task = null;

        // ЭТАП 17.3 — negative feedback recovery reuses the existing task
        // system rather than inventing a separate "complaint" mechanism.
        if ($data['satisfaction'] === 'negative') {
            $task = CrmTask::query()->create([
                'tenant_id' => $customer->tenant_id,
                'company_id' => $customer->company_id,
                'customer_id' => $customer->id,
                'lead_id' => $data['lead_id'] ?? null,
                'title' => 'Жалоба: '.$customer->name,
                'description' => $data['notes'] ?? 'Клиент оставил негативный отзыв — уточните детали и предложите решение.',
                'status' => 'open',
                'priority' => 'high',
            ]);
        }

        return response()->json(['feedback' => $feedback, 'task' => $task], 201);
    }
}
