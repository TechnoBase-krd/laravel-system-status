<?php

namespace Technobase\SystemStatus\Tests\Fixtures;

use Technobase\SystemStatus\Probes\HttpEndpointProbe;
use Technobase\SystemStatus\Probes\ProbeResult;

/**
 * An application's subclass of a built-in HTTP probe that overrides run() only.
 */
class CustomRunHttpProbe extends HttpEndpointProbe
{
    public function run(): ProbeResult
    {
        return ProbeResult::degraded('custom run');
    }
}
