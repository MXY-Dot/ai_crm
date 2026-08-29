<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\ConversationAnalysis;
use App\Models\Message;
use App\Models\Tenant;
use App\Support\Analytics\ConversationAnalyzer;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * ТЗ «Отчётность...» раздел 3 — "После завершения или остановки каждого
 * диалога система должна определить результат." Picks up two kinds of
 * conversations: recently resolved ones, and ones that went quiet (no new
 * message in IDLE_HOURS) without ever being explicitly closed — an abandoned
 * thread is itself a real, analyzable outcome. Capped per tenant per run so a
 * large backlog spreads across several hourly runs instead of one huge bill.
 */
class AnalyzeConversationsCommand extends Command
{
    private const IDLE_HOURS = 2;

    private const RESOLVED_LOOKBACK_HOURS = 26;

    private const MAX_PER_TENANT_PER_RUN = 50;

    protected $signature = 'conversations:analyze';

    protected $description = 'Runs an AI pass over recently resolved or gone-quiet conversations: outcome, quality, sentiment, dissatisfaction reason, recommendation.';

    public function handle(ConversationAnalyzer $analyzer): int
    {
        $total = 0;
        $resolvedCutoff = Carbon::now()->subHours(self::RESOLVED_LOOKBACK_HOURS);
        $idleCutoff = Carbon::now()->subHours(self::IDLE_HOURS);

        Tenant::query()->each(function (Tenant $tenant) use ($analyzer, $resolvedCutoff, $idleCutoff, &$total): void {
            $candidates = Conversation::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where(function ($q) use ($resolvedCutoff, $idleCutoff): void {
                    $q->where('resolved_at', '>=', $resolvedCutoff)
                        ->orWhere(function ($q2) use ($idleCutoff): void {
                            $q2->where('status', '!=', 'closed')->where('last_message_at', '<', $idleCutoff);
                        });
                })
                ->limit(self::MAX_PER_TENANT_PER_RUN)
                ->get();

            $count = 0;

            foreach ($candidates as $conversation) {
                if (! $this->needsAnalysis($conversation)) {
                    continue;
                }

                $messageCount = Message::withoutGlobalScopes()->where('conversation_id', $conversation->id)->count();

                if ($messageCount < 2) {
                    continue;
                }

                try {
                    if ($analyzer->analyze($conversation)) {
                        $count++;
                    }
                } catch (Throwable $error) {
                    $this->warn("Conversation {$conversation->id}: analysis failed — {$error->getMessage()}");
                }
            }

            $total += $count;

            if ($count > 0) {
                $this->line("Tenant {$tenant->id} ({$tenant->name}): {$count} conversation(s) analyzed.");
            }
        });

        $this->info("Done. {$total} conversation(s) analyzed in total.");

        return self::SUCCESS;
    }

    private function needsAnalysis(Conversation $conversation): bool
    {
        $analysis = ConversationAnalysis::withoutGlobalScopes()->where('conversation_id', $conversation->id)->first();

        if (! $analysis || ! $analysis->analyzed_at) {
            return true;
        }

        $lastActivity = $conversation->resolved_at ?? $conversation->last_message_at;

        return ! $lastActivity || $analysis->analyzed_at->lt($lastActivity);
    }
}
