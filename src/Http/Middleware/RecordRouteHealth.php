<?php

namespace Technobase\SystemStatus\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;
use Technobase\SystemStatus\Probes\HttpEndpointProbe;
use Technobase\SystemStatus\Support\RouteComponentResolver;
use Technobase\SystemStatus\Support\StatusMetrics;
use Throwable;

/**
 * Counts every request per System Status component after the response is sent. 5xx responses
 * are failures; 4xx (validation, business errors, auth) are not. Never changes the response.
 */
class RecordRouteHealth
{
    public function __construct(private readonly RouteComponentResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            $route = $request->route();

            if (! $route instanceof Route || $request->headers->has(HttpEndpointProbe::HEADER)) {
                return;
            }

            $component = $this->resolver->resolve($route->getName(), $route->uri());
            if ($component === null) {
                return;
            }

            $startedAt = defined('LARAVEL_START') ? LARAVEL_START : (float) $request->server('REQUEST_TIME_FLOAT', microtime(true));
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            StatusMetrics::record(
                $component,
                $request->method().' '.$route->uri(),
                $response->getStatusCode() >= 500,
                $durationMs,
                $durationMs >= (int) config('system-status.thresholds.slow_request_ms', 2000),
            );
        } catch (Throwable) {
            // Monitoring must never affect a request.
        }
    }
}
