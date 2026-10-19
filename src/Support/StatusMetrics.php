<?php

namespace Technobase\SystemStatus\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Request and signal counters for the System Status page. Writes go to one Redis hash per
 * minute (cheap, safe to call on every request); `status:check` moves finished minutes into
 * status_route_buckets in 5-minute buckets. Recording never throws.
 */
class StatusMetrics
{
    public const SIGNAL_ROUTE = '(signal)';

    private const KEY_PREFIX = 'status:metrics:';

    private const SEPARATOR = "\x1f";

    private const KEEP_MINUTES = 120;

    public static function record(string $component, string $route, bool $failed, int $durationMs = 0, bool $slow = false): void
    {
        try {
            $key = self::KEY_PREFIX.(intdiv(time(), 60) * 60);
            $field = $component.self::SEPARATOR.$route.self::SEPARATOR;

            Redis::connection(config('system-status.redis_connection'))->pipeline(function ($pipe) use ($key, $field, $failed, $durationMs, $slow) {
                $pipe->hincrby($key, $field.'r', 1);
                if ($failed) {
                    $pipe->hincrby($key, $field.'e', 1);
                }
                if ($slow) {
                    $pipe->hincrby($key, $field.'s', 1);
                }
                if ($durationMs > 0) {
                    $pipe->hincrby($key, $field.'t', $durationMs);
                }
                $pipe->expire($key, self::KEEP_MINUTES * 60 * 2);
            });
        } catch (Throwable $e) {
            Log::debug('Status metrics could not be recorded.', ['exception' => $e::class]);
        }
    }

    /**
     * Moves every finished minute from Redis into status_route_buckets. Returns the number of
     * minutes flushed.
     */
    public function flush(): int
    {
        $redis = Redis::connection(config('system-status.redis_connection'));
        $currentMinute = intdiv(time(), 60) * 60;
        $rows = [];
        $flushed = 0;

        for ($minute = $currentMinute - self::KEEP_MINUTES * 60; $minute < $currentMinute; $minute += 60) {
            $key = self::KEY_PREFIX.$minute;

            if (! $redis->exists($key)) {
                continue;
            }

            // Renaming first means a late write lands in a new hash that the next run picks up.
            $flushingKey = $key.':flushing';
            $redis->rename($key, $flushingKey);
            $values = $redis->hgetall($flushingKey) ?: [];
            $redis->del($flushingKey);
            $flushed++;

            $bucketAt = date('Y-m-d H:i:s', intdiv($minute, 300) * 300);

            foreach ($values as $field => $count) {
                $parts = explode(self::SEPARATOR, (string) $field);
                if (count($parts) !== 3) {
                    continue;
                }

                [$component, $route, $metric] = $parts;
                $rowKey = $component.self::SEPARATOR.$route.self::SEPARATOR.$bucketAt;
                $rows[$rowKey] ??= [
                    'component' => $component,
                    'route' => mb_substr($route, 0, 255),
                    'bucket_at' => $bucketAt,
                    'requests' => 0,
                    'errors' => 0,
                    'slow' => 0,
                    'total_ms' => 0,
                ];

                $column = match ($metric) {
                    'r' => 'requests',
                    'e' => 'errors',
                    's' => 'slow',
                    't' => 'total_ms',
                    default => null,
                };

                if ($column) {
                    $rows[$rowKey][$column] += (int) $count;
                }
            }
        }

        foreach (array_chunk(array_values($rows), 500) as $chunk) {
            DB::table('status_route_buckets')->upsert(
                $chunk,
                ['component', 'route', 'bucket_at'],
                StatusSql::incrementOnConflict('status_route_buckets', ['requests', 'errors', 'slow', 'total_ms']),
            );
        }

        return $flushed;
    }
}
