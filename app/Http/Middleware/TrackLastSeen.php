<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Powers the real "online now" indicator on /team (TeamMembersTable.vue) --
 * last_login_at only updates on actual sign-in, so a user active for hours
 * in one long session always showed a stale "signed in N hours ago" instead
 * of genuinely online. Throttled to roughly one write per minute per user
 * (a raw query, not $user->save(), so it never touches updated_at) since
 * this runs on every authenticated request.
 */
class TrackLastSeen
{
    private const THROTTLE_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && (! $user->last_seen_at || $user->last_seen_at->diffInSeconds(now()) >= self::THROTTLE_SECONDS)) {
            DB::table('users')->where('id', $user->id)->update(['last_seen_at' => now()]);
        }

        return $next($request);
    }
}
