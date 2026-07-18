<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenantKey = $request->header('X-Tenant-Id') ?? $request->query('tenant_id');

        if (! $tenantKey) {
            return response()->json(['message' => 'Tenant context is required.'], 422);
        }

        $tenant = Tenant::query()
            ->where('id', $tenantKey)
            ->orWhere('slug', $tenantKey)
            ->firstOrFail();

        app(TenantContext::class)->set($tenant);

        return $next($request);
    }
}