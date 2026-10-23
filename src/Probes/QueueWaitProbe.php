<?php

namespace Technobase\SystemStatus\Probes;

use Illuminate\Support\Facades\Queue;

/**
 * How long the oldest waiting job has been in the queue: degraded from `degradedSeconds`,
 * major outage from `outageSeconds`. Skipped on drivers that cannot tell (sync, null, …).
 */
class QueueWaitProbe implements Probe
{
    public function __construct(
        private readonly string $queue = 'default',
        private readonly ?string $connection = null,
        private readonly int $degradedSeconds = 60,
        private readonly int $outageSeconds = 300,
    ) {}

    public function name(): string
    {
        return 'Queue wait '.$this->queue;
    }

    public function run(): ProbeResult
    {
        $connection = Queue::connection($this->connection);

        if (! method_exists($connection, 'creationTimeOfOldestPendingJob')) {
            return ProbeResult::skipped('This queue driver does not report job age');
        }

        $createdAt = $connection->creationTimeOfOldestPendingJob($this->queue);

        if ($createdAt === null) {
            return ProbeResult::ok('No jobs waiting');
        }

        $wait = max(0, now()->timestamp - (int) $createdAt);
        $message = "The oldest job in \"{$this->queue}\" has waited {$wait} s";

        return match (true) {
            $wait >= $this->outageSeconds => ProbeResult::outage($message),
            $wait >= $this->degradedSeconds => ProbeResult::degraded($message),
            default => ProbeResult::ok($message),
        };
    }
}
