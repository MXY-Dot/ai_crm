<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Not tenant-scoped via BelongsToTenant on purpose — EmergencyStateManager reads/
 * writes this for whichever tenant an inbound message belongs to, independent of
 * the current request's own tenant context (e.g. queued jobs, scheduled probes).
 */
#[Fillable(['tenant_id', 'mode', 'reason', 'since', 'active_incident_id', 'consecutive_ai_failures', 'consecutive_recoveries', 'manual_override'])]
class TenantAiStatus extends Model
{
    // Eloquent's default pluralization would guess `tenant_ai_statuses` — the
    // actual migration deliberately named it `tenant_ai_status` (singular, one row
    // per tenant reads more naturally that way), so it has to be spelled out here.
    protected $table = 'tenant_ai_status';

    protected function casts(): array
    {
        return [
            'since' => 'datetime',
            'consecutive_ai_failures' => 'integer',
            'consecutive_recoveries' => 'integer',
            'manual_override' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function activeIncident(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'active_incident_id');
    }
}
