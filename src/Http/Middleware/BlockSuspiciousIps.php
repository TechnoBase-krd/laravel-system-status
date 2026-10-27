<?php

namespace Technobase\SystemStatus\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Technobase\SystemStatus\Services\SecurityBlocker;

/**
 * Answers 403 to IPs blocked by security.block. A global middleware that runs after TrustProxies
 * (so it sees the visitor's IP) and before the route's sessions, auth and database work, so a
 * blocked request costs one cache read.
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
