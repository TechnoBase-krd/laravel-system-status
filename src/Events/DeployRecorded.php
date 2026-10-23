<?php

namespace Technobase\SystemStatus\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Technobase\SystemStatus\Models\StatusDeploy;

/**
 * Fired when a deploy is recorded (status:deploy or POST {path}/deploys).
 */
class DeployRecorded
{
    use Dispatchable;

    public function __construct(public readonly StatusDeploy $deploy) {}
}
