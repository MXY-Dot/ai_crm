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
Schedule::command('customers:post-service-follow-up')->daily()->withoutOverlapping();
// 22:30 UTC ≈ 03:30 in Tajikistan (UTC+5) — off-peak for the target market.
Schedule::command('db:backup')->dailyAt('22:30')->withoutOverlapping();
