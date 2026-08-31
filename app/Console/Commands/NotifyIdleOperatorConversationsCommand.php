<?php

namespace App\Console\Commands;

use App\Jobs\NotifyConversationEventJob;
use App\Models\Conversation;
use App\Support\Inbox\ConversationStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * ТЗ раздел 15 — "оператор долго не отвечает". Runs every 5 minutes; a
 * conversation that's been sitting in pending_operator for >= 15 minutes
 * with no newer message gets flagged once (the 'operator_idle' label is
 * both the visible tag and the dedup marker -- same pattern as
 * AiWorkflow::notifyConversationEvents()'s other triggers), not re-flagged
 * every run while it stays idle.
 */
class NotifyIdleOperatorConversationsCommand extends Command
{
    private const IDLE_MINUTES = 15;

    private const MAX_PER_RUN = 200;

    protected $signature = 'conversations:notify-idle-operator';

    protected $description = 'Notifies staff about conversations waiting on an operator for too long.';

    public function handle(): int
    {
        $cutoff = Carbon::now()->subMinutes(self::IDLE_MINUTES);

        $conversations = Conversation::withoutGlobalScopes()
            ->where('status', ConversationStatus::PENDING_OPERATOR)
            ->where('last_message_at', '<=', $cutoff)
            ->limit(self::MAX_PER_RUN)
            ->get();

        $notified = 0;

        foreach ($conversations as $conversation) {
            if (in_array('operator_idle', $conversation->labels ?? [], true)) {
                continue;
            }

            $conversation->addLabel('operator_idle');
            $conversation->save();

            NotifyConversationEventJob::dispatch($conversation->tenant_id, $conversation->id, 'operator_idle');
            $notified++;
        }

        if ($notified > 0) {
            $this->info("Notified {$notified} idle conversation(s).");
        }

        return self::SUCCESS;
    }
}
