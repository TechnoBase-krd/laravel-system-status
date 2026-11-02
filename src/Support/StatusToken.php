<?php

namespace Technobase\SystemStatus\Support;

use Illuminate\Http\Request;

/**
 * Bearer token checks for the machine endpoints (deploy markers and the read-only API).
 * The token is sent as "Authorization: Bearer <token>" or "X-System-Status-Token: <token>".
 */
final class StatusToken
{
    /**
     * Whether the request carries the configured token. An empty configured token never matches.
     */
    public static function matches(Request $request, string $configured): bool
    {
        if ($configured === '') {
            return false;
        }

        $given = (string) ($request->bearerToken() ?? $request->header('X-System-Status-Token', ''));

        return $given !== '' && hash_equals($configured, $given);
    }
}
