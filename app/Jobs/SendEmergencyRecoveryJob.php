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
 * ЭТАП 16.16 — mirror of SendEmergencyAlertJob's outage notice, sent once
 * EmergencyStateManager has seen enough consecutive real successes to close the
 * tenant's incident (RECOVERY_THRESHOLD in EmergencyStateManager).
 */
class SendEmergencyRecoveryJob implements ShouldQueue
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

        if (! $tenant || ! $incident) {
            return;
        }

        $company = $tenant->companies()->first();
        $duration = $incident->resolved_at && $incident->started_at
            ? $incident->started_at->diffForHumans($incident->resolved_at, true, false, 2)
            : 'неизвестно';
        $chatId = trim((string) Arr::get($tenant->settings ?? [], 'emergency.telegram_chat_id', ''));

        if ($chatId !== '') {
            $text = "✅ WERO AI восстановлен.\n\n"
                ."Компания: {$company?->name}\n"
                ."Продолжительность сбоя: {$duration}\n"
                .'Новые сообщения снова обслуживаются AI.';

            try {
                $telegram->sendMessage($tenant, $chatId, $text);
            } catch (RuntimeException $error) {
                Log::warning('Emergency Telegram recovery notice failed to send', ['tenant_id' => $tenant->id, 'error' => $error->getMessage()]);
            }
        }

        $staff = User::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('role', [User::ROLE_OWNER, User::ROLE_MANAGER])
            ->where('status', 'active')
            ->get();

        foreach ($staff as $user) {
            $user->notify(new AppNotification(
                'emergency_recovery',
                'AI восстановлен',
                "Компания: {$company?->name}. Сбой длился {$duration}. Новые сообщения снова обрабатывает AI.",
                '/inbox',
            ));
        }
    }
}
