<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiRun;
use App\Models\Conversation;
use App\Models\ConversationAnalysis;
use App\Models\KnowledgeGap;
use App\Models\Lead;
use App\Models\LlmCallFailure;
use App\Models\Message;
use App\Models\User;
use App\Support\Analytics\AnalyticsSnapshot;
use App\Support\Analytics\DateRangeResolver;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function __construct(private readonly DateRangeResolver $range, private readonly AnalyticsSnapshot $snapshot)
    {
    }

    public function index(Request $request): JsonResponse
    {
        [$start, $end, $bucket] = $this->range->resolve($request);

        // ТЗ раздел 21 "Сравнить период" — opt-in (?compare=1) since it doubles
        // the kpis()/sales() queries; topics() already always compares internally
        // for its own change_percent/is_new fields, unrelated to this flag.
        $compare = $request->boolean('compare');
        $previousStart = $previousEnd = null;

        if ($compare) {
            [$previousStart, $previousEnd] = $this->range->previousPeriod($start, $end);
        }

        return response()->json([
            'range' => ['start' => $start->toIso8601String(), 'end' => $end->toIso8601String()],
            'previous_range' => $compare ? ['start' => $previousStart->toIso8601String(), 'end' => $previousEnd->toIso8601String()] : null,
            'raw' => $this->raw($start, $end),
            'kpis' => $this->snapshot->kpis($start, $end),
            'previous_kpis' => $compare ? $this->snapshot->kpis($previousStart, $previousEnd) : null,
            'message_trend' => $this->messageTrend($start, $end, $bucket),
            'leads_funnel' => $this->leadsFunnel(),
            'ai_performance' => $this->aiPerformance($start, $end),
            'llm_usage' => $this->llmUsage($start, $end),
            'sales' => $this->snapshot->sales($start, $end, $bucket),
            'previous_sales' => $compare ? $this->snapshot->sales($previousStart, $previousEnd, $bucket) : null,
            'sla' => $this->sla($start, $end),
            'outcomes' => $this->snapshot->outcomes($start, $end),
            'sentiment' => $this->snapshot->sentimentBreakdown($start, $end),
            'dissatisfied_customers' => $this->dissatisfiedCustomers($start, $end),
            'topics' => $this->snapshot->topics($start, $end),
            'operators' => $this->operators($start, $end),
            'lost_customers' => $this->lostCustomers(),
            'conversation_funnel' => $this->snapshot->conversationFunnel($start, $end),
        ]);
    }

    /**
     * ТЗ раздел 24 — реальный Excel-экспорт (не CSV/скриншот, см.
     * AnalyticsExportMenu.vue). Reuses the exact same numbers index() returns
     * (same AnalyticsSnapshot calls), just laid out across sheets instead of
     * one JSON blob -- never a second, divergent set of queries.
     */
    public function exportXlsx(Request $request): StreamedResponse
    {
        [$start, $end, $bucket] = $this->range->resolve($request);

        $kpis = $this->snapshot->kpis($start, $end);
        $funnel = $this->snapshot->conversationFunnel($start, $end);
        $topics = $this->snapshot->topics($start, $end);
        $outcomes = $this->snapshot->outcomes($start, $end);
        $sentiment = $this->snapshot->sentimentBreakdown($start, $end);
        $operators = $this->operators($start, $end);

        $spreadsheet = new Spreadsheet();

        $kpiSheet = $spreadsheet->getActiveSheet();
        $kpiSheet->setTitle('KPI');
        $kpiSheet->fromArray(['Период', $start->toDateString().' — '.$end->toDateString()], null, 'A1');
        $kpiLabels = $this->kpiLabels();
        $row = 3;
        foreach ($kpiLabels as $key => $label) {
            $kpiSheet->setCellValue("A{$row}", $label);
            $kpiSheet->setCellValue("B{$row}", $kpis[$key] ?? '');
            $row++;
        }
        $kpiSheet->getColumnDimension('A')->setWidth(36);

        $funnelSheet = $spreadsheet->createSheet();
        $funnelSheet->setTitle('Воронка');
        $funnelSheet->fromArray(['Стадия', 'Количество', '% от начала'], null, 'A1');
        $funnelSheet->fromArray(array_map(fn (array $s) => [$s['label'], $s['count'], $s['percent_of_total']], $funnel), null, 'A2');
        $funnelSheet->getColumnDimension('A')->setWidth(30);

        $topicsSheet = $spreadsheet->createSheet();
        $topicsSheet->setTitle('Темы обращений');
        $topicsSheet->fromArray(['Тема', 'Количество', '%', 'Изменение, %', 'Новая'], null, 'A1');
        $topicsSheet->fromArray(array_map(fn (array $t) => [$t['topic'], $t['count'], $t['percent'], $t['change_percent'], $t['is_new'] ? 'да' : ''], $topics), null, 'A2');
        $topicsSheet->getColumnDimension('A')->setWidth(24);

        $outcomesSheet = $spreadsheet->createSheet();
        $outcomesSheet->setTitle('Результаты диалогов');
        $outcomesSheet->fromArray(['Результат', 'Количество', '%'], null, 'A1');
        $outcomesSheet->fromArray(array_map(fn (array $o) => [$o['outcome'], $o['count'], $o['percent']], $outcomes), null, 'A2');
        $outcomesSheet->getColumnDimension('A')->setWidth(26);

        $sentimentSheet = $spreadsheet->createSheet();
        $sentimentSheet->setTitle('Настроение');
        $sentimentSheet->fromArray(['Настроение', 'Количество', '%'], null, 'A1');
        $sentimentSheet->fromArray(array_map(fn (array $s) => [$s['sentiment'], $s['count'], $s['percent']], $sentiment), null, 'A2');
        $sentimentSheet->getColumnDimension('A')->setWidth(20);

        $operatorsSheet = $spreadsheet->createSheet();
        $operatorsSheet->setTitle('Операторы');
        $operatorsSheet->fromArray(['Оператор', 'Диалогов', 'Закрыто', 'Средняя оценка', 'Недовольных'], null, 'A1');
        $operatorsSheet->fromArray(array_map(fn (array $o) => [$o['name'], $o['conversations'], $o['closed'], $o['avg_quality_score'], $o['unhappy_count']], $operators), null, 'A2');
        $operatorsSheet->getColumnDimension('A')->setWidth(24);

        $spreadsheet->setActiveSheetIndex(0);

        $filename = 'analytics-'.$start->toDateString().'-'.$end->toDateString().'.xlsx';

        return new StreamedResponse(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /** Shared between exportXlsx() and exportPdf() so the two exports can never list different KPIs. */
    private function kpiLabels(): array
    {
        return [
            'messages' => 'Сообщений', 'conversations' => 'Обращений', 'unique_customers' => 'Уникальных клиентов',
            'new_customers' => 'Новых клиентов', 'repeat_customers' => 'Повторных клиентов', 'total_leads' => 'Всего лидов',
            'conversion_rate' => 'Конверсия, %', 'ai_runs' => 'Запусков AI', 'avg_confidence' => 'Средняя уверенность AI, %',
            'avg_latency_ms' => 'Среднее время ответа AI, мс', 'ai_replacement_rate' => 'AI решил самостоятельно, %',
            'handed_to_operator' => 'Передано оператору', 'fully_ai_handled' => 'Полностью обработано AI',
            'avg_messages_per_conversation' => 'Сообщений на диалог', 'active_conversations' => 'Активных диалогов сейчас',
        ];
    }

    /**
     * ТЗ раздел 24 — the other half of the real report export, same
     * AnalyticsSnapshot calls as index()/exportXlsx() rendered as PDF via
     * barryvdh/laravel-dompdf (no PDF-generation library existed in this
     * codebase before -- smalot/pdfparser, already present, only *reads*
     * PDFs, for knowledge-base uploads).
     */
    public function exportPdf(Request $request): Response
    {
        [$start, $end] = $this->range->resolve($request);

        $html = view('pdf.analytics-report', [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'kpis' => $this->snapshot->kpis($start, $end),
            'kpiLabels' => $this->kpiLabels(),
            'funnel' => $this->snapshot->conversationFunnel($start, $end),
            'topics' => $this->snapshot->topics($start, $end),
            'outcomes' => $this->snapshot->outcomes($start, $end),
            'sentiment' => $this->snapshot->sentimentBreakdown($start, $end),
            'operators' => $this->operators($start, $end),
        ])->render();

        $filename = 'analytics-'.$start->toDateString().'-'.$end->toDateString().'.pdf';

        return Pdf::loadHTML($html)->download($filename);
    }

    /** ТЗ раздел 9 — tenant-scoped counterpart of SuperAdminInsightsController::knowledgeGaps() (that one is platform-wide, Super Admin only). */
    public function knowledgeGaps(Request $request): JsonResponse
    {
        [$start, $end] = $this->range->resolve($request);

        $gaps = KnowledgeGap::query()
            ->whereBetween('created_at', [$start, $end])
            ->orderByDesc('created_at')
            ->limit(200)
            ->get(['id', 'conversation_id', 'customer_message', 'created_at']);

        return response()->json(['data' => $gaps, 'total' => $gaps->count()]);
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
                ->get(['id', 'ai_agent_id', 'conversation_id', 'lead_id', 'status', 'confidence', 'intent', 'summary', 'next_action', 'finished_at', 'payload']),
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
            'ai_replacement_rate' => $this->snapshot->replacementRate($runs),
        ];
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

    /**
     * ЭТАП 13.6 — real measured numbers only, no invented SLA target/threshold.
     * "Time to first response" is approximated as first_response_at minus the
     * conversation's own created_at (its earliest customer contact) — close
     * enough without a dedicated "which customer message started the clock"
     * field, which doesn't exist and isn't worth a new column for this.
     */
    private function sla(Carbon $start, Carbon $end): array
    {
        $responded = Conversation::query()
            ->whereNotNull('first_response_at')
            ->whereBetween('first_response_at', [$start, $end])
            ->selectRaw('avg(extract(epoch from (first_response_at - created_at))) as avg_seconds')
            ->value('avg_seconds');

        $resolved = Conversation::query()
            ->whereNotNull('resolved_at')
            ->whereBetween('resolved_at', [$start, $end]);

        $avgResolutionSeconds = (clone $resolved)
            ->selectRaw('avg(extract(epoch from (resolved_at - created_at))) as avg_seconds')
            ->value('avg_seconds');

        return [
            'avg_first_response_minutes' => $responded !== null ? round($responded / 60, 1) : null,
            'avg_resolution_hours' => $avgResolutionSeconds !== null ? round($avgResolutionSeconds / 3600, 1) : null,
            'resolved_count' => (clone $resolved)->count(),
        ];
    }

    /** ТЗ раздел 6 — «Клиенты, требующие внимания». */
    private function dissatisfiedCustomers(Carbon $start, Carbon $end): array
    {
        return ConversationAnalysis::query()
            ->whereBetween('analyzed_at', [$start, $end])
            ->where(function (Builder $q): void {
                $q->whereIn('sentiment', ConversationAnalysis::UNHAPPY_SENTIMENTS)
                    ->orWhereIn('outcome', ConversationAnalysis::UNHAPPY_OUTCOMES);
            })
            ->with([
                'conversation:id,customer_id,channel_id,assigned_user_id,status,last_message_at',
                'conversation.customer:id,name,phone',
                'conversation.assignedUser:id,name',
                'conversation.channel:id,provider',
            ])
            ->orderByDesc('analyzed_at')
            ->limit(100)
            ->get()
            ->map(fn (ConversationAnalysis $analysis): array => [
                'conversation_id' => $analysis->conversation_id,
                'customer_name' => $analysis->conversation?->customer?->name,
                'customer_phone' => $analysis->conversation?->customer?->phone,
                'channel' => $analysis->conversation?->channel?->provider,
                'date' => $analysis->analyzed_at?->toIso8601String(),
                'last_message_at' => $analysis->conversation?->last_message_at?->toIso8601String(),
                'reason' => $analysis->unhappy_reason,
                'summary' => $analysis->summary,
                'status' => $analysis->conversation?->status,
                'assigned_user_id' => $analysis->conversation?->assigned_user_id,
                'assigned_user_name' => $analysis->conversation?->assignedUser?->name,
                'sentiment' => $analysis->sentiment,
                'outcome' => $analysis->outcome,
            ])
            ->values()
            ->all();
    }

    /**
     * ТЗ раздел 11 — «Потерянные потенциальные клиенты», tenant-scoped
     * counterpart of SuperAdminInsightsController::lostLeads() (that one is
     * platform-wide, Super Admin only). Same real data both read: Lead.status
     * = 'lost' and whatever free-text lost_reason an operator recorded --
     * no qualitative auto-detection of specific loss scenarios (price-then-
     * silence, chose a competitor, etc.) exists anywhere in this codebase, so
     * this deliberately doesn't invent one; the leadsFunnel()'s own 'lost'
     * count already covers the plain total, this adds reason breakdown + who.
     */
    private function lostCustomers(): array
    {
        $lost = Lead::query()->where('status', 'lost');
        $total = (clone $lost)->count();

        $byReason = (clone $lost)
            ->selectRaw("coalesce(nullif(lost_reason, ''), 'Не указано') as reason, count(*) as total")
            ->groupBy('reason')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row): array => [
                'reason' => $row->reason,
                'total' => (int) $row->total,
                'percent' => $total > 0 ? round(((int) $row->total / $total) * 100) : 0,
            ]);

        $recent = (clone $lost)
            ->with('customer:id,name,phone')
            ->latest('updated_at')
            ->limit(50)
            ->get()
            ->map(fn (Lead $lead): array => [
                'id' => $lead->id,
                'title' => $lead->title,
                'customer_name' => $lead->customer?->name,
                'customer_phone' => $lead->customer?->phone,
                'amount' => $lead->amount,
                'lost_reason' => $lead->lost_reason,
                'updated_at' => $lead->updated_at?->toIso8601String(),
            ]);

        return ['total' => $total, 'by_reason' => $byReason->values(), 'recent' => $recent];
    }

    /** ТЗ раздел 12 — статистика по операторам (диалоги с назначенным сотрудником). */
    private function operators(Carbon $start, Carbon $end): array
    {
        $conversations = Conversation::query()
            ->whereNotNull('assigned_user_id')
            ->whereBetween('last_message_at', [$start, $end])
            ->get(['id', 'assigned_user_id', 'status']);

        if ($conversations->isEmpty()) {
            return [];
        }

        $analysisByConversation = ConversationAnalysis::query()
            ->whereIn('conversation_id', $conversations->pluck('id'))
            ->get(['conversation_id', 'quality_score', 'outcome', 'sentiment'])
            ->keyBy('conversation_id');

        $userIds = $conversations->pluck('assigned_user_id')->unique()->values();
        $users = User::query()->whereIn('id', $userIds)->get(['id', 'name'])->keyBy('id');

        return $conversations->groupBy('assigned_user_id')->map(function ($group, $userId) use ($analysisByConversation, $users): array {
            $scores = $group->map(fn (Conversation $c) => $analysisByConversation[$c->id]->quality_score ?? null)->filter(fn ($v) => $v !== null);
            $unhappyCount = $group->filter(fn (Conversation $c) => in_array($analysisByConversation[$c->id]->sentiment ?? null, ConversationAnalysis::UNHAPPY_SENTIMENTS, true))->count();

            return [
                'user_id' => (int) $userId,
                'name' => $users[$userId]->name ?? '—',
                'conversations' => $group->count(),
                'closed' => $group->where('status', 'closed')->count(),
                'avg_quality_score' => $scores->count() > 0 ? round($scores->avg(), 1) : null,
                'unhappy_count' => $unhappyCount,
            ];
        })->values()->all();
    }
}
