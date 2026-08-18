<?php

namespace App\Support\Audit;

use App\Models\AuditLog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuditLogger
{
    /**
     * $tenantId lets a Super Admin action (acting user has no tenant of
     * their own — TenantContext is unbound) still record which tenant it
     * targeted (ЭТАП 10.4), e.g. `$audit->record(..., tenantId: $tenant->id)`.
     * Every other caller omits it and keeps the prior behavior.
     */
    public function record(string $action, Model|string $entity, array $newValues = [], array $oldValues = [], ?Request $request = null, ?int $tenantId = null): AuditLog
    {
        $entityType = $entity instanceof Model ? $entity::class : $entity;
        $entityId = $entity instanceof Model ? $entity->getKey() : null;

        return AuditLog::query()->create([
            'tenant_id' => $tenantId ?? app(TenantContext::class)->id(),
            'user_id' => Auth::id(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => $oldValues ?: null,
            'new_values' => $newValues ?: null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }
}