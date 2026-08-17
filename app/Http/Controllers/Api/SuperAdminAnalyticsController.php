<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiAgent;
use App\Models\AiRun;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\KnowledgeDocument;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Analytics\DateRangeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class SuperAdminAnalyticsController extends Controller
{
    private const LEAD_STATUSES = ['new', 'qualified', 'won', 'lost'];

    private const LLM_PROVIDERS = ['groq', 'openai', 'anthropic', 'google', 'deepseek'];

    public function __construct(private readonly DateRangeResolver $range)
    {
    }

    public function index(Request $request): JsonResponse
    {
        [$start, $end, $bucket] = $this->range->resolve($request);

        return response()->json([
            'range' => ['start' => $start->toIso8601String(), 'end' => $end->toIso8601String()],
            'kpis' => $this->kpis($start, $end),
            'message_trend' => $this->messageTrend($start, $end, $bucket),
            'leads_funnel' => $this->leadsFunnel(),
            'ai' => $this->aiPerformance($start, $end),
            'llm_usage' => $this->llmUsage($start, $end),
            'channels' => $this->channels($start, $end),
            'knowledge' => $this->knowledge(),
            'team' => $this->team(),
            'top_tenants' => $this->topTenants($start, $end),
        ]);
    }

    private function kpis(Carbon $start, Carbon $end): array
    {
        $totalLeads = Lead::withoutGlobalScopes()->count();
        $wonLeads = Lead::withoutGlobalScopes()->where('status', 'won')->count();

        return [
            'messages_30d' => Message::withoutGlobalScopes()->whereBetween('sent_at', [$start, $end])->count(),
            'conversations_30d' => Conversation::withoutGlobalScopes()->whereBetween('created_at', [$start, $end])->count(),
            'total_leads' => $totalLeads,
            'conversion_rate' => $totalLeads > 0 ? round($wonLeads / $totalLeads * 100, 1) : 0.0,
            'ai_runs_30d' => AiRun::withoutGlobalScopes()->whereBetween('created_at', [$start, $end])->count(),
            'avg_confidence' => (int) round(AiRun::withoutGlobalScopes()->whereBetween('created_at', [$start, $end])->avg('confidence') ?? 0),
        ];
    }

    private function messageTrend(Carbon $start, Carbon $end, string $bucket): array
    {
        if ($bucket === 'hour') {
            $rows = Message::withoutGlobalScopes()
                ->whereBetween('sent_at', [$start, $end])
                ->selectRaw('extract(hour from sent_at) as bucket, count(*) as count')
                ->groupBy('bucket')
                ->get()
                ->pluck('count', 'bucket')
                ->mapWithKeys(fn ($count, $hour) => [(int) $hour => (int) $count]);
        } else {
            $rows = Message::withoutGlobalScopes()
                ->whereBetween('sent_at', [$start, $end])
                ->selectRaw('DATE(sent_at) as day, count(*) as count')
                ->groupBy('day')
                ->get()
                ->pluck('count', 'day')
                ->mapWithKeys(fn ($count, $day) => [(string) $day => (int) $count]);
        }

        return array_map(
            fn (array $point): array => ['date' => $point['date'], 'label' => $point['label'], 'count' => (int) $point['value']],
            $this->range->fillSeries($start, $end, $bucket, $rows->all()),
        );
    }

    private function leadsFunnel(): array
    {
        $counts = Lead::withoutGlobalScopes()->selectRaw('status, count(*) as count')->groupBy('status')->pluck('count', 'status');
        $total = (int) $counts->sum();

        return collect(self::LEAD_STATUSES)->map(fn (string $status) => [
            'status' => $status,
            'count' => (int) ($counts[$status] ?? 0),
            'percent' => $total > 0 ? round(((int) ($counts[$status] ?? 0)) / $total * 100, 1) : 0.0,
        ])->values()->all();
    }

    private function aiPerformance(Carbon $start, Carbon $end): array
    {
        $agentsByStatus = AiAgent::withoutGlobalScopes()->selectRaw('status, count(*) as count')->groupBy('status')->pluck('count', 'status');
        $runs = AiRun::withoutGlobalScopes()->whereBetween('created_at', [$start, $end]);
        $runsCount = (clone $runs)->count();
        $handoffCount = (clone $runs)->where('payload->handoff_required', true)->count();

        return [
            'agents_active' => (int) ($agentsByStatus['active'] ?? 0),
            'agents_paused' => (int) ($agentsByStatus['paused'] ?? 0),
            'agents_disabled' => (int) ($agentsByStatus['disabled'] ?? 0),
            'runs_30d' => $runsCount,
            'avg_confidence' => (int) round((clone $runs)->avg('confidence') ?? 0),
            'handoff_rate' => $runsCount > 0 ? round($handoffCount / $runsCount * 100, 1) : 0.0,
        ];
    }

    /** Spend/token breakdown per direct LLM provider — all 5 platform-managed providers (see SuperAdminLlmProviderController), always returned in a fixed order even at zero. */
    private function llmUsage(Carbon $start, Carbon $end): array
    {
        $rows = AiRun::withoutGlobalScopes()
            ->whereNotNull('provider')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('provider, count(*) as requests, sum(tokens_in) as tokens_in, sum(tokens_out) as tokens_out, sum(cost_usd) as cost_usd')
            ->groupBy('provider')
            ->get()
            ->keyBy('provider');

        return collect(self::LLM_PROVIDERS)->map(fn (string $provider): array => [
            'provider' => $provider,
            'requests' => (int) ($rows[$provider]->requests ?? 0),
            'tokens_in' => (int) ($rows[$provider]->tokens_in ?? 0),
            'tokens_out' => (int) ($rows[$provider]->tokens_out ?? 0),
            'cost_usd' => round((float) ($rows[$provider]->cost_usd ?? 0), 4),
        ])->values()->all();
    }

    private function channels(Carbon $start, Carbon $end): array
    {
        $messagesByProvider = Message::withoutGlobalScopes()
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->join('channels', 'channels.id', '=', 'conversations.channel_id')
            ->whereBetween('messages.sent_at', [$start, $end])
            ->selectRaw('channels.provider as provider, count(*) as count')
            ->groupBy('channels.provider')
            ->pluck('count', 'provider');

        return Channel::withoutGlobalScopes()
            ->selectRaw("provider, count(*) as total, sum(case when status = 'connected' then 1 else 0 end) as active")
            ->groupBy('provider')
            ->get()
            ->map(fn ($row) => [
                'provider' => $row->provider,
                'total' => (int) $row->total,
                'active' => (int) $row->active,
                'messages_30d' => (int) ($messagesByProvider[$row->provider] ?? 0),
            ])
            ->sortByDesc('messages_30d')
            ->values()
            ->all();
    }

    private function knowledge(): array
    {
        $counts = KnowledgeDocument::withoutGlobalScopes()->selectRaw('status, count(*) as count')->groupBy('status')->pluck('count', 'status');

        return [
            'indexed' => (int) ($counts['indexed'] ?? 0),
            'queued' => (int) ($counts['queued'] ?? 0),
            'failed' => (int) ($counts['failed'] ?? 0),
        ];
    }

    private function team(): array
    {
        $byRole = User::query()->whereNotNull('tenant_id')->selectRaw('role, count(*) as count')->groupBy('role')->pluck('count', 'role');
        $byStatus = User::query()->whereNotNull('tenant_id')->selectRaw('status, count(*) as count')->groupBy('status')->pluck('count', 'status');

        return [
            'by_role' => [
                'owner' => (int) ($byRole['owner'] ?? 0),
                'manager' => (int) ($byRole['manager'] ?? 0),
                'operator' => (int) ($byRole['operator'] ?? 0),
            ],
            'active' => (int) ($byStatus['active'] ?? 0),
            'invited' => (int) ($byStatus['invited'] ?? 0),
            'disabled' => (int) ($byStatus['disabled'] ?? 0),
        ];
    }

    private function topTenants(Carbon $start, Carbon $end): array
    {
        $topRows = Message::withoutGlobalScopes()
            ->whereBetween('sent_at', [$start, $end])
            ->selectRaw('tenant_id, count(*) as messages')
            ->groupBy('tenant_id')
            ->orderByDesc('messages')
            ->limit(5)
            ->get();

        if ($topRows->isEmpty()) {
            return [];
        }

        $tenants = Tenant::query()
            ->with(['companies' => fn ($q) => $q->limit(1)])
            ->whereIn('id', $topRows->pluck('tenant_id'))
            ->get()
            ->keyBy('id');

        $leadsByTenant = Lead::withoutGlobalScopes()
            ->whereIn('tenant_id', $topRows->pluck('tenant_id'))
            ->selectRaw('tenant_id, count(*) as count')
            ->groupBy('tenant_id')
            ->pluck('count', 'tenant_id');

        return $topRows->map(function ($row) use ($tenants, $leadsByTenant) {
            $tenant = $tenants[$row->tenant_id] ?? null;

            return [
                'id' => $row->tenant_id,
                'name' => $tenant?->companies->first()?->name ?? $tenant?->name ?? 'Компания #'.$row->tenant_id,
                'plan' => Arr::get($tenant?->settings, 'billing.plan', 'starter'),
                'messages_30d' => (int) $row->messages,
                'leads' => (int) ($leadsByTenant[$row->tenant_id] ?? 0),
            ];
        })->values()->all();
    }
}
