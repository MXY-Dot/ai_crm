<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Support\Booking\BookingConflictException;
use App\Support\Booking\BookingService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * ТЗ раздел 13 — временная бронь: releases a temp_hold booking whose
 * owner-configured hold window (see CancellationPolicy::hold_minutes, default 15
 * minutes) has passed with no payment action taken. Runs every minute (the hold
 * window is as short as 10 minutes, so an hourly cadence like the reminder
 * commands would be far too coarse); the query itself is a cheap indexed lookup,
 * so per-minute polling costs nothing meaningful even across many tenants.
 */
class ExpireBookingHoldsCommand extends Command
{
    private const MAX_PER_RUN = 500;

    protected $signature = 'bookings:expire-holds';

    protected $description = 'Cancels temp_hold bookings whose hold window has expired with no payment action taken.';

    public function handle(BookingService $bookingService): int
    {
        $expired = Booking::withoutGlobalScopes()
            ->where('status', Booking::STATUS_TEMP_HOLD)
            ->whereNotNull('hold_expires_at')
            ->where('hold_expires_at', '<=', Carbon::now())
            ->limit(self::MAX_PER_RUN)
            ->get();

        $released = 0;

        foreach ($expired as $booking) {
            try {
                $bookingService->cancel($booking, null, 'Автоматически отменена: истекло время удержания.', false);
                $released++;
            } catch (BookingConflictException) {
                // Already moved out of temp_hold (payment action taken) between the query
                // above and this cancel() call -- not an error, just a race we lost, correctly.
            } catch (Throwable $error) {
                $this->warn("Booking {$booking->id}: hold expiry failed — {$error->getMessage()}");
            }
        }

        if ($released > 0) {
            $this->info("Released {$released} expired hold(s).");
        }

        return self::SUCCESS;
    }
}
