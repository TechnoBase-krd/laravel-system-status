<?php

namespace Technobase\SystemStatus\Probes;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * GETs one of the app's own public, read-only URLs through APP_URL, so the whole chain
 * (DNS, proxy, web server, PHP, database, cache) is exercised the way a customer reaches it.
 */
class HttpEndpointProbe implements ConcurrentProbe
{
    use SendsHttpRequest;

    public const HEADER = 'X-Status-Probe';

    public function __construct(private readonly string $path, private readonly bool $json = true) {}

    public function name(): string
    {
        return 'GET '.$this->path;
    }

    public function precheck(): ?ProbeResult
    {
        return null;
    }

    public function send(PendingRequest $request): mixed
    {
        $baseUrl = config('system-status.probe_base_url');

        $headers = [
            self::HEADER => '1',
            'Accept' => $this->json ? 'application/json' : 'text/html',
            'lang' => 'en',
        ];

        // Reaching the app directly: keep the public host so the web server picks the right site.
        if (filled($baseUrl) && ($host = parse_url((string) config('app.url'), PHP_URL_HOST))) {
            $headers['Host'] = $host;
        }

        $request = $request->timeout((int) config('system-status.thresholds.probe_timeout_seconds', 10))
            ->withUserAgent((string) config('system-status.probe_user_agent', 'SystemStatusProbe/1.0'))
            ->withHeaders($headers);

        if (! config('system-status.probe_verify_ssl', true)) {
            $request = $request->withoutVerifying();
        }

        return $request->get($this->url());
    }

    public function result(Response|Throwable $response): ProbeResult
    {
        if ($response instanceof ConnectionException) {
            return ProbeResult::outage('Could not connect to '.$this->target($this->url()).$this->reason($response));
        }

        if ($response instanceof Throwable) {
            return ProbeResult::outage('Check failed ('.class_basename($response).')');
        }

        if (! $response->successful()) {
            return ProbeResult::outage($this->path.' returned HTTP '.$response->status().$this->origin($response));
        }

        $seconds = $response->transferStats?->getTransferTime();
        if ($seconds !== null && $seconds * 1000 >= (int) config('system-status.thresholds.slow_probe_ms', 3000)) {
            return ProbeResult::degraded($this->path.' is slow ('.round($seconds, 1).' s)');
        }

        return ProbeResult::ok();
    }

    private function url(): string
    {
        $baseUrl = config('system-status.probe_base_url');

        return rtrim((string) ($baseUrl ?: config('app.url')), '/').$this->path;
    }

    /**
     * Scheme, host and port the probe called, plus the path; never a query string.
     */
    private function target(string $url): string
    {
        $parts = parse_url($url);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return ($parts['scheme'] ?? '').'://'.($parts['host'] ?? '').$port.$this->path;
    }

    /**
     * The cURL reason (e.g. "Connection refused", "SSL certificate problem") without the URL.
     */
    private function reason(ConnectionException $e): string
    {
        return preg_match('/cURL error \d+: ([^(]+)/', $e->getMessage(), $match)
            ? ' ('.trim(mb_substr($match[1], 0, 120)).')'
            : '';
    }

    /**
     * Says who answered a failed request, so a block by a proxy or firewall in front of the
     * app (no Laravel involved) is told apart from an error in the app itself.
     */
    private function origin(Response $response): string
    {
        $server = strtolower((string) $response->header('Server'));

        return match (true) {
            $response->hasHeader('CF-RAY') || str_contains($server, 'cloudflare') => ' (blocked or answered by Cloudflare, not the app)',
            $response->hasHeader('X-Powered-By') || $response->hasHeader('Set-Cookie') => ' (answered by the app)',
            $server !== '' => ' (answered by '.mb_substr($server, 0, 40).', not the app)',
            default => '',
        };
    }
}
