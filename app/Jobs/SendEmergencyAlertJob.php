<?php

namespace App\Jobs;

use App\Models\Incident;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Support\TelegramClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * ЭТАП 16.4/16.5/16.6 — notifies a tenant's staff that their AI has genuinely
 * stopped answering. Queued (not called synchronously from EmergencyStateManager)
 * so a slow/failed Telegram call never blocks the customer-facing reply path.
 */
class SendEmergencyAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(private readonly int $tenantId, private readonly int $incidentId)
    {
    }

    public function handle(TelegramClient $telegram): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        $incident = Incident::query()->find($this->incidentId);

        if (! $tenant || ! $incident || $incident->alerted_at !== null) {
            return;
        }

        $company = $tenant->companies()->first();
        $chatId = trim((string) Arr::get($tenant->settings ?? [], 'emergency.telegram_chat_id', ''));

        if ($chatId !== '') {
            $text = "⚠️ WERO AI временно недоступен.\n\n"
                ."Компания: {$company?->name}\n"
                ."Причина: {$incident->cause}\n"
                ."С {$incident->started_at->format('H:i')} AI не отвечает автоматически.\n\n"
                .'Пожалуйста, отвечайте клиентам вручную до восстановления системы.';

            try {
                $telegram->sendMessage($tenant, $chatId, $text);
            } catch (RuntimeException $error) {
                Log::warning('Emergency Telegram alert failed to send', ['tenant_id' => $tenant->id, 'error' => $error->getMessage()]);
            }
        }

        $staff = User::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('role', [User::ROLE_OWNER, User::ROLE_MANAGER])
            ->where('status', 'active')
            ->get();

        foreach ($staff as $user) {
            $user->notify(new AppNotification(
                'emergency_outage',
                'AI временно недоступен',
                "Компания: {$company?->name}. Причина: {$incident->cause}. Новые обращения назначайте себе вручную.",
                '/inbox',
                'urgent',
            ));
        }

        $incident->forceFill(['alerted_at' => now()])->save();
    }
}
