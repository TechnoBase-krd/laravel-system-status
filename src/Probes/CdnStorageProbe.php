<?php

namespace Technobase\SystemStatus\Probes;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * A HEAD request to the public URL of a filesystem disk (a CDN such as Bunny). Any answer below
 * 500 means the CDN is reachable. Config: ['with' => ['disk' => 's3']].
 */
class CdnStorageProbe implements ConcurrentProbe
{
    use SendsHttpRequest;

    public function __construct(private readonly string $disk = 's3') {}

    public function name(): string
    {
        return 'CDN ('.$this->disk.' disk)';
    }

    public function precheck(): ?ProbeResult
    {
        return blank($this->url()) ? ProbeResult::skipped('CDN URL is not configured') : null;
    }

    public function send(PendingRequest $request): mixed
    {
        return $request->timeout((int) config('system-status.thresholds.probe_timeout_seconds', 10))->head($this->url());
    }

    public function result(Response|Throwable $response): ProbeResult
    {
        if ($response instanceof Throwable) {
            return ProbeResult::outage('Could not connect to the CDN');
        }

        if ($response->serverError()) {
            return ProbeResult::outage('The CDN returned HTTP '.$response->status());
        }

        return ProbeResult::ok();
    }

    private function url(): ?string
    {
        return config('filesystems.disks.'.$this->disk.'.url');
    }
}
