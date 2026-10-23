<?php

namespace Technobase\SystemStatus\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Technobase\SystemStatus\Models\StatusIncident;
use Technobase\SystemStatus\Models\StatusIncidentUpdate;

/**
 * Fired when an incident is posted by hand, gets a timeline update or a postmortem.
 */
class IncidentUpdated
{
    use Dispatchable;

    public const POSTED = 'posted';

    public const UPDATED = 'updated';

    public const POSTMORTEM = 'postmortem';

    public function __construct(
        public readonly StatusIncident $incident,
        public readonly string $action,
        public readonly ?StatusIncidentUpdate $update = null,
    ) {}
}
