<?php

namespace App\Console\Commands;

use App\Support\Emergency\ActiveHealthProbe;
use Illuminate\Console\Command;

class RunHealthProbesCommand extends Command
{
    protected $signature = 'emergency:probe';

    protected $description = 'Run scheduled health probes for LLM providers, Dify recovery, database, and queue (ЭТАП 16.1/16.17).';

    public function handle(ActiveHealthProbe $probe): int
    {
        $probe->probeDatabase();
        $probe->probeQueue();
        $probe->probeLlmProviders();
        $probe->probeDifyRecovery();

        return self::SUCCESS;
    }
}
