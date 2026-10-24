<?php

namespace Technobase\SystemStatus\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Technobase\SystemStatus\Enums\ComponentStatus;
use Technobase\SystemStatus\Support\StatusSql;

/**
 * Runs per component and day (status_daily, the 90-day bars) and per month (status_monthly,
 * the SLA report), counted up once per status:check run.
 */
class DailyRollup
{
    public function row(string $component, ComponentStatus $status, CarbonInterface $now): array
    {
        return [
            'component' => $component,
            'day' => $now->toDateString(),
            'runs' => 1,
            'degraded_runs' => $status === ComponentStatus::Degraded ? 1 : 0,
            'outage_runs' => $status === ComponentStatus::MajorOutage ? 1 : 0,
            'maintenance_runs' => $status === ComponentStatus::Maintenance ? 1 : 0,
        ];
    }

    /**
     * @param  array<int, array{component: string, day: string, runs: int, degraded_runs: int, outage_runs: int, maintenance_runs: int}>  $rows
     */
    public function add(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        DB::table('status_daily')->upsert(
            $rows,
            ['component', 'day'],
            StatusSql::incrementOnConflict('status_daily', ['runs', 'degraded_runs', 'outage_runs', 'maintenance_runs']),
        );

        $monthly = array_map(function (array $row) {
            $row['month'] = substr($row['day'], 0, 7);
            unset($row['day']);

            return $row;
        }, $rows);

        DB::table('status_monthly')->upsert(
            $monthly,
            ['component', 'month'],
            StatusSql::incrementOnConflict('status_monthly', ['runs', 'degraded_runs', 'outage_runs', 'maintenance_runs']),
        );
    }

    /**
     * Rows of the last $days days, keyed by component.
     *
     * @return Collection<string, Collection<int, object>>
     */
    public function history(int $days = 90): Collection
    {
        return DB::table('status_daily')
            ->where('day', '>=', now()->subDays($days - 1)->toDateString())
            ->get()
            ->groupBy('component');
    }
}
