<?php

namespace App\Support\Emergency;

use App\Jobs\SendEmergencyAlertJob;
use App\Jobs\SendEmergencyRecoveryJob;
use App\Models\Tenant;
use App\Models\TenantAiStatus;
use Illuminate\Support\Facades\DB;

/**
 * Tenant-level emergency mode (ЭТАП 16.3) — separate from HealthMonitor's
 * component-level circuit breakers. A tenant enters emergency mode only when
 * AiWorkflow::decision() genuinely fell all the way through to local-mvp AND the
 * tenant actually has Dify or a direct-LLM provider configured (i.e. this is a
 * real failure, not "never set anything up" — that's a normal state, not an
 * incident). Threshold-gated the same way as HealthMonitor, but tracked
 * independently since a tenant can be "down" (Dify+their model's provider both
 * tripped) while other tenants using a different provider are unaffected.
 */
class EmergencyStateManager
{
    private const FAILURE_THRESHOLD = 2;

    private const RECOVERY_THRESHOLD = 2;

    public function __construct(private readonly IncidentManager $incidents)
    {
    }

    public function recordAiOutcome(Tenant $tenant, string $engine, bool $difyConfigured, bool $llmConfigured): void
    {
        if ($engine === 'local-mvp' && ! $difyConfigured && ! $llmConfigured) {
            // Never configured anything — not an incident, nothing to track.
            return;
        }

        DB::transaction(function () use ($tenant, $engine): void {
            $status = $this->lockedRow($tenant);

            if ($engine === 'local-mvp') {
                $status->forceFill([
                    'consecutive_ai_failures' => $status->consecutive_ai_failures + 1,
                    'consecutive_recoveries' => 0,
                ]);

                if ($status->mode === 'normal' && $status->consecutive_ai_failures >= self::FAILURE_THRESHOLD) {
                    $incident = $this->incidents->open('tenant-ai:'.$tenant->id, $tenant->id, 'ai_chain_down');
                    $status->forceFill([
                        'mode' => 'emergency',
                        'reason' => 'ai_down',
                        'since' => now(),
                        'active_incident_id' => $incident->id,
                    ]);
                    SendEmergencyAlertJob::dispatch($tenant->id, $incident->id);
                } elseif ($status->active_incident_id) {
                    $this->incidents->touch($status->activeIncident);
                }

                $status->save();

                return;
            }

            // A real dify/direct-llm success.
            $status->forceFill(['consecutive_ai_failures' => 0]);

            if ($status->mode === 'emergency' && $status->reason === 'ai_down') {
                $status->forceFill(['consecutive_recoveries' => $status->consecutive_recoveries + 1]);

                if ($status->consecutive_recoveries >= self::RECOVERY_THRESHOLD) {
                    if ($status->active_incident_id) {
                        $incident = $status->activeIncident;
                        $incident && $this->incidents->resolve($incident);
                        SendEmergencyRecoveryJob::dispatch($tenant->id, $status->active_incident_id);
                    }

                    $status->forceFill(['mode' => 'normal', 'reason' => null, 'active_incident_id' => null, 'consecutive_recoveries' => 0]);
                }
            }

            $status->save();
        });
    }

    public function isEmergency(Tenant $tenant): bool
    {
        $status = TenantAiStatus::query()->where('tenant_id', $tenant->id)->first();

        return $status && ($status->manual_override || $status->mode === 'emergency');
    }

    public function currentMode(Tenant $tenant): string
    {
        return $this->isEmergency($tenant) ? 'emergency' : 'normal';
    }

    public function setManualOverride(Tenant $tenant, bool $enabled): void
    {
        DB::transaction(function () use ($tenant, $enabled): void {
            $status = $this->lockedRow($tenant);
            $status->forceFill(['manual_override' => $enabled]);

            if ($enabled && $status->mode === 'normal') {
                $status->forceFill(['reason' => 'manual_override', 'since' => now()]);
            }

            if (! $enabled && $status->mode === 'normal') {
                $status->forceFill(['reason' => null, 'since' => null]);
            }

            $status->save();
        });
    }

    private function lockedRow(Tenant $tenant): TenantAiStatus
    {
        TenantAiStatus::query()->firstOrCreate(['tenant_id' => $tenant->id]);

        return TenantAiStatus::query()->where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
    }
}
