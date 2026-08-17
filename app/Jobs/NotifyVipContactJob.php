<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * ЭТАП 12.2's "VIP пишет в WERO" example — a VIP customer's first message in a
 * conversation gets the owner/managers a heads-up with the numbers that made
 * them VIP, matching the spec's own sample notification text. Queued (same
 * reasoning as SendEmergencyAlertJob) so this never delays the customer's reply.
 */
class NotifyVipContactJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(private readonly int $tenantId, private readonly int $conversationId)
    {
    }

    public function handle(): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        $conversation = Conversation::withoutGlobalScopes()->with('customer', 'channel')->find($this->conversationId);
        $customer = $conversation?->customer;

        if (! $tenant || ! $conversation || ! $customer) {
            return;
        }

        $channelLabel = match ($conversation->channel?->provider) {
            'telegram' => 'Telegram',
            'website' => 'сайт',
            'chatwoot' => 'Chatwoot',
            default => $conversation->channel?->provider ?? 'канал',
        };

        $body = "VIP-клиент {$customer->name} написал в {$channelLabel}. "
            .$customer->purchases_count.' '.$this->pluralizePurchases($customer->purchases_count).', '
            .'оборот '.number_format((float) $customer->total_revenue, 0, ',', ' ').' TJS. Приоритет: высокий.';

        $staff = User::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('role', [User::ROLE_OWNER, User::ROLE_MANAGER])
            ->where('status', 'active')
            ->get();

        foreach ($staff as $user) {
            $user->notify(new AppNotification('vip_contact', 'VIP-клиент на связи', $body, '/inbox'));
        }
    }

    private function pluralizePurchases(int $count): string
    {
        $mod10 = $count % 10;
        $mod100 = $count % 100;

        if ($mod10 === 1 && $mod100 !== 11) {
            return 'покупка';
        }

        if (in_array($mod10, [2, 3, 4], true) && ! in_array($mod100, [12, 13, 14], true)) {
            return 'покупки';
        }

        return 'покупок';
    }
}
