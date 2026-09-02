<?php

namespace App\Http\Middleware;

use App\Models\IntegrationApiKey;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ТЗ раздел 9 -- authenticates `/api/erp/*` requests from an external
 * system (1C, a warehouse manager) via `Authorization: Bearer <token>`,
 * mirroring ResolveTenant's own "resolve tenant, set TenantContext, then
 * let the request through" shape -- but the tenant here comes from WHICH
 * key matched, not from a client-supplied X-Tenant-Id header, since the
 * token itself is the only thing this caller can prove.
 */
class AuthenticateErpApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json(['message' => 'Missing API key.'], 401);
        }

        $key = IntegrationApiKey::withoutGlobalScopes()
            ->where('token_hash', hash('sha256', $token))
            ->where('is_active', true)
            ->with('tenant')
            ->first();

        if (! $key || ! $key->tenant) {
            return response()->json(['message' => 'Invalid or revoked API key.'], 401);
        }

        app(TenantContext::class)->set($key->tenant);
        $key->forceFill(['last_used_at' => now()])->save();

        return $next($request);
    }
}
