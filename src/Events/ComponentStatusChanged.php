<?php

namespace Technobase\SystemStatus\Events;

use Carbon\CarbonInterface;
use Illuminate\Foundation\Events\Dispatchable;
use Technobase\SystemStatus\Enums\ComponentStatus;

/**
 * Fired by status:check when a component's confirmed status changes (after `confirm_runs` runs in
 * a row agree). Listen to it to send alerts anywhere: mail, Slack, a webhook, an in-app feed.
 */
class ComponentStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly string $component,
        public readonly string $label,
        public readonly ComponentStatus $previous,
        public readonly ComponentStatus $current,
        public readonly ?string $message,
        public readonly CarbonInterface $changedAt,
    ) {}

    public function isRecovery(): bool
    {
        return $this->current === ComponentStatus::Operational;
    }
}
