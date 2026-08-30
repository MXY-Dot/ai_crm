<?php

namespace App\Support\Analytics;

use App\Models\AiRun;
use App\Models\ConversationAnalysis;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Tenant-scoped KPI/sales/topics/outcomes/sentiment aggregation for a date
 * range — extracted out of AnalyticsController (which still owns everything
 * that only it needs, e.g. raw/messageTrend/sla/operators) so
 * AiReportGenerator can build a report from the exact same numbers the
 * /analytics page shows, instead of a second hand-rolled set of queries that
 * could silently drift from what the dashboard displays.
 */
class AnalyticsSnapshot
{
    private const LEAD_STATUSES = ['new', 'qualified', 'won', 'lost'];

    public function __construct(private readonly DateRangeResolver $range)
    {
    }

    public function kpis(Carbon $start, Carbon $end): array
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

    public function sales(Carbon $start, Carbon $end, string $bucket): array
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

    public function topics(Carbon $start, Carbon $end): array
    {
        $current = AiRun::query()
            ->whereBetween('created_at', [$start, $end])
            ->whereNotNull('intent')
            ->where('intent', '!=', '')
            ->selectRaw('intent, count(*) as count')
            ->groupBy('intent')
            ->orderByDesc('count')
            ->limit(15)
            ->pluck('count', 'intent');

        [$prevStart, $prevEnd] = $this->range->previousPeriod($start, $end);
        $previous = AiRun::query()
            ->whereBetween('created_at', [$prevStart, $prevEnd])
            ->whereNotNull('intent')
            ->where('intent', '!=', '')
            ->selectRaw('intent, count(*) as count')
            ->groupBy('intent')
            ->pluck('count', 'intent');

        $total = (int) $current->sum();

        return $current->map(function (int $count, string $intent) use ($previous, $total): array {
            $previousCount = (int) ($previous[$intent] ?? 0);

            return [
                'topic' => $intent,
                'count' => $count,
                'percent' => $total > 0 ? round($count / $total * 100, 1) : 0.0,
                'change_percent' => $previousCount > 0 ? round((($count - $previousCount) / $previousCount) * 100, 1) : ($count > 0 ? 100.0 : 0.0),
                'is_new' => $previousCount === 0 && $count > 0,
            ];
        })->values()->all();
    }

    public function outcomes(Carbon $start, Carbon $end): array
    {
        $counts = ConversationAnalysis::query()
            ->whereBetween('analyzed_at', [$start, $end])
            ->selectRaw('outcome, count(*) as count')
            ->groupBy('outcome')
            ->pluck('count', 'outcome');
        $total = (int) $counts->sum();

        return collect(ConversationAnalysis::OUTCOMES)
            ->map(fn (string $outcome): array => [
                'outcome' => $outcome,
                'count' => (int) ($counts[$outcome] ?? 0),
                'percent' => $total > 0 ? round(((int) ($counts[$outcome] ?? 0)) / $total * 100, 1) : 0.0,
            ])
            ->filter(fn (array $row): bool => $row['count'] > 0)
            ->values()
            ->all();
    }

    public function sentimentBreakdown(Carbon $start, Carbon $end): array
    {
        $counts = ConversationAnalysis::query()
            ->whereBetween('analyzed_at', [$start, $end])
            ->selectRaw('sentiment, count(*) as count')
            ->groupBy('sentiment')
            ->pluck('count', 'sentiment');
        $total = (int) $counts->sum();

        return collect(ConversationAnalysis::SENTIMENTS)->map(fn (string $sentiment): array => [
            'sentiment' => $sentiment,
            'count' => (int) ($counts[$sentiment] ?? 0),
            'percent' => $total > 0 ? round(((int) ($counts[$sentiment] ?? 0)) / $total * 100, 1) : 0.0,
        ])->values()->all();
    }

    /** Share of AI runs that did NOT require a human handoff — "AI resolved it alone." */
    public function replacementRate(Builder $runs): float
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
}
