<?php

namespace Technobase\SystemStatus\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Technobase\SystemStatus\Enums\ComponentStatus;
use Technobase\SystemStatus\Models\StatusIncident;
use Technobase\SystemStatus\Models\StatusMaintenance;

/**
 * Short-range history for the System Status bars (last hour, 24 hours, 7 days), rebuilt from
 * stored data instead of a per-minute status table:
 *  - incidents give when each component was degraded or down;
 *  - the database probe, which runs on every `status:check`, marks the slots in which the
 *    checks ran (a slot without a run is "no data", unless an incident such as a scheduler gap
 *    covers it);
 *  - status_route_buckets give requests and errors per slot.
 * Timestamps are compared in PHP, in the app timezone they were written in.
 */
class StatusTimeline
{
    public const RANGES = [
        '1h' => ['slot_seconds' => 60, 'slots' => 60],
        '24h' => ['slot_seconds' => 900, 'slots' => 96],
        '7d' => ['slot_seconds' => 7200, 'slots' => 84],
    ];

    /**
     * @param  array<int, string>  $components
     * @return array<string, array{slots: array<int, array>, uptime: ?float}>
     */
    public function build(string $range, array $components): array
    {
        $config = self::RANGES[$range];
        $size = $config['slot_seconds'];
        $end = (intdiv(now()->timestamp, $size) + 1) * $size;
        $start = $end - $config['slots'] * $size;

        $runSlots = $this->runSlots($start, $size);
        $incidents = $this->incidents($start, $end);
        $maintenance = $this->maintenance($start, $end);
        // Traffic is stored in 5-minute buckets, so it is only shown for slots at least that long.
        $traffic = $size >= 300 ? $this->traffic($start, $size) : [];

        // Slot bounds and labels are the same for every component.
        $now = now()->timestamp;
        $slotBounds = [];
        for ($slotStart = $start; $slotStart < $end; $slotStart += $size) {
            $slotBounds[] = [$slotStart, min($slotStart + $size, $now), $this->label($slotStart, $slotStart + $size, $size)];
        }

        $result = [];

        foreach ($components as $component) {
            $slots = [];
            $covered = 0;
            $outage = 0;
            $componentIncidents = $incidents[$component] ?? [];
            $componentTraffic = $traffic[$component] ?? [];

            foreach ($slotBounds as $index => [$slotStart, $slotEnd, $label]) {
                [$majorSeconds, $degradedSeconds] = $componentIncidents === [] ? [0, 0] : $this->overlap($componentIncidents, $slotStart, $slotEnd);
                $maintenanceSeconds = $maintenance === [] ? 0 : $this->maintenanceOverlap($maintenance, $component, $slotStart, $slotEnd);
                $hasRun = isset($runSlots[$index]);
                $span = max(1, $slotEnd - $slotStart);
                // Maintenance time does not count against uptime.
                $countedSpan = max(0, $span - $maintenanceSeconds);

                $level = match (true) {
                    ! $hasRun && $majorSeconds === 0 && $degradedSeconds === 0 && $maintenanceSeconds === 0 => 'none',
                    $majorSeconds * 2 >= $span => 'major',
                    $majorSeconds > 0 => 'partial',
                    $degradedSeconds > 0 => 'degraded',
                    $maintenanceSeconds > 0 => 'maintenance',
                    default => 'ok',
                };

                if ($level !== 'none') {
                    $covered += $countedSpan;
                    $outage += min($countedSpan, $majorSeconds);
                }

                // Zero and empty values are left out to keep the response small.
                $slot = [
                    'label' => $label,
                    'level' => $level,
                    'uptime' => $level === 'none' ? null
                        : ($countedSpan > 0 ? round(($countedSpan - min($countedSpan, $majorSeconds)) / $countedSpan * 100, 2) : 100.0),
                ];
                if ($majorSeconds > 0) {
                    $slot['outage_minutes'] = intdiv($majorSeconds + 59, 60);
                }
                if ($degradedSeconds > 0) {
                    $slot['degraded_minutes'] = intdiv($degradedSeconds + 59, 60);
                }
                if ($maintenanceSeconds > 0) {
                    $slot['maintenance_minutes'] = intdiv($maintenanceSeconds + 59, 60);
                }
                if (isset($componentTraffic[$index])) {
                    $slot['requests'] = $componentTraffic[$index]['requests'];
                    $slot['errors'] = $componentTraffic[$index]['errors'];
                }
                $slots[] = $slot;
            }

            $result[$component] = [
                'slots' => $slots,
                'uptime' => $covered > 0 ? round(($covered - $outage) / $covered * 100, 2) : null,
            ];
        }

        return $result;
    }

