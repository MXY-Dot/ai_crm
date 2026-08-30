<?php

namespace App\Support\Analytics;

use App\Models\AiAnalyticsReport;
use App\Models\Company;
use App\Models\CrmTask;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Support\Ai\LlmClient;
use App\Support\Integrations\PlatformSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * "AI-отчёт по аналитике" — a weekly/monthly natural-language summary of the
 * same numbers AnalyticsSnapshot feeds to /analytics, so a business owner
 * gets the story behind the dashboard instead of having to read it
 * themselves. Same primary→backup provider pattern as ConversationAnalyzer
 * (platform-managed keys via PlatformSettings, no tenant-provided key).
 */
class AiReportGenerator
{
    private const MAX_RESPONSE_TOKENS = 900;

    public function __construct(
        private readonly AnalyticsSnapshot $snapshot,
        private readonly LlmClient $llm,
        private readonly PlatformSettings $platform,
    ) {
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public function periodFor(string $periodType): array
    {
        $now = now();

        return match ($periodType) {
            'monthly' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
            default => [$now->copy()->subDays(7)->startOfDay(), $now->copy()->subDay()->endOfDay()],
        };
    }

    public function generateForTenant(Tenant $tenant, string $periodType): AiAnalyticsReport
    {
        [$start, $end] = $this->periodFor($periodType);

        $kpis = $this->snapshot->kpis($start, $end);
        $sales = $this->snapshot->sales($start, $end, 'day');
        $topics = array_slice($this->snapshot->topics($start, $end), 0, 5);
        $outcomes = $this->snapshot->outcomes($start, $end);
        $sentiment = $this->snapshot->sentimentBreakdown($start, $end);

        $snapshot = compact('kpis', 'sales', 'topics', 'outcomes', 'sentiment');

        $result = $this->complete($tenant, $this->buildPrompt($periodType, $start, $end, $snapshot));
        $content = $result['text'] ?? $this->fallbackText($periodType, $start, $end, $kpis, $sales);

        $report = AiAnalyticsReport::query()->create([
            'tenant_id' => $tenant->id,
            'period_type' => $periodType,
            'period_start' => $start,
            'period_end' => $end,
            'content' => $content,
            'snapshot' => $snapshot,
            'generated_by' => $result['model'] ?? null,
        ]);

        $this->notifyStaff($tenant, $report);
        $this->createTasksFromReport($tenant, $report);

        return $report;
    }

    private function complete(Tenant $tenant, array $prompt): ?array
    {
        $provider = $this->platform->primaryLlmProvider();
        $model = $this->platform->defaultModel();
        $result = $this->llm->complete($tenant, $provider, $model, $prompt['system'], $prompt['user'], self::MAX_RESPONSE_TOKENS);

        if ($result === null) {
            $backupProvider = $this->platform->backupLlmProvider();
            if ($backupProvider) {
                $model = $this->platform->defaultModelFor($backupProvider);
                $result = $this->llm->complete($tenant, $backupProvider, $model, $prompt['system'], $prompt['user'], self::MAX_RESPONSE_TOKENS);
            }
        }

        return $result === null ? null : ['text' => $result['text'], 'model' => $model];
    }

    private function buildPrompt(string $periodType, Carbon $start, Carbon $end, array $snapshot): array
    {
        $periodLabel = $periodType === 'monthly' ? 'месяц' : 'неделю';
        $system = <<<PROMPT
Ты — бизнес-аналитик CRM-платформы WERO. Тебе дают агрегированные цифры компании за {$periodLabel} (диалоги, AI, продажи, темы обращений, настроение клиентов). Напиши короткий отчёт владельцу бизнеса на русском языке (без markdown-разметки, обычный связный текст, 4-6 абзацев):
1. Главное за период (2-3 предложения).
2. Что хорошо.
3. На что стоит обратить внимание (проблемы/риски, если есть).
4. 2-3 конкретные рекомендации, что сделать дальше.

Пиши по-деловому, но по-человечески, без канцелярита. Не выдумывай цифры, которых нет во входных данных.
PROMPT;

        $user = sprintf(
            "Период: %s — %s\n\nДанные (JSON):\n%s",
            $start->format('d.m.Y'),
            $end->format('d.m.Y'),
            json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        );

        return ['system' => $system, 'user' => $user];
    }

    /** If every LLM provider is unavailable, still deliver a report — just the raw numbers in plain Russian sentences instead of AI prose. */
    private function fallbackText(string $periodType, Carbon $start, Carbon $end, array $kpis, array $sales): string
    {
        $periodLabel = $periodType === 'monthly' ? 'Месячный' : 'Недельный';

        return sprintf(
            "%s отчёт за %s — %s (сформирован без AI, все провайдеры были недоступны).\n\n".
            "Диалогов: %d, сообщений: %d. AI-запусков: %d, доля решённых без оператора: %s%%.\n".
            "Выручка: %s, сделок выиграно: %d, средний чек: %s.",
            $periodLabel,
            $start->format('d.m.Y'),
            $end->format('d.m.Y'),
            $kpis['conversations'],
            $kpis['messages'],
            $kpis['ai_runs'],
            $kpis['ai_replacement_rate'],
            number_format((float) $sales['total_revenue'], 0, ',', ' '),
            $sales['won_count'],
            number_format((float) $sales['avg_deal_size'], 0, ',', ' '),
        );
    }

    private function notifyStaff(Tenant $tenant, AiAnalyticsReport $report): void
    {
        $periodLabel = $report->period_type === 'monthly' ? 'Месячный' : 'Недельный';
        $title = "{$periodLabel} AI-отчёт готов";
        $body = Str::limit(str_replace("\n", ' ', $report->content), 200);

        $staff = User::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('role', [User::ROLE_OWNER, User::ROLE_MANAGER])
            ->where('status', 'active')
            ->get();

        foreach ($staff as $user) {
            $user->notify(new AppNotification('ai_analytics_report', $title, $body, '/analytics', 'normal'));
        }
    }

    /**
     * A second, small, dedicated LLM call (same primary→backup pattern) reading the
     * already-generated report text and pulling out only genuinely concrete action
     * items -- deliberately separate from the report-writing call above rather than
     * asking one call for both prose and JSON at once, which is a much less reliable
     * way to get either half right. Silent no-op on any failure: a missed task is a
     * soft miss, not a correctness problem worth a fallback path.
     */
    private function createTasksFromReport(Tenant $tenant, AiAnalyticsReport $report): void
    {
        $company = Company::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();

        if (! $company) {
            return;
        }

        // Regenerating the SAME period (the manual "Сформировать сейчас" button can be
        // clicked more than once, or the scheduled command re-run) must not spawn a
        // second batch of tasks -- found live: the LLM words the same underlying
        // recommendation slightly differently each call, so firstOrCreate()'s exact-
        // title match below does NOT catch this on its own; guarding on "is this the
        // first report for this exact period" does.
        $isFirstReportForPeriod = ! AiAnalyticsReport::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('period_type', $report->period_type)
            ->where('period_start', $report->period_start)
            ->where('id', '!=', $report->id)
            ->exists();

        if (! $isFirstReportForPeriod) {
            return;
        }

        foreach ($this->extractTasks($tenant, $report->content) as $item) {
            CrmTask::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'company_id' => $company->id, 'title' => 'Из AI-отчёта: '.$item['title']],
                ['status' => 'open', 'priority' => $item['priority'], 'description' => $item['description']],
            );
        }
    }

    /** @return array<int, array{title: string, description: ?string, priority: string}> */
    private function extractTasks(Tenant $tenant, string $reportText): array
    {
        $system = <<<PROMPT
Прочитай отчёт ниже и определи, есть ли в нём КОНКРЕТНЫЕ действия, которые владельцу бизнеса стоит реально сделать (не общие советы вроде "улучшайте сервис", а что-то конкретное и выполнимое). Если таких нет — верни пустой массив.

Верни СТРОГО валидный JSON-массив (0-3 элемента), без пояснений и markdown-обрамления:
[{"title": "короткое название задачи, до 80 символов", "description": "1-2 предложения, что именно и зачем сделать", "priority": "low" или "normal" или "high"}]
PROMPT;

        $provider = $this->platform->primaryLlmProvider();
        $model = $this->platform->defaultModel();
        $result = $this->llm->complete($tenant, $provider, $model, $system, $reportText, 500);

        if ($result === null) {
            $backupProvider = $this->platform->backupLlmProvider();
            if ($backupProvider) {
                $result = $this->llm->complete($tenant, $backupProvider, $this->platform->defaultModelFor($backupProvider), $system, $reportText, 500);
            }
        }

        if ($result === null) {
            return [];
        }

        $items = $this->parseJsonArray($result['text']);

        if ($items === null) {
            return [];
        }

        return collect($items)
            ->filter(fn ($item) => is_array($item) && is_string($item['title'] ?? null) && trim($item['title']) !== '')
            ->map(fn (array $item): array => [
                'title' => Str::limit(trim($item['title']), 80),
                'description' => is_string($item['description'] ?? null) && trim($item['description']) !== '' ? trim($item['description']) : null,
                'priority' => in_array($item['priority'] ?? null, ['low', 'normal', 'high'], true) ? $item['priority'] : 'normal',
            ])
            ->take(3)
            ->values()
            ->all();
    }

    private function parseJsonArray(string $text): ?array
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text) ?? $text;

        $decoded = json_decode($text, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        if (! preg_match('/\[.*\]/s', $text, $matches)) {
            return null;
        }

        $decoded = json_decode($matches[0], true);

        return is_array($decoded) ? $decoded : null;
    }
}
