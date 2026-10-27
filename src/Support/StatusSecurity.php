<?php

namespace Technobase\SystemStatus\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Technobase\SystemStatus\Services\SecurityMonitor;
use Throwable;

/**
 * Reports a risky action to the suspicious activity monitor (security.actions):
 *
 *     StatusSecurity::action('roles.changed', auth()->user(), ['target' => 'User #12']);
 *
 * Never throws and never changes the caller's behaviour. Does nothing while security.enabled is off.
 */
class StatusSecurity
{
    /**
     * @param  Authenticatable|null  $actor  null = the signed-in user
     * @param  array<string, scalar|null>  $details  no secrets or personal data
     */
    public static function action(string $action, ?Authenticatable $actor = null, array $details = []): void
    {
        try {
            $actor ??= auth()->user();

            app(SecurityMonitor::class)->action($action, $actor, $details, self::requestIp());
        } catch (Throwable) {
            // Monitoring must never affect the caller.
        }
    }

    /**
     * The client IP while handling an HTTP request; null in queue workers and commands, so a
     * job's calls are not all counted against 127.0.0.1.
     */
    public static function requestIp(): ?string
    {
        if (app()->runningInConsole() || ! app()->bound('request')) {
            return null;
        }

        return request()->ip();
    }
}
