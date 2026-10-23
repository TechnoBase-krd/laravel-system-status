<?php

namespace Technobase\SystemStatus\Enums;

enum ComponentStatus: string
{
    case Operational = 'operational';
    case Degraded = 'degraded';
    case MajorOutage = 'major_outage';
    // Planned work (a maintenance window). Never the worst status, never an incident or alert.
    case Maintenance = 'maintenance';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return __('system-status::status.status_'.$this->value);
    }

    public function severity(): int
    {
        return match ($this) {
            self::Operational, self::Maintenance => 0,
            self::Degraded => 1,
            self::MajorOutage => 2,
        };
    }

    public function isProblem(): bool
    {
        return $this->severity() > 0;
    }

    /**
     * The most severe of the given statuses; operational when the list is empty.
     */
    public static function worst(array $statuses): self
    {
        $worst = self::Operational;

        foreach ($statuses as $status) {
            if ($status instanceof self && $status->severity() > $worst->severity()) {
                $worst = $status;
            }
        }

        return $worst;
    }
}
