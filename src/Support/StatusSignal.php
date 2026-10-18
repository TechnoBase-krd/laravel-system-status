<?php

namespace Technobase\SystemStatus\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reports the outcome of a call that the status checks cannot probe without cost or side
 * effects (SMS gateway, social login, Gemini, AppLens). Counts only: it never throws and never
 * changes the caller's behaviour. The component must have 'signal' => true in config/system-status.php.
 */
class StatusSignal
{
    public static function success(string $component): void
    {
        StatusMetrics::record($component, StatusMetrics::SIGNAL_ROUTE, false);
    }

    /**
     * $reason must not contain secrets or personal data: an exception class or a short text.
     */
    public static function failure(string $component, string $reason): void
    {
        StatusMetrics::record($component, StatusMetrics::SIGNAL_ROUTE, true);

        try {
            Cache::put(self::lastFailureKey($component), [
                'reason' => Str::limit($reason, 200),
                'at' => now()->timestamp,
            ], now()->addDay());
        } catch (Throwable) {
            // Counting is best effort.
        }
    }

    public static function lastFailure(string $component): ?array
    {
        try {
            return Cache::get(self::lastFailureKey($component));
        } catch (Throwable) {
            return null;
        }
    }

    private static function lastFailureKey(string $component): string
    {
        return 'status:signal:'.$component.':last_failure';
    }
}
