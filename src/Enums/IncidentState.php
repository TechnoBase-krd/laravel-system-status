<?php

namespace Technobase\SystemStatus\Enums;

/**
 * Steps of an incident's timeline, as on most status pages.
 */
enum IncidentState: string
{
    case Investigating = 'investigating';
    case Identified = 'identified';
    case Monitoring = 'monitoring';
    case Resolved = 'resolved';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(?string $locale = null): string
    {
        return __('system-status::status.status_state_'.$this->value, [], $locale);
    }
}
