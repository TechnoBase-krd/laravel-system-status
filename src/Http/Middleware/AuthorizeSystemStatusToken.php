<?php

namespace Technobase\SystemStatus\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the JSON API that a central dashboard reads. The caller sends the token from
 * system-status.api.token as `Authorization: Bearer <token>` or in the `X-Status-Token` header.
 * The token is never accepted in the query string, so it does not end up in access logs.
 */
class AuthorizeSystemStatusToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('system-status.api.token');
        $given = (string) ($request->bearerToken() ?: $request->header('X-Status-Token'));

        if (! config('system-status.api.enabled', false) || $expected === '') {
            return response()->json(['message' => 'Not Found.'], 404);
        }

        if ($given === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
