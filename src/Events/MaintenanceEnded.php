<?php

namespace Technobase\SystemStatus\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Technobase\SystemStatus\Models\StatusMaintenance;

/**
 * Fired by status:check on the first run after a maintenance window ended (or was ended early).
 */
class MaintenanceEnded
{
    use Dispatchable;

    public function __construct(public readonly StatusMaintenance $maintenance) {}
}
