<?php

namespace App\Support\Emergency;

use App\Models\Incident;
use Illuminate\Support\Facades\DB;

/**
 * Owns the persisted incident log (ЭТАП 16.18). HealthMonitor (component-level)
 * and EmergencyStateManager (tenant-level) both call into this rather than
 * writing to the incidents table directly, so "one open incident per component"
 * stays a single invariant enforced in one place.
 */
class IncidentManager
{
    public function open(string $component, ?int $tenantId, string $cause, ?string $detail = null): Incident
    {
        $existing = $this->findOpen($component, $tenantId);

        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($component, $tenantId, $cause, $detail): Incident {
            return Incident::create([
                'component' => $component,
                'tenant_id' => $tenantId,
                'status' => 'open',
                'cause' => $cause,
                'detail' => $detail,
                'started_at' => now(),
                'affected_conversations_count' => 0,
            ]);
        });
    }

    public function touch(Incident $incident): void
    {
        $incident->increment('affected_conversations_count');
    }

    public function resolve(Incident $incident): void
    {
        if ($incident->status === 'resolved') {
            return;
        }

        $incident->forceFill(['status' => 'resolved', 'resolved_at' => now()])->save();
    }

    public function findOpen(string $component, ?int $tenantId): ?Incident
    {
        return Incident::query()
            ->where('component', $component)
            ->where('tenant_id', $tenantId)
            ->where('status', 'open')
            ->latest('started_at')
            ->first();
    }
}
