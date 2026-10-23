<?php

namespace Technobase\SystemStatus\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * The System Status page shows infrastructure and integration details, so only the roles in
 * system-status.access.roles (default: Super Admin) may open it. A role check, not a permission:
 * a Super Admin passes every permission check, and a permission could be given to any role.
 * The user model needs a hasRole() method (spatie/laravel-permission); without it, access is
 * refused. Set system-status.access.gate to an ability name to use a Gate instead.
 */
class AuthorizeSystemStatus
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(self::allows($request->user()), 403);

        return $next($request);
    }

    /**
     * Whether this user may see the status (also used by the Pulse card).
     */
    public static function allows($user): bool
    {
        if (! $user) {
            return false;
        }

        // A Gate ability, when set, replaces the role check.
        if (filled($gate = config('system-status.access.gate'))) {
            return Gate::forUser($user)->allows($gate);
        }

        $roles = (array) config('system-status.access.roles', ['Super Admin']);

        return $roles !== [] && method_exists($user, 'hasRole') && $user->hasRole($roles);
    }
}
