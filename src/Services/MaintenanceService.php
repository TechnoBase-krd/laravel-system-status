<?php

namespace Technobase\SystemStatus\Services;

use Carbon\CarbonInterface;
use InvalidArgumentException;
use Technobase\SystemStatus\Models\StatusMaintenance;
use Technobase\SystemStatus\Support\StatusRegistry;

/**
 * Schedules and ends maintenance windows (used by the page and by status:maintenance).
 */
class MaintenanceService
{
    public function __construct(private readonly StatusRegistry $registry) {}

    /**
     * @param  array<int, string>  $components
     */
    public function schedule(
        array $components,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        string $title,
        ?string $message = null,
        ?string $createdBy = null,
    ): StatusMaintenance {
        $components = array_values(array_unique($components));

        if ($components === []) {
            throw new InvalidArgumentException('Choose at least one component.');
        }

        foreach ($components as $component) {
            if (! $this->registry->exists($component)) {
                throw new InvalidArgumentException("Unknown component [{$component}].");
            }
        }

        if ($endsAt->lte($startsAt)) {
            throw new InvalidArgumentException('The window must end after it starts.');
        }

        return StatusMaintenance::query()->create([
            'title' => mb_substr($title, 0, 200),
            'message' => $message,
            'components' => $components,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'created_by' => $createdBy !== null ? mb_substr($createdBy, 0, 191) : null,
        ]);
    }

    /**
     * Ends a window now. A window that has not started yet is cancelled (deleted).
     */
    public function end(StatusMaintenance $maintenance): void
    {
        if ($maintenance->starts_at->isFuture()) {
            $maintenance->delete();

            return;
        }

        if ($maintenance->ends_at->isFuture()) {
            // status:check fires MaintenanceEnded on its next run.
            $maintenance->update(['ends_at' => now()]);
        }
    }
}
