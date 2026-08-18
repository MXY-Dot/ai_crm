<?php

namespace App\Console\Commands;

use App\Models\CrmTask;
use App\Models\Lead;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * ЭТАП 17.1/17.2 — Post-Service Follow-up. Deliberately creates a CrmTask for
 * an operator to call the customer and ask how it went, never an autonomous
 * AI message — same WhatsApp/Telegram consent constraint already documented
 * on FollowUpAbandonedConversationsCommand (ЭТАП 13). The operator records
 * the outcome via CustomerFeedbackController, which itself creates a
 * high-priority complaint task on a negative result (ЭТАП 17.3).
 */
class PostServiceFollowUpCommand extends Command
{
    private const DEFAULT_DELAY_HOURS = 36;

    protected $signature = 'customers:post-service-follow-up';

    protected $description = 'Flag customers whose service/deal was recently won, so an operator can call and ask how it went.';

    public function handle(): int
    {
        $total = 0;

        Tenant::query()->each(function (Tenant $tenant) use (&$total): void {
            $hours = (int) Arr::get($tenant->settings ?? [], 'post_service.delay_hours', self::DEFAULT_DELAY_HOURS);
            $cutoff = Carbon::now()->subHours($hours);

            $candidates = Lead::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('status', 'won')
                ->whereNotNull('customer_id')
                ->whereNotNull('won_at')
                ->where('won_at', '<', $cutoff)
                ->get();

            $count = 0;

            foreach ($candidates as $lead) {
                $task = CrmTask::withoutGlobalScopes()->firstOrCreate(
                    ['tenant_id' => $tenant->id, 'company_id' => $lead->company_id, 'lead_id' => $lead->id, 'title' => 'Уточнить впечатления: '.$lead->title],
                    [
                        'customer_id' => $lead->customer_id,
                        'status' => 'open',
                        'priority' => 'low',
                        'description' => sprintf(
                            'Услуга завершена %s. Свяжитесь с клиентом, уточните впечатления и запишите результат через отзыв клиента.',
                            $lead->won_at?->format('d.m.Y H:i')
                        ),
                    ]
                );

                if ($task->wasRecentlyCreated) {
                    $count++;
                }
            }

            $total += $count;
            $this->line("Tenant {$tenant->id} ({$tenant->name}): {$count} post-service follow-up task(s) created.");
        });

        $this->info("Done. {$total} post-service follow-up task(s) created in total.");

        return self::SUCCESS;
    }
}