    /**
     * Slot indexes in which `status:check` ran.
     *
     * @return array<int, true>
     */
    private function runSlots(int $start, int $size): array
    {
        $slots = [];

        DB::table('status_checks')
            ->where('component', 'database')
            ->where('checked_at', '>=', Carbon::createFromTimestamp($start, config('app.timezone')))
            ->pluck('checked_at')
            ->each(function ($checkedAt) use (&$slots, $start, $size) {
                // strtotime() reads the stored local time like Carbon::parse(), only faster.
                $slots[intdiv(strtotime((string) $checkedAt) - $start, $size)] = true;
            });

        return $slots;
    }

    /**
     * @return array<string, array<int, array{0: int, 1: int, 2: ComponentStatus}>> start, end, severity
     */
    private function incidents(int $start, int $end): array
    {
        $now = now()->timestamp;
        $incidents = [];

        StatusIncident::query()
            ->where('started_at', '<', Carbon::createFromTimestamp($end, config('app.timezone')))
            ->where(fn ($query) => $query->whereNull('resolved_at')
                ->orWhere('resolved_at', '>', Carbon::createFromTimestamp($start, config('app.timezone'))))
            ->get()
            ->each(function (StatusIncident $incident) use (&$incidents, $now) {
                $incidents[$incident->component][] = [
                    $incident->started_at->timestamp,
                    $incident->resolved_at?->timestamp ?? $now,
                    $incident->severity,
                ];
            });

        return $incidents;
    }

    /**
     * @return array<int, array{0: int, 1: int, 2: array<int, string>}> start, end, components
     */
    private function maintenance(int $start, int $end): array
    {
        return StatusMaintenance::query()
            ->where('starts_at', '<', Carbon::createFromTimestamp($end, config('app.timezone')))
            ->where('ends_at', '>', Carbon::createFromTimestamp($start, config('app.timezone')))
            ->get()
            ->map(fn (StatusMaintenance $m) => [$m->starts_at->timestamp, $m->ends_at->timestamp, (array) $m->components])
            ->all();
    }

    private function maintenanceOverlap(array $windows, string $component, int $slotStart, int $slotEnd): int
    {
        $seconds = 0;

        foreach ($windows as [$from, $to, $components]) {
            if (in_array($component, $components, true)) {
                $seconds += max(0, min($to, $slotEnd) - max($from, $slotStart));
            }
        }

        return min(max(0, $slotEnd - $slotStart), $seconds);
    }

    /**
     * @return array{0: int, 1: int} seconds of major outage and of degraded service in the slot
     */
    private function overlap(array $incidents, int $slotStart, int $slotEnd): array
    {
        $major = 0;
        $degraded = 0;

        foreach ($incidents as [$from, $to, $severity]) {
            $seconds = max(0, min($to, $slotEnd) - max($from, $slotStart));
            if ($severity === ComponentStatus::MajorOutage) {
                $major += $seconds;
            } else {
                $degraded += $seconds;
            }
        }

        $span = max(0, $slotEnd - $slotStart);

        return [min($span, $major), min($span, $degraded)];
    }

    /**
     * @return array<string, array<int, array{requests: int, errors: int}>>
     */
    private function traffic(int $start, int $size): array
    {
        $traffic = [];

        DB::table('status_route_buckets')
            ->where('bucket_at', '>=', Carbon::createFromTimestamp($start, config('app.timezone')))
            ->where('route', '!=', StatusMetrics::SIGNAL_ROUTE)
            ->select('component', 'bucket_at')
            ->selectRaw('SUM(requests) AS requests, SUM(errors) AS errors')
            ->groupBy('component', 'bucket_at')
            ->get()
            ->each(function ($row) use (&$traffic, $start, $size) {
                $index = intdiv(strtotime((string) $row->bucket_at) - $start, $size);
                $traffic[$row->component][$index]['requests'] = ($traffic[$row->component][$index]['requests'] ?? 0) + (int) $row->requests;
                $traffic[$row->component][$index]['errors'] = ($traffic[$row->component][$index]['errors'] ?? 0) + (int) $row->errors;
            });

        return $traffic;
    }

    private function label(int $from, int $to, int $size): string
    {
        $fromAt = Carbon::createFromTimestamp($from, config('app.timezone'));
        $toAt = Carbon::createFromTimestamp($to, config('app.timezone'));

        return $size <= 60
            ? $fromAt->format('Y-m-d H:i')
            : $fromAt->format('Y-m-d H:i').' – '.$toAt->format('H:i');
    }
}
