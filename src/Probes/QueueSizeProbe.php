<?php

namespace Technobase\SystemStatus\Probes;

use Illuminate\Support\Facades\Queue;

/**
 * Jobs waiting in a queue: degraded from `degradedAt`, major outage from `outageAt`.
 */
class QueueSizeProbe implements Probe
{
    public function __construct(
        private readonly string $queue = 'default',
        private readonly ?string $connection = null,
        private readonly int $degradedAt = 100,
        private readonly int $outageAt = 1000,
    ) {}

    public function name(): string
    {
        return 'Queue size '.$this->queue;
    }

    public function run(): ProbeResult
    {
        $size = Queue::connection($this->connection)->size($this->queue);
        $message = "{$size} jobs waiting in \"{$this->queue}\"";

        return match (true) {
            $size >= $this->outageAt => ProbeResult::outage($message),
            $size >= $this->degradedAt => ProbeResult::degraded($message),
            default => ProbeResult::ok($message),
        };
    }
}
