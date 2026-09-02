<?php

namespace App\Support\Erp;

use App\Models\IntegrationApiKey;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * ТЗ раздел 9 -- issues/revokes the Bearer tokens that authenticate the
 * `/api/erp/*` surface (see AuthenticateErpApiKey middleware). Deliberately
 * no update() beyond revoke -- a key's name/scope never changes after
 * creation, only its active state, same as how a real API-key management
 * screen works.
 */
class IntegrationApiKeyService
{
    /** @return array{key: IntegrationApiKey, token: string} `token` is the ONLY time the real value is ever available -- callers must surface it to the user immediately and never persist it themselves. */
    public function generate(int $tenantId, int $companyId, string $name, ?User $actor): array
    {
        $token = 'wero_erp_'.Str::random(40);

        $key = IntegrationApiKey::create([
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'name' => $name,
            'token_hash' => hash('sha256', $token),
            'is_active' => true,
            'created_by_user_id' => $actor?->id,
        ]);

        return ['key' => $key, 'token' => $token];
    }

    /** Soft-revoke (is_active=false) rather than delete -- keeps the audit trail (created_by/created_at, and whatever last_used_at shows) intact after revocation. */
    public function revoke(IntegrationApiKey $key): IntegrationApiKey
    {
        $key->update(['is_active' => false]);

        return $key->refresh();
    }
}
