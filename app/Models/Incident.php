<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Deliberately NOT tenant-scoped (no BelongsToTenant) — platform-wide incidents
 * have tenant_id null, and Super Admin needs to read across every tenant's rows.
 */
#[Fillable(['component', 'tenant_id', 'status', 'cause', 'detail', 'started_at', 'resolved_at', 'affected_tenants_count', 'affected_conversations_count', 'alerted_at', 'meta'])]
class Incident extends Model
{
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'resolved_at' => 'datetime',
            'alerted_at' => 'datetime',
            'affected_tenants_count' => 'integer',
            'affected_conversations_count' => 'integer',
            'meta' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
