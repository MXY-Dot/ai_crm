<?php

namespace App\Console\Commands;

use App\Jobs\NotifyConversationEventJob;
use App\Models\Conversation;
use App\Models\Message;
use App\Support\Inbox\ConversationStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * ТЗ раздел 15 — "клиент очень долго ждёт ответа". Distinct from
 * NotifyIdleOperatorConversationsCommand's 'operator_idle' (which only
 * covers conversations already escalated to pending_operator): this catches
 * a customer's message sitting unanswered in a still-'open' conversation --
 * the case where the AI pipeline itself stalled (a failed/stuck queue job)
 * rather than a human being slow. Runs every 5 minutes, same dedup-via-label
 * pattern as the other triggers here.
 */
class NotifyWaitingTooLongConversationsCommand extends Command
{
    private const IDLE_MINUTES = 10;

    private const MAX_PER_RUN = 200;

    protected $signature = 'conversations:notify-waiting-too-long';

    protected $description = 'Notifies staff about open conversations where the customer sent a message and got no reply at all for too long.';

    public function handle(): int
    {
        $cutoff = Carbon::now()->subMinutes(self::IDLE_MINUTES);

        $conversations = Conversation::withoutGlobalScopes()
            ->where('status', ConversationStatus::OPEN)
            ->where('last_message_at', '<=', $cutoff)
            ->limit(self::MAX_PER_RUN)
            ->get();

        $notified = 0;

        foreach ($conversations as $conversation) {
            if (in_array('waiting_too_long', $conversation->labels ?? [], true)) {
                continue;
            }

            $lastMessage = Message::withoutGlobalScopes()
                ->where('conversation_id', $conversation->id)
                ->orderByDesc('sent_at')
                ->first(['sender_type']);

            if (! $lastMessage || $lastMessage->sender_type !== 'customer') {
                continue;
            }

            $conversation->addLabel('waiting_too_long');
            $conversation->save();

            NotifyConversationEventJob::dispatch($conversation->tenant_id, $conversation->id, 'waiting_too_long');
            $notified++;
        }

        if ($notified > 0) {
            $this->info("Notified {$notified} conversation(s) waiting too long.");
        }

        return self::SUCCESS;
    }
}
