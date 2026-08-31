<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('emergency:probe')->everyTwoMinutes()->withoutOverlapping();
Schedule::command('vip:recalculate')->daily()->withoutOverlapping();
Schedule::command('conversations:follow-up')->hourly()->withoutOverlapping();
Schedule::command('conversations:analyze')->hourly()->withoutOverlapping();
Schedule::command('customers:post-service-follow-up')->daily()->withoutOverlapping();
Schedule::command('bookings:send-reminders')->hourly()->withoutOverlapping();
Schedule::command('bookings:send-reminders-3h')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('bookings:expire-holds')->everyMinute()->withoutOverlapping();
Schedule::command('conversations:notify-idle-operator')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('conversations:notify-waiting-too-long')->everyFiveMinutes()->withoutOverlapping();
// 04:00 UTC ≈ 09:00 in Tajikistan (UTC+5) — start of the business day, so the
// report is waiting when the owner opens WERO, not generated mid-shift.
Schedule::command('analytics:generate-reports --type=weekly')->weeklyOn(1, '04:00')->withoutOverlapping();
Schedule::command('analytics:generate-reports --type=monthly')->monthlyOn(1, '04:30')->withoutOverlapping();
// 22:30 UTC ≈ 03:30 in Tajikistan (UTC+5) — off-peak for the target market.
Schedule::command('db:backup')->dailyAt('22:30')->withoutOverlapping();
