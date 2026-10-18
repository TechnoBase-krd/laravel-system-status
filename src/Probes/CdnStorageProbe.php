<?php

namespace Technobase\SystemStatus\Probes;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * A HEAD request to the public URL of a filesystem disk (a CDN such as Bunny). Any answer below
 * 500 means the CDN is reachable. Config: ['with' => ['disk' => 's3']].
 */
class CdnStorageProbe implements Probe
{
    public function __construct(private readonly string $disk = 's3') {}

    public function name(): string
    {
        return 'CDN ('.$this->disk.' disk)';
    }

    public function run(): ProbeResult
    {
        $url = config('filesystems.disks.'.$this->disk.'.url');

        if (blank($url)) {
            return ProbeResult::skipped('CDN URL is not configured');
        }

        try {
            $response = Http::timeout((int) config('system-status.thresholds.probe_timeout_seconds', 10))->head($url);
        } catch (ConnectionException) {
            return ProbeResult::outage('Could not connect to the CDN');
        }

        if ($response->serverError()) {
            return ProbeResult::outage('The CDN returned HTTP '.$response->status());
        }

        return ProbeResult::ok();
    }
}
