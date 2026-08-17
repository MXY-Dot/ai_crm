<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Proves the queue is actually alive (ЭТАП 16.1's "queue" health row): does
 * nothing but stamp a timestamp. ActiveHealthProbe::probeQueue() dispatches this
 * and separately checks how stale the last stamp is — if this job never runs
 * (workers dead, queue stuck), the timestamp goes stale and the probe reports
 * 'queue' as down. Deliberately not itself checking anything — its only signal is
 * whether it got to run at all.
 */
class HealthHeartbeatJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function handle(): void
    {
        Cache::put('health:queue:last_processed_at', now()->toIso8601String(), 3600);
    }
}
