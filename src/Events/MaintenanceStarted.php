<?php

namespace Technobase\SystemStatus\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Technobase\SystemStatus\Models\StatusMaintenance;

/**
 * Fired by status:check on the first run inside a maintenance window.
 */
class MaintenanceStarted
{
    use Dispatchable;

    public function __construct(public readonly StatusMaintenance $maintenance) {}
}
