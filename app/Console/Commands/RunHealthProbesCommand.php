<?php

namespace App\Console\Commands;

use App\Support\Emergency\ActiveHealthProbe;
use Illuminate\Console\Command;

class RunHealthProbesCommand extends Command
{
    protected $signature = 'emergency:probe';

    protected $description = 'Run scheduled health probes for LLM providers, Dify recovery, database, queue, and Telegram channels (ЭТАП 16.1/16.17, 2.6).';

    public function handle(ActiveHealthProbe $probe): int
    {
        $probe->probeDatabase();
        $probe->probeQueue();
        $probe->probeLlmProviders();
        $probe->probeDifyRecovery();
        $probe->probeTelegramChannels();

        return self::SUCCESS;
    }
}
