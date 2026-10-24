<?php

namespace Technobase\SystemStatus\Support;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The outcome of the last status:check runs, kept in a cache store (`heartbeat_store`) rather
 * than the database, so a run that fails (database down, a bug, a missing migration) is still
 * known: the page shows "checks failing since 12:04 (QueryException)" instead of old data.
 */
final class StatusHeartbeat
{
    public const KEY = 'system-status:heartbeat';

    public static function succeeded(CarbonInterface $at): void
    {
        self::put([
            'last_success_at' => $at->getTimestamp(),
            'failing_since' => null,
            'error' => null,
        ]);
    }

    public static function failed(Throwable $e, CarbonInterface $at): void
    {
        $state = self::get();

        self::put([
            'last_success_at' => $state['last_success_at'],
            'failing_since' => $state['failing_since'] ?? $at->getTimestamp(),
            'error' => class_basename($e),
        ]);
    }

    /**
     * @return array{last_success_at: ?int, failing_since: ?int, error: ?string}
     */
    public static function get(): array
    {
        $empty = ['last_success_at' => null, 'failing_since' => null, 'error' => null];

        try {
            $state = self::store()->get(self::KEY);
        } catch (Throwable) {
            return $empty;
        }

        return is_array($state) ? array_merge($empty, array_intersect_key($state, $empty)) : $empty;
    }

    private static function put(array $state): void
    {
        try {
            self::store()->forever(self::KEY, $state);
        } catch (Throwable $e) {
            Log::warning('The System Status heartbeat could not be stored.', ['exception' => $e::class]);
        }
    }

    private static function store(): Repository
    {
        return Cache::store(config('system-status.heartbeat_store'));
    }
}
