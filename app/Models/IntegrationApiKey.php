<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ТЗ раздел 9 -- "Интеграция с CRM / 1С / складом". Unlike every credential
 * App\Support\Integrations\TenantIntegrationSettings handles (a secret WE
 * hold to call OUT to an external service), this is the inverse: a
 * credential WE issue so an external system (1C, a warehouse manager, any
 * REST-capable ERP) can call INTO the tenant-scoped `/api/erp/*` surface.
 * Only `token_hash` (sha256) is ever stored -- the real token is shown once
 * at generation time (see IntegrationApiKeyService::generate()) and is
 * unrecoverable after that, same discipline as GitHub/Sanctum-style API
 * tokens.
 */
#[Fillable(['tenant_id', 'company_id', 'name', 'token_hash', 'last_used_at', 'is_active', 'created_by_user_id'])]
class IntegrationApiKey extends Model
{
    use BelongsToTenant;

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'is_active' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
