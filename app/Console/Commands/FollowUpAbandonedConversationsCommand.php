<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\CrmTask;
use App\Models\Message;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * ЭТАП 13.1/13.2/13.4 — Follow-up Engine / Abandoned Conversation. Deliberately
 * creates a CrmTask for an operator to review, never sends anything to the
 * customer automatically — WhatsApp/Telegram both restrict unsolicited
 * outbound messages, and nothing in this schema tracks customer consent to
 * receive one, so an automatic send was explicitly ruled out for this round.
 */
class FollowUpAbandonedConversationsCommand extends Command
{
    private const DEFAULT_ABANDONED_HOURS = 48;

    protected $signature = 'conversations:follow-up';

    protected $description = 'Flag conversations where the customer went quiet after our own last message, so an operator can decide whether to follow up.';

    public function handle(): int
    {
        $total = 0;

        Tenant::query()->each(function (Tenant $tenant) use (&$total): void {
            $hours = (int) Arr::get($tenant->settings ?? [], 'follow_up.abandoned_hours', self::DEFAULT_ABANDONED_HOURS);
            $cutoff = Carbon::now()->subHours($hours);

            $candidates = Conversation::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('status', '!=', 'closed')
                ->whereNotNull('lead_id')
                ->where('last_message_at', '<', $cutoff)
                ->get();

            $count = 0;

            foreach ($candidates as $conversation) {
                $lastMessage = Message::withoutGlobalScopes()
                    ->where('conversation_id', $conversation->id)
                    ->latest('sent_at')
                    ->first();

                // We're waiting on the customer, not the other way around — a
                // conversation where WE haven't replied yet is a response-time
                // concern (ЭТАП 13.6/SLA), not an abandoned-conversation one.
                if (! $lastMessage || $lastMessage->sender_type === 'customer') {
                    continue;
                }

                $task = CrmTask::withoutGlobalScopes()->firstOrCreate(
                    ['tenant_id' => $tenant->id, 'company_id' => $conversation->company_id, 'lead_id' => $conversation->lead_id, 'title' => 'Follow-up: '.$conversation->subject],
                    [
                        'status' => 'open',
                        'priority' => 'low',
                        'description' => sprintf(
                            'Клиент не отвечает с %s (%d ч.). Последнее сообщение было от нас — рассмотрите, стоит ли написать ещё раз.',
                            $conversation->last_message_at?->format('d.m.Y H:i'),
                            $conversation->last_message_at ? $conversation->last_message_at->diffInHours(now()) : $hours
                        ),
                    ]
                );

                if ($task->wasRecentlyCreated) {
                    $count++;
                }
            }

            $total += $count;
            $this->line("Tenant {$tenant->id} ({$tenant->name}): {$count} follow-up task(s) created.");
        });

        $this->info("Done. {$total} follow-up task(s) created in total.");

        return self::SUCCESS;
    }
}
