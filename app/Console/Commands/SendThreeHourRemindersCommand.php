<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Tenant;
use App\Support\Booking\BookingReminderSender;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * ТЗ раздел 20 — second reminder, ~3 hours before the visit, alongside the
 * pre-existing 24h one (SendBookingRemindersCommand). Runs every 15 minutes; a
 * narrower ±20-minute window than the 24h job's ±1 hour, since "3 hours before"
 * is a same-day nudge where precision matters more. Dedup rides on
 * BookingReminderSender::sendOnce()'s reminders_sent JSON marker, not a
 * dedicated column, so overlapping runs are naturally safe.
 */
class SendThreeHourRemindersCommand extends Command
{
    private const MAX_PER_TENANT_PER_RUN = 200;

    protected $signature = 'bookings:send-reminders-3h';

    protected $description = 'Sends a reminder message to customers whose confirmed booking starts in ~3 hours.';

    public function handle(BookingReminderSender $sender): int
    {
        $windowStart = Carbon::now()->addMinutes(160);
        $windowEnd = Carbon::now()->addMinutes(200);

        $sent = 0;

        Tenant::query()->each(function (Tenant $tenant) use ($sender, $windowStart, $windowEnd, &$sent): void {
            $bookings = Booking::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('status', Booking::STATUS_CONFIRMED)
                ->whereBetween('starts_at', [$windowStart, $windowEnd])
                ->limit(self::MAX_PER_TENANT_PER_RUN)
                ->get();

            $count = 0;

            foreach ($bookings as $booking) {
                try {
                    if ($sender->send3HoursBefore($tenant, $booking)) {
                        $count++;
                    }
                } catch (Throwable $error) {
                    $this->warn("Booking {$booking->id}: 3h reminder failed — {$error->getMessage()}");
                }
            }

            $sent += $count;

            if ($count > 0) {
                $this->line("Tenant {$tenant->id} ({$tenant->name}): {$count} reminder(s) sent.");
            }
        });

        $this->info("Done. {$sent} reminder(s) sent.");

        return self::SUCCESS;
    }
}
