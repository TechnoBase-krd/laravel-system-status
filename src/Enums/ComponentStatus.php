<?php

namespace Technobase\SystemStatus\Enums;

enum ComponentStatus: string
{
    case Operational = 'operational';
    case Degraded = 'degraded';
    case MajorOutage = 'major_outage';

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
            self::Operational => 0,
            self::Degraded => 1,
            self::MajorOutage => 2,
        };
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
