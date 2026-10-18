<?php

namespace Technobase\SystemStatus\Probes;

/**
 * A read-only check run by `status:check`. It must not change data, send messages or call a
 * paid API, and must finish within the probe timeout.
 */
interface Probe
{
    public function name(): string;

    public function run(): ProbeResult;
}
