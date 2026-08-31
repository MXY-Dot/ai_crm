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
use Illuminate\Support\Collection;

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
     * ТЗ раздел 2 -- the spec's 16 top-level KPIs. "Диалогов без результата"
     * still isn't duplicated here as a single bucket -- outcomes() already
     * exposes the full per-outcome breakdown, and "no result" has no single
     * reliable status mapping worth guessing at.
     *
     * $filterIds (ТЗ раздел 13) narrows every conversation/message/AiRun-based
     * metric below to that conversation-id set when given; total_leads/
     * conversion_rate/leads_created stay unfiltered (Lead has no direct
     * conversation link) -- documented, not a silent gap.
     */
    public function kpis(Carbon $start, Carbon $end, ?Collection $filterIds = null): array
    {
        $totalLeads = Lead::query()->count();
        $wonLeads = Lead::query()->where('status', 'won')->count();
        // total_leads/conversion_rate above are all-time (kept as-is, other
        // callers depend on that meaning) -- this is the period-scoped "количество
        // заявок" the spec actually asks for.
        $leadsCreated = Lead::query()->whereBetween('created_at', [$start, $end])->count();
        $runs = AiRun::query()->whereBetween('created_at', [$start, $end])
            ->when($filterIds, fn (Builder $q, Collection $ids) => $q->whereIn('conversation_id', $ids));

        $conversationsInPeriod = Conversation::query()->whereBetween('created_at', [$start, $end])
            ->when($filterIds, fn (Builder $q, Collection $ids) => $q->whereIn('id', $ids));
        $conversationsCount = (clone $conversationsInPeriod)->count();
        $messagesCount = Message::query()->whereBetween('sent_at', [$start, $end])
            ->when($filterIds, fn (Builder $q, Collection $ids) => $q->whereIn('conversation_id', $ids))
            ->count();

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
            ->when($filterIds, fn (Builder $q, Collection $ids) => $q->whereIn('conversation_id', $ids))
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
            'leads_created' => $leadsCreated,
            // "количество незакрытых диалогов" -- conversations from this period still not closed.
            'unresolved_conversations' => (clone $conversationsInPeriod)->where('status', '!=', ConversationStatus::CLOSED)->count(),
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

        // ТЗ раздел 7 -- "количество успешно решённых / нерешённых вопросов" per
        // topic, joined via each intent's own conversation_ids against
        // ConversationAnalysis (the same per-conversation analysis outcomes()
        // already reads, just grouped by topic instead of overall). "Resolved"
        // uses the same outcome==='resolved' OR is_resolved===true condition
        // as conversationFunnel()'s own last stage, not is_resolved alone --
        // an operator can correct just the outcome via updateAnalysis()
        // without AI ever having flipped is_resolved.
        $runsByIntent = AiRun::query()
            ->whereBetween('created_at', [$start, $end])
            ->whereIn('intent', $current->keys())
            ->get(['conversation_id', 'intent'])
            ->groupBy('intent');

        $analyses = ConversationAnalysis::query()
            ->whereIn('conversation_id', $runsByIntent->flatten(1)->pluck('conversation_id')->unique())
            ->get(['conversation_id', 'outcome', 'is_resolved'])
            ->keyBy('conversation_id');

        return $current->map(function (int $count, string $intent) use ($previous, $total, $runsByIntent, $analyses): array {
            $previousCount = (int) ($previous[$intent] ?? 0);

            $conversationIds = ($runsByIntent[$intent] ?? collect())->pluck('conversation_id')->unique();
            $analyzed = $conversationIds->filter(fn ($id) => $analyses->has($id));
            $resolved = $analyzed->filter(fn ($id) => $analyses[$id]->outcome === 'resolved' || $analyses[$id]->is_resolved === true)->count();

            return [
                'topic' => $intent,
                'count' => $count,
                'percent' => $total > 0 ? round($count / $total * 100, 1) : 0.0,
                'change_percent' => $previousCount > 0 ? round((($count - $previousCount) / $previousCount) * 100, 1) : ($count > 0 ? 100.0 : 0.0),
                'is_new' => $previousCount === 0 && $count > 0,
                'resolved_count' => $resolved,
                'unresolved_count' => max(0, $analyzed->count() - $resolved),
            ];
        })->values()->all();
    }

    /**
     * ТЗ раздел 9/12 -- "основные слабые темы" AI struggles with: topics where
     * the AI-classified conversation's own analysis came back with a "did not
     * work out" outcome unusually often. Requires at least 3 occurrences and a
     * 30%+ bad-outcome rate to surface -- a topic asked twice with one handoff
     * isn't a pattern yet.
     */
    public function weakTopics(Carbon $start, Carbon $end): array
    {
        $badOutcomes = ['not_resolved', 'ai_failed', 'customer_stopped_responding', 'handed_to_operator'];

        $runs = AiRun::query()
            ->whereBetween('created_at', [$start, $end])
            ->whereNotNull('intent')
            ->where('intent', '!=', '')
            ->get(['conversation_id', 'intent']);

        if ($runs->isEmpty()) {
            return [];
        }

        $analyses = ConversationAnalysis::query()
            ->whereIn('conversation_id', $runs->pluck('conversation_id')->unique())
            ->get(['conversation_id', 'outcome'])
            ->keyBy('conversation_id');

        return $runs->groupBy('intent')
            ->map(function ($group, string $intent) use ($analyses, $badOutcomes): array {
                $total = $group->pluck('conversation_id')->unique()->count();
                $bad = $group->pluck('conversation_id')->unique()
                    ->filter(fn ($id) => in_array($analyses[$id]->outcome ?? null, $badOutcomes, true))
                    ->count();

                return [
                    'topic' => $intent,
                    'total' => $total,
                    'weak_count' => $bad,
                    'weak_rate' => $total > 0 ? round($bad / $total * 100, 1) : 0.0,
                ];
            })
            ->filter(fn (array $row): bool => $row['total'] >= 3 && $row['weak_rate'] >= 30)
            ->sortByDesc('weak_rate')
            ->take(5)
            ->values()
            ->all();
    }

    public function outcomes(Carbon $start, Carbon $end, ?Collection $filterIds = null): array
    {
        $counts = ConversationAnalysis::query()
            ->whereBetween('analyzed_at', [$start, $end])
            ->when($filterIds, fn (Builder $q, Collection $ids) => $q->whereIn('conversation_id', $ids))
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

    public function sentimentBreakdown(Carbon $start, Carbon $end, ?Collection $filterIds = null): array
    {
        $counts = ConversationAnalysis::query()
            ->whereBetween('analyzed_at', [$start, $end])
            ->when($filterIds, fn (Builder $q, Collection $ids) => $q->whereIn('conversation_id', $ids))
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
