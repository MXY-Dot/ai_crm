<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Tenant;
use App\Support\Booking\BookingReminderSender;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Runs hourly; the 2-hour lookahead window (23-25h out) means a booking
 * starting at any given hour gets caught on some run within that window —
 * the reminder_sent_at guard (see BookingReminderSender::send()) stops it
 * being sent twice across overlapping runs.
 */
class SendBookingRemindersCommand extends Command
{
    private const MAX_PER_TENANT_PER_RUN = 200;

    protected $signature = 'bookings:send-reminders';

    protected $description = 'Sends a reminder message to customers whose confirmed booking starts in ~24 hours.';

    public function handle(BookingReminderSender $sender): int
    {
        $hours = BookingReminderSender::REMINDER_HOURS_BEFORE;
        $windowStart = Carbon::now()->addHours($hours - 1);
        $windowEnd = Carbon::now()->addHours($hours + 1);

        $sent = 0;
        $skipped = 0;

        Tenant::query()->each(function (Tenant $tenant) use ($sender, $windowStart, $windowEnd, &$sent, &$skipped): void {
            $bookings = Booking::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('status', Booking::STATUS_CONFIRMED)
                ->whereNull('reminder_sent_at')
                ->whereBetween('starts_at', [$windowStart, $windowEnd])
                ->limit(self::MAX_PER_TENANT_PER_RUN)
                ->get();

            $count = 0;

            foreach ($bookings as $booking) {
                try {
                    if ($sender->send($tenant, $booking)) {
                        $count++;
                    } else {
                        $skipped++;
                    }
                } catch (Throwable $error) {
                    $skipped++;
                    $this->warn("Booking {$booking->id}: reminder failed — {$error->getMessage()}");
                }
            }

            $sent += $count;

            if ($count > 0) {
                $this->line("Tenant {$tenant->id} ({$tenant->name}): {$count} reminder(s) sent.");
            }
        });

        $this->info("Done. {$sent} reminder(s) sent, {$skipped} skipped (no channel or send failure).");

        return self::SUCCESS;
    }
}
