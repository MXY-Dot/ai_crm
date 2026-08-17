<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\Vip\VipScoreCalculator;
use Illuminate\Console\Command;

class RecalculateVipScoresCommand extends Command
{
    protected $signature = 'vip:recalculate';

    protected $description = 'Recalculate VIP score/status for every customer of every tenant (ЭТАП 12.2) — backfill plus a periodic safety net.';

    public function handle(VipScoreCalculator $calculator): int
    {
        $total = 0;

        Tenant::query()->each(function (Tenant $tenant) use ($calculator, &$total): void {
            $count = $calculator->recalculateAll($tenant);
            $total += $count;
            $this->line("Tenant {$tenant->id} ({$tenant->name}): {$count} customers recalculated.");
        });

        $this->info("Done. {$total} customers recalculated in total.");

        return self::SUCCESS;
    }
}
