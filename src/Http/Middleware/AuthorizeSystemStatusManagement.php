<?php

namespace Technobase\SystemStatus\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who may post incidents and schedule maintenance. Runs after AuthorizeSystemStatus; without
 * system-status.access.manage_gate, everyone who can open the page may manage.
 */
class AuthorizeSystemStatusManagement
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(self::allows($request->user()), 403);

        return $next($request);
    }

    public static function allows($user): bool
    {
        if (! $user) {
            return false;
        }

        $gate = config('system-status.access.manage_gate');

        return blank($gate) || Gate::forUser($user)->allows($gate);
    }
}
