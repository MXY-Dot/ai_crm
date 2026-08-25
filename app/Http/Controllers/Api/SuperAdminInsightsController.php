<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeGap;
use App\Models\Lead;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;

/**
 * ЭТАП 19.6/19.7 — Lost Sale Analysis + FAQ Gap Detection, platform-wide.
 * Both read real data collected as a side effect of normal operation
 * (AiWorkflow logging a KnowledgeGap when its own anti-hallucination guard
 * fires; an operator picking a reason when marking a lead lost) — no
 * synthetic/demo numbers, so both sections legitimately start out sparse
 * until that data accumulates.
 */
class SuperAdminInsightsController extends Controller
{
    public function knowledgeGaps(): JsonResponse
    {
        $recent = KnowledgeGap::withoutGlobalScopes()
            ->with(['tenant:id,name', 'company:id,name'])
            ->latest('created_at')
            ->limit(100)
            ->get()
            ->map(fn (KnowledgeGap $gap): array => [
                'id' => $gap->id,
                'tenant_name' => $gap->tenant?->name,
                'company_name' => $gap->company?->name,
                'customer_message' => $gap->customer_message,
                'created_at' => $gap->created_at,
            ]);

        $byTenant = KnowledgeGap::withoutGlobalScopes()
            ->selectRaw('tenant_id, count(*) as total')
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('tenant_id')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        $tenantNames = Tenant::query()->whereIn('id', $byTenant->pluck('tenant_id'))->pluck('name', 'id');

        return response()->json([
            'total_30d' => KnowledgeGap::withoutGlobalScopes()->where('created_at', '>=', now()->subDays(30))->count(),
            'by_tenant' => $byTenant->map(fn ($row): array => [
                'tenant_id' => (int) $row->tenant_id,
                'name' => $tenantNames[$row->tenant_id] ?? ('#'.$row->tenant_id),
                'total' => (int) $row->total,
            ])->values(),
            'recent' => $recent,
        ]);
    }

    public function lostLeads(): JsonResponse
    {
        $lost = Lead::withoutGlobalScopes()->where('status', 'lost');

        $byReason = (clone $lost)
            ->selectRaw("coalesce(nullif(lost_reason, ''), 'Не указано') as reason, count(*) as total")
            ->groupBy('reason')
            ->orderByDesc('total')
            ->get();

        $recent = (clone $lost)
            ->with(['tenant:id,name', 'company:id,name'])
            ->latest('updated_at')
            ->limit(100)
            ->get()
            ->map(fn (Lead $lead): array => [
                'id' => $lead->id,
                'title' => $lead->title,
                'tenant_name' => $lead->tenant?->name,
                'company_name' => $lead->company?->name,
                'amount' => $lead->amount,
                'lost_reason' => $lead->lost_reason,
                'updated_at' => $lead->updated_at,
            ]);

        $total = (clone $lost)->count();

        return response()->json([
            'total' => $total,
            'by_reason' => $byReason->map(fn ($row): array => [
                'reason' => $row->reason,
                'total' => (int) $row->total,
                'percent' => $total > 0 ? round(((int) $row->total / $total) * 100) : 0,
            ])->values(),
            'recent' => $recent,
        ]);
    }
}
