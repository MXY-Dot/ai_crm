<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * A self-registered company defaults to tenant status "inactive" (see
 * SessionController::signup()) until a moderator manually reviews it and
 * flips it to "active" in Super Admin. Any status other than active/trial
 * blocks the whole dashboard — renders a pending-review page instead.
 * Super admins have no tenant_id and are never gated here.
 */
class EnsureTenantActive
{
    private const ALLOWED = ['active', 'trial'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->isSuperAdmin() || ! $user->tenant_id) {
            return $next($request);
        }

        $status = Tenant::query()->where('id', $user->tenant_id)->value('status');

        if ($status !== null && ! in_array($status, self::ALLOWED, true)) {
            return Inertia::render('PendingReviewPage', [
                'status' => $status,
            ])->toResponse($request);
        }

        return $next($request);
    }
}
