<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiRun;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\LlmCallFailure;
use App\Models\Message;
use App\Support\Analytics\DateRangeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * ЭТАП 19.1-19.5 — tenant-facing analytics ("Глубокая аналитика"). Backs
 * AnalyticsPage.vue. Before this, that page had no backend at all — it only
 * ever saw whatever was already loaded into the dashboard bootstrap payload
 * (12 conversations / 24 messages / 10 ai_runs, total, not per period), so any
 * date range beyond the last few records rendered as empty. `raw` here fixes
 * that by actually querying the selected range; everything else is new
 * aggregate reporting the old client-side-only page never had.
 *
 * Deliberately does not include CSAT (no customer-survey mechanism exists
 * anywhere in this codebase) or a ground-truth "accuracy" figure (only the
 * model's own self-reported confidence exists) — see the ЭТАП 19 build plan
 * for why those two are excluded rather than faked.
 */
class AnalyticsController extends Controller
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
            'raw' => $this->raw($start, $end),
            'kpis' => $this->kpis($start, $end),
            'message_trend' => $this->messageTrend($start, $end, $bucket),
            'leads_funnel' => $this->leadsFunnel(),
            'ai_performance' => $this->aiPerformance($start, $end),
            'llm_usage' => $this->llmUsage($start, $end),
            'sales' => $this->sales($start, $end, $bucket),
        ]);
    }

    /** Date-range-scoped conversations/messages/ai_runs for the existing chart components (AnalyticsKpis/LoadHeatmap/MessageLoadDonut/PriorityBreakdown/DialogsTrendChart) — same field shapes as DashboardData, just not capped at the bootstrap's fixed 12/24/10. */
    private function raw(Carbon $start, Carbon $end): array
    {
        return [
            'conversations' => Conversation::query()
                ->with(['channel:id,provider,name', 'customer:id,name', 'lead:id,title'])
                ->whereBetween('last_message_at', [$start, $end])
                ->orderByDesc('last_message_at')
                ->limit(1000)
                ->get(['id', 'channel_id', 'customer_id', 'lead_id', 'external_id', 'subject', 'status', 'priority', 'last_message_at', 'ai_summary']),
            'messages' => Message::query()
                ->whereBetween('sent_at', [$start, $end])
                ->orderBy('sent_at')
                ->limit(5000)
                ->get(['id', 'conversation_id', 'sender_type', 'sender_name', 'body', 'sent_at', 'meta']),
            'ai_runs' => AiRun::query()
                ->with(['agent:id,name,provider', 'conversation:id,subject', 'lead:id,title'])
                ->whereBetween('finished_at', [$start, $end])
                ->orderByDesc('finished_at')
                ->limit(2000)
                ->get(['id', 'ai_agent_id', 'conversation_id', 'lead_id', 'status', 'confidence', 'intent', 'summary', 'next_action', 'finished_at']),
        ];
    }

    private function kpis(Carbon $start, Carbon $end): array
    {
        $totalLeads = Lead::query()->count();
        $wonLeads = Lead::query()->where('status', 'won')->count();
        $runs = AiRun::query()->whereBetween('created_at', [$start, $end]);

        return [
            'messages' => Message::query()->whereBetween('sent_at', [$start, $end])->count(),
            'conversations' => Conversation::query()->whereBetween('created_at', [$start, $end])->count(),
            'total_leads' => $totalLeads,
            'conversion_rate' => $totalLeads > 0 ? round($wonLeads / $totalLeads * 100, 1) : 0.0,
            'ai_runs' => (clone $runs)->count(),
            'avg_confidence' => (int) round((clone $runs)->avg('confidence') ?? 0),
            'avg_latency_ms' => (int) round((clone $runs)->avg('latency_ms') ?? 0),
            'ai_replacement_rate' => $this->replacementRate($runs),
        ];
    }

    private function messageTrend(Carbon $start, Carbon $end, string $bucket): array
    {
        $column = $bucket === 'hour' ? 'extract(hour from sent_at)' : 'DATE(sent_at)';

        $rows = Message::query()
            ->whereBetween('sent_at', [$start, $end])
            ->selectRaw("{$column} as bucket_key, count(*) as count")
            ->groupBy('bucket_key')
            ->get()
            ->mapWithKeys(fn ($row) => [$bucket === 'hour' ? (int) $row->bucket_key : (string) $row->bucket_key => (int) $row->count]);

        return array_map(
            fn (array $point): array => ['date' => $point['date'], 'label' => $point['label'], 'count' => (int) $point['value']],
            $this->range->fillSeries($start, $end, $bucket, $rows->all()),
        );
    }

    private function leadsFunnel(): array
    {
        $counts = Lead::query()->selectRaw('status, count(*) as count')->groupBy('status')->pluck('count', 'status');
        $total = (int) $counts->sum();

        return collect(self::LEAD_STATUSES)->map(fn (string $status) => [
            'status' => $status,
            'count' => (int) ($counts[$status] ?? 0),
            'percent' => $total > 0 ? round(((int) ($counts[$status] ?? 0)) / $total * 100, 1) : 0.0,
        ])->values()->all();
    }

    /** 19.2 AI Replacement Rate + 19.3 AI Performance (minus CSAT/ground-truth accuracy, see class docblock). */
    private function aiPerformance(Carbon $start, Carbon $end): array
    {
        $runs = AiRun::query()->whereBetween('created_at', [$start, $end]);
        $runsCount = (clone $runs)->count();
        $handoffCount = (clone $runs)->where('payload->handoff_required', true)->count();

        return [
            'runs' => $runsCount,
            'avg_confidence' => (int) round((clone $runs)->avg('confidence') ?? 0),
            'avg_latency_ms' => (int) round((clone $runs)->avg('latency_ms') ?? 0),
            'handoff_rate' => $runsCount > 0 ? round($handoffCount / $runsCount * 100, 1) : 0.0,
            'ai_replacement_rate' => $this->replacementRate($runs),
        ];
    }

    /** Share of AI runs that did NOT require a human handoff — "AI resolved it alone." */
    private function replacementRate(Builder $runs): float
    {
        $total = (clone $runs)->count();

        if ($total === 0) {
            return 0.0;
        }

        $resolved = (clone $runs)->where(function ($query): void {
            $query->whereNull('payload->handoff_required')->orWhere('payload->handoff_required', false);
        })->count();

        return round($resolved / $total * 100, 1);
    }

    /** 19.4 Groq/LLM Performance Analytics — per provider, always all 5 in a fixed order even at zero. */
    private function llmUsage(Carbon $start, Carbon $end): array
    {
        $usage = AiRun::query()
            ->whereNotNull('provider')
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('provider, count(*) as requests, sum(tokens_in) as tokens_in, sum(tokens_out) as tokens_out, sum(cost_usd) as cost_usd, avg(latency_ms) as avg_latency_ms')
            ->groupBy('provider')
            ->get()
            ->keyBy('provider');

        $failures = LlmCallFailure::query()
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('provider, count(*) as errors')
            ->groupBy('provider')
            ->get()
            ->pluck('errors', 'provider');

        return collect(self::LLM_PROVIDERS)->map(function (string $provider) use ($usage, $failures): array {
            $requests = (int) ($usage[$provider]->requests ?? 0);
            $errors = (int) ($failures[$provider] ?? 0);
            $attempts = $requests + $errors;

            return [
                'provider' => $provider,
                'requests' => $requests,
                'tokens_in' => (int) ($usage[$provider]->tokens_in ?? 0),
                'tokens_out' => (int) ($usage[$provider]->tokens_out ?? 0),
                'cost_usd' => round((float) ($usage[$provider]->cost_usd ?? 0), 4),
                'avg_latency_ms' => (int) round((float) ($usage[$provider]->avg_latency_ms ?? 0)),
                'errors' => $errors,
                'error_rate' => $attempts > 0 ? round($errors / $attempts * 100, 1) : 0.0,
            ];
        })->values()->all();
    }

    /** 19.5 Sales Analytics. */
    private function sales(Carbon $start, Carbon $end, string $bucket): array
    {
        $wonInRange = Lead::query()->where('status', 'won')->whereBetween('won_at', [$start, $end]);
        $totalRevenue = (float) (clone $wonInRange)->sum('amount');
        $wonCount = (clone $wonInRange)->count();

        $funnel = Lead::query()->selectRaw('status, count(*) as count, sum(amount) as amount_sum')->groupBy('status')->get()->keyBy('status');

        $bySource = Lead::query()
            ->selectRaw("coalesce(source, 'unknown') as source, count(*) as count")
            ->groupBy('source')
            ->orderByDesc('count')
            ->get();

        $column = $bucket === 'hour' ? 'extract(hour from won_at)' : 'DATE(won_at)';
        $trendRows = (clone $wonInRange)
            ->selectRaw("{$column} as bucket_key, sum(amount) as amount")
            ->groupBy('bucket_key')
            ->get()
            ->mapWithKeys(fn ($row) => [$bucket === 'hour' ? (int) $row->bucket_key : (string) $row->bucket_key => (float) $row->amount]);

        return [
            'total_revenue' => $totalRevenue,
            'won_count' => $wonCount,
            'avg_deal_size' => $wonCount > 0 ? round($totalRevenue / $wonCount, 2) : 0.0,
            'funnel' => collect(self::LEAD_STATUSES)->map(fn (string $status) => [
                'status' => $status,
                'count' => (int) ($funnel[$status]->count ?? 0),
                'amount_sum' => (float) ($funnel[$status]->amount_sum ?? 0),
            ])->values()->all(),
            'by_source' => $bySource->map(fn ($row) => ['source' => $row->source, 'count' => (int) $row->count])->values()->all(),
            'trend' => array_map(
                fn (array $point): array => ['date' => $point['date'], 'label' => $point['label'], 'amount' => round($point['value'], 2)],
                $this->range->fillSeries($start, $end, $bucket, $trendRows->all()),
            ),
        ];
    }
}
