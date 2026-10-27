<?php

namespace Technobase\SystemStatus\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers 404 on every package route while system-status.enabled is false. The routes stay
 * registered so route('system_status.index') in an application's menu keeps working.
 */
class EnsureSystemStatusEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('system-status.enabled', true), 404);

        return $next($request);
    }
}
