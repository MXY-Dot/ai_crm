<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks the whole app -- dashboard pages and every endpoint behind the main
 * authenticated api.php group -- until email_verified_at is set (see
 * EmailVerificationController). Super admins are exempt, same convention as
 * EnsureTenantActive: they're created by other means, never through
 * self-signup, so there's no verification step for them to complete.
 */
class EnsureEmailVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->isSuperAdmin() || $user->email_verified_at) {
            return $next($request);
        }

        if ($request->header('X-Inertia')) {
            return Inertia::render('VerifyEmailPage', ['email' => $user->email])->toResponse($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Подтвердите почту, чтобы продолжить.', 'redirect' => '/verify-email'], 403);
        }

        return redirect()->route('verification.notice');
    }
}
