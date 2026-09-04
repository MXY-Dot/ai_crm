<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two-tier gate (see EmailVerificationController for how each is set):
 *
 * 1. email_verified_at (5-digit code, entered right after signup) unlocks
 *    nothing but the Settings page -- just enough to trigger step 2.
 * 2. email_link_confirmed_at (a signed link, sent on demand from that same
 *    Settings page) unlocks everything else: onboarding, dashboard, every
 *    other authenticated API route.
 *
 * Super admins are exempt, same convention as EnsureTenantActive: they're
 * created by other means, never through self-signup.
 */
class EnsureEmailVerified
{
    /** Reachable once the code is confirmed but before the link is -- just enough for Settings to work. */
    private const PROFILE_STAGE_PATHS = [
        'profile',
        'verify-email*',
        'api/me',
        'api/dashboard',
        'api/profile',
        'api/profile/*',
        'api/notification-settings/*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->isSuperAdmin()) {
            return $next($request);
        }

        if (! $user->email_verified_at) {
            return $this->deny($request, '/verify-email', 'Подтвердите почту, чтобы продолжить.');
        }

        if (! $user->email_link_confirmed_at && ! $request->is(...self::PROFILE_STAGE_PATHS)) {
            return $this->deny($request, '/profile', 'Подтвердите почту по ссылке в Настройках, чтобы получить полный доступ.');
        }

        return $next($request);
    }

    private function deny(Request $request, string $redirectTo, string $message): Response
    {
        if ($request->header('X-Inertia')) {
            return Inertia::location($redirectTo);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'redirect' => $redirectTo], 403);
        }

        return redirect($redirectTo);
    }
}
