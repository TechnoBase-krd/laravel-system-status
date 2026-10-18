<?php

namespace Technobase\SystemStatus\Probes;

use Spatie\Health\Checks\Check;
use Spatie\Health\Enums\Status;

/**
 * Runs one of the spatie/laravel-health checks already used by the health endpoints.
 */
class SpatieCheckProbe implements Probe
{
    /**
     * @param  class-string<Check>  $check
     */
    public function __construct(private readonly string $check) {}

    public function name(): string
    {
        return class_basename($this->check);
    }

    public function run(): ProbeResult
    {
        $result = $this->check::new()->run();
        $message = $result->getNotificationMessage() ?: $result->getShortSummary();

        return match ($result->status->value) {
            Status::ok()->value => ProbeResult::ok($message ?: null),
            Status::warning()->value => ProbeResult::degraded($message ?: 'Warning'),
            Status::skipped()->value => ProbeResult::skipped($message ?: 'Skipped'),
            default => ProbeResult::outage($message ?: 'Failed'),
        };
    }
}
