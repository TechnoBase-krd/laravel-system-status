<?php

namespace Technobase\SystemStatus\Probes;

use Illuminate\Support\Facades\Cache;
use Technobase\SystemStatus\Jobs\StatusHeartbeatJob;

/**
 * Checks that the heartbeat job dispatched to each monitored queue was processed recently.
 */
class QueueHeartbeatProbe implements Probe
{
    private const SLOW_WAIT_SECONDS = 60;

    public function name(): string
    {
        return 'Queue heartbeat';
    }

    public function run(): ProbeResult
    {
        $staleSeconds = (int) config('system-status.thresholds.heartbeat_stale_minutes', 3) * 60;
        $now = now()->timestamp;
        $degraded = [];

        foreach (config('system-status.queues', []) as $queue) {
            $name = $queue['queue'];
            $processed = Cache::get(StatusHeartbeatJob::processedKey($name));

            if (! $processed) {
                $firstDispatch = Cache::get(StatusHeartbeatJob::firstDispatchKey($name));

                if ($firstDispatch && $now - $firstDispatch > $staleSeconds) {
                    return ProbeResult::outage('Queue "'.$name.'" has not processed any heartbeat job');
                }

                continue;
            }

            $age = $now - (int) $processed['processed_at'];
            if ($age > $staleSeconds) {
                return ProbeResult::outage('Queue "'.$name.'" last processed a job '.intdiv($age, 60).' min ago');
            }

            if ((int) $processed['wait_seconds'] > self::SLOW_WAIT_SECONDS) {
                $degraded[] = 'Queue "'.$name.'": jobs wait '.$processed['wait_seconds'].' s';
            }
        }

        return $degraded ? ProbeResult::degraded(implode('; ', $degraded)) : ProbeResult::ok();
    }
}
