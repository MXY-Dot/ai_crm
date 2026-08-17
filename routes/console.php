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
