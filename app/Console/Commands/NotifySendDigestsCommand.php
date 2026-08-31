<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use App\Notifications\NotificationDigest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ТЗ раздел 18 — the hourly/daily/weekly counterpart to AppNotification's
 * per-event mail/Telegram send (AppNotification::passesFrequencyGate()
 * suppresses those for any user whose company is set to one of these three
 * frequencies, leaving them database-only until this command bundles and
 * sends them). Runs once per frequency value, scheduled separately for each
 * (see routes/console.php) with its own cadence.
 */
class NotifySendDigestsCommand extends Command
{
    private const FREQUENCIES = ['hourly', 'daily', 'weekly'];

    private const PERIOD_LABELS = ['hourly' => 'последний час', 'daily' => 'сегодня', 'weekly' => 'эту неделю'];

    protected $signature = 'notifications:send-digests {--frequency=hourly : hourly|daily|weekly}';

    protected $description = 'Bundles undigested notifications and sends one mail/Telegram digest per user set to this delivery frequency.';

    public function handle(): int
    {
        $frequency = (string) $this->option('frequency');

        if (! in_array($frequency, self::FREQUENCIES, true)) {
            $this->error("Unknown frequency '{$frequency}', expected one of: ".implode(', ', self::FREQUENCIES));

            return self::FAILURE;
        }

        $tenantIds = Company::withoutGlobalScopes()
            ->get(['tenant_id', 'brand_settings'])
            ->filter(fn (Company $company) => ($company->brand_settings['notifications']['frequency'] ?? 'instant') === $frequency)
            ->pluck('tenant_id');

        if ($tenantIds->isEmpty()) {
            return self::SUCCESS;
        }

        $users = User::withoutGlobalScopes()
            ->whereIn('tenant_id', $tenantIds)
            ->whereIn('role', [User::ROLE_OWNER, User::ROLE_MANAGER])
            ->where('status', 'active')
            ->get();

        $digestsSent = 0;

        foreach ($users as $user) {
            $rows = DB::table('notifications')
                ->where('notifiable_id', $user->id)
                ->where('notifiable_type', User::class)
                ->whereNull('digested_at')
                ->orderBy('created_at')
                ->get();

            if ($rows->isEmpty()) {
                continue;
            }

            // Urgent items already went out instantly (AppNotification::passesFrequencyGate()
            // always lets 'urgent' through) -- included in the digested_at stamp below so they
            // don't linger forever un-digested, but never repeated in the digest body itself.
            $items = $rows
                ->map(fn ($row) => json_decode($row->data, true) ?? [])
                ->filter(fn (array $data) => ($data['priority'] ?? 'normal') !== 'urgent')
                ->map(fn (array $data) => ['title' => $data['title'] ?? '', 'body' => $data['body'] ?? null, 'action_url' => $data['action_url'] ?? null])
                ->values()
                ->all();

            if ($items !== []) {
                $user->notify(new NotificationDigest($items, self::PERIOD_LABELS[$frequency]));
                $digestsSent++;
            }

            DB::table('notifications')->whereIn('id', $rows->pluck('id'))->update(['digested_at' => now()]);
        }

        if ($digestsSent > 0) {
            $this->info("Sent {$digestsSent} {$frequency} digest(s).");
        }

        return self::SUCCESS;
    }
}
