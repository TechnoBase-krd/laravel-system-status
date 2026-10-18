<?php

namespace Technobase\SystemStatus\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Dispatched to each monitored queue every minute by `status:check`. Writing the time it ran
 * proves a worker is taking jobs from that queue; the delay since dispatch is the queue wait.
 */
class StatusHeartbeatJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public string $queueName, public int $dispatchedAt) {}

    public function handle(): void
    {
        Cache::put(self::processedKey($this->queueName), [
            'processed_at' => now()->timestamp,
            'wait_seconds' => max(0, now()->timestamp - $this->dispatchedAt),
        ], now()->addDay());
    }

    public static function processedKey(string $queue): string
    {
        return 'status:queue:'.$queue.':processed';
    }

    public static function firstDispatchKey(string $queue): string
    {
        return 'status:queue:'.$queue.':first_dispatch';
    }
}
