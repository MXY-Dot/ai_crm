<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\Analytics\AiReportGenerator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Scheduled weekly (Mondays) / monthly (1st) in routes/console.php. AnalyticsSnapshot's
 * queries rely on the BelongsToTenant global scope (same as AnalyticsController serving
 * a real request), so — unlike SendBookingRemindersCommand's withoutGlobalScopes()+manual
 * tenant_id filter — this sets TenantContext per tenant instead, matching how a real
 * /analytics request would see that tenant's data.
 */
class GenerateAiAnalyticsReportsCommand extends Command
{
    protected $signature = 'analytics:generate-reports {--type=weekly : weekly|monthly}';

    protected $description = 'Generates an AI-written analytics report for every active tenant and notifies owners/managers.';

    public function handle(AiReportGenerator $generator, TenantContext $context): int
    {
        $type = $this->option('type');

        if (! in_array($type, ['weekly', 'monthly'], true)) {
            $this->error("Unknown --type={$type}, expected weekly or monthly.");

            return self::FAILURE;
        }

        $generated = 0;
        $failed = 0;

        Tenant::query()->where('status', 'active')->each(function (Tenant $tenant) use ($generator, $context, $type, &$generated, &$failed): void {
            $context->set($tenant);

            try {
                $generator->generateForTenant($tenant, $type);
                $generated++;
            } catch (Throwable $error) {
                $failed++;
                $this->warn("Tenant {$tenant->id} ({$tenant->name}): report generation failed — {$error->getMessage()}");
            }
        });

        $this->info("Done. {$generated} report(s) generated, {$failed} failed.");

        return self::SUCCESS;
    }
}
