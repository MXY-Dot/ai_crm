<?php

namespace App\Support\Analytics;

use App\Models\AiRun;
use App\Models\ConversationAnalysis;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Conversation;
use App\Support\Inbox\ConversationStatus;
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

    /**
     * ТЗ раздел 2 -- the spec lists 16 top-level KPIs; this now covers all but
     * "количество заявок / успешно обработанных / потерянных" (that's leads
     * terminology this codebase doesn't use the same way -- total_leads/
     * conversion_rate below is the closest real equivalent) and "количество
     * диалогов без результата" (would need a 6th ConversationAnalysis outcome
     * bucket query with no reliable single-status mapping -- outcomes() already
     * exposes the full per-outcome breakdown for that, no need to duplicate a
     * guess at "no result" here).
     */
    public function kpis(Carbon $start, Carbon $end): array
    {
        $totalLeads = Lead::query()->count();
        $wonLeads = Lead::query()->where('status', 'won')->count();
        $runs = AiRun::query()->whereBetween('created_at', [$start, $end]);

        $conversationsInPeriod = Conversation::query()->whereBetween('created_at', [$start, $end]);
        $conversationsCount = (clone $conversationsInPeriod)->count();
        $messagesCount = Message::query()->whereBetween('sent_at', [$start, $end])->count();

        $customerIdsInPeriod = (clone $conversationsInPeriod)->whereNotNull('customer_id')->pluck('customer_id')->unique();
        // Postgres doesn't allow referencing a SELECT-list alias in HAVING (unlike MySQL) --
        // havingRaw() against the actual aggregate expression is the portable form.
        $repeatCustomers = $customerIdsInPeriod->isEmpty() ? 0 : Conversation::query()
            ->whereIn('customer_id', $customerIdsInPeriod)
            ->selectRaw('customer_id, count(*) as total')
            ->groupBy('customer_id')
            ->havingRaw('count(*) > 1')
            ->get()
            ->count();

        $handedToOperator = ConversationAnalysis::query()
            ->whereBetween('analyzed_at', [$start, $end])
            ->where('outcome', 'handed_to_operator')
            ->count();

        $fullyAiHandled = (clone $conversationsInPeriod)
            ->whereDoesntHave('messages', fn ($q) => $q->where('sender_type', 'operator'))
            ->count();

        return [
            'messages' => $messagesCount,
            'conversations' => $conversationsCount,
            'total_leads' => $totalLeads,
            'conversion_rate' => $totalLeads > 0 ? round($wonLeads / $totalLeads * 100, 1) : 0.0,
            'ai_runs' => (clone $runs)->count(),
            'avg_confidence' => (int) round((clone $runs)->avg('confidence') ?? 0),
            'avg_latency_ms' => (int) round((clone $runs)->avg('latency_ms') ?? 0),
            'ai_replacement_rate' => $this->replacementRate($runs),
            'unique_customers' => $customerIdsInPeriod->count(),
            'new_customers' => Customer::query()->whereBetween('created_at', [$start, $end])->count(),
            'repeat_customers' => $repeatCustomers,
            'handed_to_operator' => $handedToOperator,
            'fully_ai_handled' => $fullyAiHandled,
            'avg_messages_per_conversation' => $conversationsCount > 0 ? round($messagesCount / $conversationsCount, 1) : 0.0,
            // Current snapshot, not period-bound -- "how many conversations need
            // attention right now" is inherently a today question, not a date-range one.
            'active_conversations' => Conversation::query()->whereIn('status', [ConversationStatus::OPEN, ConversationStatus::PENDING_OPERATOR])->count(),
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

    /**
     * ТЗ раздел 10 — the 8-stage engagement funnel. Genuinely nested (each
     * stage is a strict subset of the one before it, not 8 independent counts)
     * so this behaves like an actual funnel, not 8 unrelated numbers that could
     * technically go back up. Every stage maps to a real, already-tracked
     * signal -- no invented "interest score":
     *
     * Написали → conversations created in period.
     * Получили ответ → first_response_at is set.
     * Продолжили диалог → the customer sent a 2nd message (real back-and-forth,
     *   not just one question that went nowhere).
     * Заинтересовались → the linked Lead crossed into 'qualified' (or 'won') --
     *   this is exactly what AiWorkflow::process() already uses that status
     *   transition for.
     * Оставили заявку → ConversationAnalysis outcome is 'lead_created' or
     *   'consultation_requested'.
     * Оставили контакты → the customer has a phone on file (not guaranteed on
     *   every channel -- Instagram/Facebook DMs never require one).
     * Совершили целевое действие → outcome is 'sale' or 'booking'.
     * Успешно завершили диалог → outcome is 'resolved', or is_resolved is true.
     *
     * The last 4 stages only ever have data for conversations
     * `conversations:analyze` has already processed (post-hoc, after resolve/
     * timeout) -- same "sparse until it accumulates" honesty as the rest of
     * this codebase's analytics, not a bug.
     */
    public function conversationFunnel(Carbon $start, Carbon $end): array
    {
        $conversations = Conversation::query()
            ->whereBetween('created_at', [$start, $end])
            ->with('lead:id,status')
            ->withCount(['messages as customer_message_count' => fn ($q) => $q->where('sender_type', 'customer')])
            ->get(['id', 'lead_id', 'customer_id', 'first_response_at']);

        $customerPhones = Customer::query()
            ->whereIn('id', $conversations->pluck('customer_id')->filter()->unique())
            ->pluck('phone', 'id');

        $analyses = ConversationAnalysis::query()
            ->whereIn('conversation_id', $conversations->pluck('id'))
            ->get(['conversation_id', 'outcome', 'is_resolved'])
            ->keyBy('conversation_id');

        $wrote = $conversations;
        $gotReply = $wrote->filter(fn (Conversation $c): bool => $c->first_response_at !== null);
        $continued = $gotReply->filter(fn (Conversation $c): bool => $c->customer_message_count >= 2);
        $interested = $continued->filter(fn (Conversation $c): bool => in_array($c->lead?->status, ['qualified', 'won'], true));
        $leftRequest = $interested->filter(fn (Conversation $c): bool => in_array($analyses[$c->id]->outcome ?? null, ['lead_created', 'consultation_requested'], true));
        $leftContacts = $leftRequest->filter(fn (Conversation $c): bool => ! empty($customerPhones[$c->customer_id] ?? null));
        $targetAction = $leftContacts->filter(fn (Conversation $c): bool => in_array($analyses[$c->id]->outcome ?? null, ['sale', 'booking'], true));
        $resolved = $targetAction->filter(fn (Conversation $c): bool => ($analyses[$c->id]->outcome ?? null) === 'resolved' || ($analyses[$c->id]->is_resolved ?? false) === true);

        $stages = [
            ['stage' => 'wrote', 'label' => 'Написали', 'count' => $wrote->count()],
            ['stage' => 'got_reply', 'label' => 'Получили ответ', 'count' => $gotReply->count()],
            ['stage' => 'continued', 'label' => 'Продолжили диалог', 'count' => $continued->count()],
            ['stage' => 'interested', 'label' => 'Заинтересовались', 'count' => $interested->count()],
            ['stage' => 'left_request', 'label' => 'Оставили заявку', 'count' => $leftRequest->count()],
            ['stage' => 'left_contacts', 'label' => 'Оставили контакты', 'count' => $leftContacts->count()],
            ['stage' => 'target_action', 'label' => 'Совершили целевое действие', 'count' => $targetAction->count()],
            ['stage' => 'resolved', 'label' => 'Успешно завершили диалог', 'count' => $resolved->count()],
        ];

        $top = $stages[0]['count'] ?: 1;

        return array_map(fn (array $s): array => $s + ['percent_of_total' => round($s['count'] / $top * 100, 1)], $stages);
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
