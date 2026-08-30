<?php

namespace App\Jobs;

use App\Models\Incident;
use App\Models\User;
use App\Notifications\AppNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * A platform-wide component (an LLM provider, DB, queue) went down — affects
 * every tenant using it, not one. In-app only for v1 (see plan's deferrals list —
 * there's no existing "platform ops chat" concept to hang a Telegram alert on),
 * delivered to every super_admin via the same AppNotification/bell mechanism
 * tenant-level alerts use.
 */
class SendPlatformAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(private readonly string $component, private readonly int $incidentId)
    {
    }

    public function handle(): void
    {
        $incident = Incident::query()->find($this->incidentId);

        if (! $incident || $incident->alerted_at !== null) {
            return;
        }

        $admins = User::query()->where('role', User::ROLE_SUPER_ADMIN)->where('status', 'active')->get();

        foreach ($admins as $admin) {
            $admin->notify(new AppNotification(
                'platform_outage',
                'Платформенный компонент недоступен',
                "Компонент: {$this->component}. Причина: {$incident->cause}.",
                '/super-admin/incidents',
                'urgent',
            ));
        }

        $incident->forceFill(['alerted_at' => now()])->save();
    }
}
