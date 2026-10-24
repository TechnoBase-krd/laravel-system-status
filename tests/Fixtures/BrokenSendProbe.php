<?php

namespace Technobase\SystemStatus\Tests\Fixtures;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Technobase\SystemStatus\Probes\ConcurrentProbe;
use Technobase\SystemStatus\Probes\ProbeResult;
use Technobase\SystemStatus\Probes\SendsHttpRequest;
use Throwable;

/**
 * A concurrent probe whose request cannot even be built, so the pool fails.
 */
class BrokenSendProbe implements ConcurrentProbe
{
    use SendsHttpRequest;

    public function name(): string
    {
        return 'Broken send';
    }

    public function precheck(): ?ProbeResult
    {
        return null;
    }

    public function send(PendingRequest $request): mixed
    {
        throw new RuntimeException('secret detail');
    }

    public function result(Response|Throwable $response): ProbeResult
    {
        return $response instanceof Throwable
            ? ProbeResult::outage('Check failed ('.class_basename($response).')')
            : ProbeResult::ok();
    }
}
