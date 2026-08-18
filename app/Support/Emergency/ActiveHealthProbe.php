<?php

namespace App\Support\Emergency;

use App\Jobs\HealthHeartbeatJob;
use App\Models\Channel;
use App\Models\HealthComponent;
use App\Models\Tenant;
use App\Support\Ai\DifyClient;
use App\Support\Ai\LlmClient;
use App\Support\Integrations\PlatformSettings;
use App\Support\TelegramClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Scheduled proactive checks (ЭТАП 16.1 quiet-period coverage, 16.17 safe
 * recovery) — run every 2 minutes by RunHealthProbesCommand regardless of real
 * customer traffic, so a dead key or downed dependency is caught even overnight.
 * Also the ONLY way an already-open circuit (HealthMonitor::isOpen()) can ever
 * close again, since isOpen() blocks organic traffic from reaching a down
 * provider in the first place.
 */
class ActiveHealthProbe
{
    private const QUEUE_STALE_AFTER_MINUTES = 5;

    public function __construct(
        private readonly HealthMonitor $health,
        private readonly LlmClient $llm,
        private readonly DifyClient $dify,
        private readonly PlatformSettings $platform,
        private readonly TelegramClient $telegram,
    ) {
    }

    public function probeDatabase(): void
    {
        try {
            DB::select('select 1');
            $this->health->recordSuccess('db', null);
        } catch (Throwable $error) {
            $this->health->recordFailure('db', null, 'query_failed', $error->getMessage());
        }
    }

    public function probeQueue(): void
    {
        $lastBeat = Cache::get('health:queue:last_processed_at');

        if ($lastBeat === null) {
            // No heartbeat recorded yet (fresh install) — dispatch one and wait for
            // the next tick rather than reporting a false failure immediately.
            HealthHeartbeatJob::dispatch();

            return;
        }

        $staleMinutes = CarbonImmutable::parse($lastBeat)->diffInMinutes(now());

        if ($staleMinutes > self::QUEUE_STALE_AFTER_MINUTES) {
            $this->health->recordFailure('queue', null, 'stale_queue', "Last heartbeat {$staleMinutes} minutes ago");
        } else {
            $this->health->recordSuccess('queue', null);
        }

        HealthHeartbeatJob::dispatch();
    }

    /**
     * Every platform-configured LLM provider, every tick — cheap (just lists
     * models, doesn't spend a completion) and catches degradation before real
     * customer traffic does. Unconfigured providers are silently skipped, not
     * treated as failures.
     */
    public function probeLlmProviders(): void
    {
        foreach (['openai', 'anthropic', 'google', 'deepseek', 'groq'] as $provider) {
            $apiKey = $this->platform->llmApiKey($provider);

            if ($apiKey === '') {
                continue;
            }

            try {
                $this->llm->listModels(null, $provider, $apiKey);
                $this->health->recordSuccess('llm:'.$provider, null);
            } catch (RuntimeException $error) {
                $this->health->recordFailure('llm:'.$provider, null, 'probe_failed', $error->getMessage());
            }
        }
    }

    /**
     * Bounded to tenants whose Dify is currently marked down — proactively
     * polling every tenant's Dify instance every 2 minutes regardless of state
     * would be one HTTP call per tenant per tick; only the (small, in practice)
     * set of currently-broken ones need a recovery test call.
     */
    public function probeDifyRecovery(): void
    {
        $downTenantIds = HealthComponent::query()
            ->where('component', 'like', 'dify:%')
            ->where('status', 'down')
            ->whereNotNull('tenant_id')
            ->pluck('tenant_id');

        foreach ($downTenantIds as $tenantId) {
            $tenant = Tenant::query()->find($tenantId);

            if (! $tenant) {
                continue;
            }

            if ($this->dify->ping($tenant)) {
                $this->health->recordSuccess('dify:'.$tenant->id, $tenant->id);
            } else {
                $this->health->recordFailure('dify:'.$tenant->id, $tenant->id, 'probe_failed');
            }
        }
    }

    /**
     * ЭТАП 2.6 — Integration Health for channels. Every tick, for every tenant
     * with a Telegram channel already marked connected, calls getMe() (the same
     * cheap call IntegrationSettingsController::testTelegram() already uses to
     * validate a token) — catches a revoked/expired bot token or an
     * api.telegram.org outage even overnight, same as probeLlmProviders() does
     * for LLM providers. WhatsApp/Instagram/Facebook aren't probed here — they
     * have no direct API key WERO holds, only Chatwoot-mediated webhooks (see
     * wero_pending_tasks.md's Stage 2 entry); Website is WERO's own server and
     * always "connected" by definition.
     */
    public function probeTelegramChannels(): void
    {
        $tenantIds = Channel::withoutGlobalScopes()
            ->where('provider', 'telegram')
            ->where('status', 'connected')
            ->distinct()
            ->pluck('tenant_id');

        foreach ($tenantIds as $tenantId) {
            $tenant = Tenant::query()->find($tenantId);

            if (! $tenant) {
                continue;
            }

            try {
                $this->telegram->getMe($tenant);
                $this->health->recordSuccess('telegram:'.$tenant->id, $tenant->id);
            } catch (RuntimeException $error) {
                $this->health->recordFailure('telegram:'.$tenant->id, $tenant->id, 'probe_failed', $error->getMessage());
            }
        }
    }
}
