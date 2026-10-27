<?php

namespace Technobase\SystemStatus\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Technobase\SystemStatus\Models\StatusSecurityEvent;

/**
 * Fired once per new suspicious activity finding (not again while it repeats within
 * security.cooldown_minutes). Listen to it to block an IP, lock an account or open a ticket.
 */
class SuspiciousActivityDetected
{
    use Dispatchable;

    public function __construct(
        public readonly StatusSecurityEvent $event,
    ) {}
}
