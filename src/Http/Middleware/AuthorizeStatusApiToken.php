<?php

namespace Technobase\SystemStatus\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Technobase\SystemStatus\Support\StatusToken;

/**
 * The read-only API ({path}/api/...) for external monitors authenticates with
 * system-status.api.token instead of a session. Off (404) while no token is set.
 */
class AuthorizeStatusApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) config('system-status.api.token', '');
        abort_if($token === '', 404);
        abort_unless(StatusToken::matches($request, $token), 401);

        return $next($request);
    }
}
