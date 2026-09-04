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
 *    nothing at all -- everything, including reading, redirects to
 *    /verify-email until this is done.
 * 2. email_link_confirmed_at (a signed link, sent on demand from a
 *    "Подтвердить почту" button on Settings) unlocks WRITES to actual
 *    business data. Once the code is done, every GET already works -- the
 *    whole app is Browse-able -- but a mutation (POST/PUT/PATCH/DELETE)
 *    403s until the link is also confirmed, except account/setup writes
 *    (profile/avatar/2FA/notifications, onboarding) needed to reach and
 *    complete that first-run setup and actually trigger the link email.
 *
 * Super admins are exempt, same convention as EnsureTenantActive: they're
 * created by other means, never through self-signup.
 */
class EnsureEmailVerified
{
    /** Writes still allowed with only the code done -- account/setup, not business data. */
    private const ACCOUNT_WRITE_PATHS = [
        'api/profile',
        'api/profile/*',
        'api/notification-settings/*',
        'api/onboarding/*',
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

        $isSafeMethod = $request->isMethod('get') || $request->isMethod('head');

        if (! $user->email_link_confirmed_at && ! $isSafeMethod && ! $request->is(...self::ACCOUNT_WRITE_PATHS)) {
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
