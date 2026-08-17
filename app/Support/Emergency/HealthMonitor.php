<?php

namespace App\Support\Emergency;

use App\Jobs\SendPlatformAlertJob;
use App\Models\HealthComponent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Real circuit breaker (ЭТАП 16.1/16.2/16.17) for external dependencies called
 * from LlmClient::complete() and DifyClient::decide(): once a component crosses
 * FAILURE_THRESHOLD consecutive failures, isOpen() returns true and callers skip
 * the real HTTP call entirely rather than just logging the failure. It only
 * closes again via SUCCESS_THRESHOLD consecutive successes — which, once open,
 * can only come from ActiveHealthProbe's explicit recovery test calls, since
 * isOpen() itself blocks organic traffic from ever reaching the provider again.
 *
 * component values: 'llm:{provider}' (platform-wide, tenant_id null) or
 * 'dify:{tenantId}' (per-tenant BYOK) or 'db' / 'queue' (platform-wide).
 */
class HealthMonitor
{
    private const FAILURE_THRESHOLD = 3;

    private const SUCCESS_THRESHOLD = 2;

    public function __construct(private readonly IncidentManager $incidents)
    {
    }

    public function recordFailure(string $component, ?int $tenantId, string $cause, ?string $detail = null): void
    {
        DB::transaction(function () use ($component, $tenantId, $cause, $detail): void {
            $row = $this->lockedRow($component, $tenantId);

            $row->forceFill([
                'consecutive_failures' => $row->consecutive_failures + 1,
                'consecutive_successes' => 0,
                'last_failure_at' => now(),
                'last_error' => $detail !== null ? substr($detail, 0, 255) : $cause,
            ]);

            if ($row->status === 'up' && $row->consecutive_failures >= self::FAILURE_THRESHOLD) {
                $incident = $this->incidents->open($component, $tenantId, $cause, $detail);
                $row->forceFill(['status' => 'down', 'open_incident_id' => $incident->id]);

                if ($tenantId === null) {
                    SendPlatformAlertJob::dispatch($component, $incident->id);
                }
            }

            $row->save();
        });
    }

    public function recordSuccess(string $component, ?int $tenantId): void
    {
        DB::transaction(function () use ($component, $tenantId): void {
            $row = $this->lockedRow($component, $tenantId);

            $row->forceFill([
                'consecutive_successes' => $row->consecutive_successes + 1,
                'consecutive_failures' => 0,
                'last_success_at' => now(),
            ]);

            if ($row->status === 'down' && $row->consecutive_successes >= self::SUCCESS_THRESHOLD) {
                if ($row->open_incident_id) {
                    $incident = $row->openIncident;
                    $incident && $this->incidents->resolve($incident);
                }

                $row->forceFill(['status' => 'up', 'open_incident_id' => null]);
            }

            $row->save();
        });
    }

    public function isOpen(string $component, ?int $tenantId = null): bool
    {
        return HealthComponent::query()
            ->where('component', $component)
            ->where('tenant_id', $tenantId)
            ->where('status', 'down')
            ->exists();
    }

    /**
     * @return Collection<int, HealthComponent>
     */
    public function snapshot(): Collection
    {
        return HealthComponent::query()->with('tenant', 'openIncident')->orderBy('component')->get();
    }

    private function lockedRow(string $component, ?int $tenantId): HealthComponent
    {
        HealthComponent::createOrFirst(['component' => $component, 'tenant_id' => $tenantId], ['status' => 'up']);

        return HealthComponent::query()
            ->where('component', $component)
            ->where('tenant_id', $tenantId)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
