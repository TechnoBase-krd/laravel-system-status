<?php

namespace Technobase\SystemStatus\Listeners;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Technobase\SystemStatus\Services\SecurityMonitor;
use Throwable;

/**
 * Passes Laravel's login events to the suspicious activity monitor. Registered only while
 * security.enabled is on. Reads the account name from the credentials, never the password.
 */
class RecordLoginActivity
{
    public function __construct(private readonly SecurityMonitor $monitor) {}

    public function failed(Failed $event): void
    {
        try {
            $this->monitor->failedLogin($this->account($event->credentials), request()->ip());
        } catch (Throwable) {
            // Monitoring must never affect a login.
        }
    }

    public function login(Login $event): void
    {
        try {
            $header = config('system-status.security.logins.country_header');

            $this->monitor->login(
                $event->user,
                request()->ip(),
                filled($header) ? request()->header((string) $header) : null,
            );
        } catch (Throwable) {
            // Monitoring must never affect a login.
        }
    }

    /**
     * The first credential that is not a password or token: email, username, phone, …
     *
     * @param  array<string, mixed>  $credentials
     */
    private function account(array $credentials): ?string
    {
        foreach ($credentials as $key => $value) {
            if (is_string($value) && $value !== '' && ! preg_match('/pass|secret|token|code|otp/i', (string) $key)) {
                return $value;
            }
        }

        return null;
    }
}
