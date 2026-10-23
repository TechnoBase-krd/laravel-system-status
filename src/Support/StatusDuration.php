<?php

namespace Technobase\SystemStatus\Support;

/**
 * Short English durations for alerts: "45 min", "1 h 5 min", "2 d 3 h".
 */
class StatusDuration
{
    public static function format(int $minutes): string
    {
        $minutes = max(1, $minutes);

        if ($minutes < 60) {
            return $minutes.' min';
        }

        if ($minutes < 1440) {
            $rest = $minutes % 60;

            return intdiv($minutes, 60).' h'.($rest ? ' '.$rest.' min' : '');
        }

        $hours = intdiv($minutes % 1440, 60);

        return intdiv($minutes, 1440).' d'.($hours ? ' '.$hours.' h' : '');
    }
}
