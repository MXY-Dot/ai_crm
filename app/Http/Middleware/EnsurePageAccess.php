<?php

namespace App\Http\Middleware;

use App\Support\Authorization\RolePages;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePageAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $page = $request->route()?->getName();

        if ($user && $page && ! RolePages::allowed($user->role, $page)) {
            return redirect()->route('dashboard');
        }

        return $next($request);
    }
}
