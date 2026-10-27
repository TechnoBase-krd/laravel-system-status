<?php

namespace Technobase\SystemStatus\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Technobase\SystemStatus\Services\SecurityBlocker;

/**
 * Answers 403 to IPs blocked by security.block. Prepended to the global middleware, so a blocked
 * request costs one cache read and never reaches sessions, auth or the database.
 */
class BlockSuspiciousIps
{
    public function __construct(private readonly SecurityBlocker $blocker) {}

    public function handle(Request $request, Closure $next): Response
    {
        $ip = $request->ip();

        if ($ip !== null && ($until = $this->blocker->blockedUntil($ip)) !== null) {
            return response('Access denied.', 403, [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Retry-After' => (string) max(1, $until - now()->getTimestamp()),
                'Cache-Control' => 'no-store',
            ]);
        }

        return $next($request);
    }
}
