<?php

namespace Technobase\SystemStatus\Tests\Fixtures;

use Technobase\SystemStatus\Probes\Probe;
use Technobase\SystemStatus\Probes\ProbeResult;

/**
 * A probe whose result the test sets: FakeProbe::$results['database'] = ProbeResult::outage('x').
 */
class FakeProbe implements Probe
{
    /** @var array<string, ProbeResult> */
    public static array $results = [];

    public function __construct(private readonly string $key = 'default') {}

    public function name(): string
    {
        return 'Fake '.$this->key;
    }

    public function run(): ProbeResult
    {
        return self::$results[$this->key] ?? ProbeResult::ok();
    }
}
