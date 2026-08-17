<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Circuit-breaker state — see HealthMonitor. Not tenant-scoped: platform LLM
 * providers have tenant_id null, per-tenant Dify rows carry a real tenant_id, and
 * Super Admin's dashboard needs to read every row regardless of tenant.
 */
#[Fillable(['component', 'tenant_id', 'status', 'consecutive_failures', 'consecutive_successes', 'last_failure_at', 'last_success_at', 'last_error', 'open_incident_id'])]
class HealthComponent extends Model
{
    protected function casts(): array
    {
        return [
            'consecutive_failures' => 'integer',
            'consecutive_successes' => 'integer',
            'last_failure_at' => 'datetime',
            'last_success_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function openIncident(): BelongsTo
    {
        return $this->belongsTo(Incident::class, 'open_incident_id');
    }
}
