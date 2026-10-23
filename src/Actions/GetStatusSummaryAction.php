<?php

namespace Technobase\SystemStatus\Actions;

use Illuminate\Support\Carbon;
use Technobase\SystemStatus\Enums\ComponentStatus;
use Technobase\SystemStatus\Models\StatusComponent;
use Technobase\SystemStatus\Models\StatusIncident;
use Technobase\SystemStatus\Models\StatusMaintenance;
use Technobase\SystemStatus\Services\StatusService;
use Technobase\SystemStatus\Support\StatusRegistry;
use Technobase\SystemStatus\Support\StatusTimeline;

/**
 * Everything the System Status page shows: the overall banner, each component with its
 * current status and its history for the chosen range (1h, 24h, 7d or 90d), and the incidents
 * of the last 14 days.
 */
class GetStatusSummaryAction
{
    private const HISTORY_DAYS = 90;

    private const INCIDENT_DAYS = 14;

    public const RANGES = ['1h', '24h', '7d', '90d'];

    public function __construct(
        private readonly StatusRegistry $registry,
        private readonly StatusService $service,
        private readonly StatusTimeline $timeline,
    ) {}

    public function execute(string $range = '90d'): array
    {
        $states = StatusComponent::query()->get()->keyBy('component');
        $maintenance = StatusMaintenance::activeByComponent();
        $history = $range === '90d' ? $this->service->dailyHistory(self::HISTORY_DAYS) : collect();
        $timeline = $range === '90d' ? [] : $this->timeline->build($range, array_keys($this->registry->components()));

        $lastRunAt = $states->max('checked_at');
        $lastRunAt = $lastRunAt ? Carbon::parse($lastRunAt) : null;
        $staleMinutes = (int) config('system-status.thresholds.heartbeat_stale_minutes', 3);
        $isStale = ! $lastRunAt || $lastRunAt->lt(now()->subMinutes($staleMinutes));

        $groups = [];
        $statuses = [];

        foreach ($this->registry->groups() as $group => $components) {
            $items = [];

            foreach (array_keys($components) as $component) {
                /** @var StatusComponent|null $state */
                $state = $states->get($component);
                $status = $state?->status ?? ComponentStatus::Operational;
                $message = $state?->message;

                if ($component === 'scheduler' && $isStale) {
                    $status = ComponentStatus::MajorOutage;
                    $message = $lastRunAt
                        ? __('system-status::status.status_checks_not_running_since', ['time' => $lastRunAt->format('Y-m-d H:i')])
                        : __('system-status::status.status_checks_never_ran');
                }

                // A window that started after the last run already shows.
                if (isset($maintenance[$component]) && $status !== ComponentStatus::Maintenance && ! $status->isProblem()) {
                    $status = ComponentStatus::Maintenance;
                    $message = $maintenance[$component]->title;
                }

                $configured = $state?->configured ?? true;
                if ($configured) {
                    $statuses[$component] = $status;
                }

                [$days, $uptime] = $range === '90d'
                    ? $this->days($history->get($component, collect()))
                    : [$timeline[$component]['slots'], $timeline[$component]['uptime']];

                $items[] = [
                    'key' => $component,
                    'label' => $this->registry->label($component),
                    'status' => $status->value,
                    'status_label' => $configured ? $status->label() : __('system-status::status.status_not_configured'),
                    'configured' => $configured,
                    'message' => $message,
                    'changed_at' => $state?->changed_at?->format('Y-m-d H:i'),
                    'uptime' => $uptime,
                    'days' => $days,
                ];
            }

            $groups[] = [
                'key' => $group,
                'label' => $this->registry->groupLabel($group),
                'components' => $items,
            ];
        }

        return [
            'overall' => $this->overall($statuses),
            'last_run_at' => $lastRunAt?->format('Y-m-d H:i:s'),
            'last_run_ago' => $lastRunAt?->diffForHumans(),
            'stale' => $isStale,
            'range' => [
                'key' => $range,
                'start_label' => __('system-status::status.status_range_start_'.$range),
                'uptime_label' => __('system-status::status.status_range_uptime_'.$range),
            ],
            'groups' => $groups,
            'incidents' => $this->incidents(),
            'maintenances' => $this->maintenances(),
        ];
    }

    /**
     * @return array{0: array<int, array>, 1: ?float}
     */
    private function days($rows): array
    {
        $byDay = $rows->keyBy(fn ($row) => Carbon::parse($row->day)->toDateString());
        $days = [];
        $runs = 0;
        $outageRuns = 0;
        $maintenanceRuns = 0;

        for ($i = self::HISTORY_DAYS - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $row = $byDay->get($date);

            if (! $row || (int) $row->runs === 0) {
                $days[] = ['label' => $date, 'level' => 'none', 'uptime' => null, 'outage_minutes' => 0, 'degraded_minutes' => 0, 'requests' => null, 'errors' => null];

                continue;
            }

            // Maintenance time does not count against uptime.
            $dayMaintenance = (int) ($row->maintenance_runs ?? 0);
            $counted = (int) $row->runs - $dayMaintenance;
            $runs += (int) $row->runs;
            $outageRuns += (int) $row->outage_runs;
            $maintenanceRuns += $dayMaintenance;

            $days[] = [
                'label' => $date,
                'level' => match (true) {
                    (int) $row->outage_runs >= 30 => 'major',
                    (int) $row->outage_runs > 0 => 'partial',
                    (int) $row->degraded_runs > 0 => 'degraded',
                    $dayMaintenance > 0 => 'maintenance',
                    default => 'ok',
                },
                'uptime' => $counted > 0 ? round(($counted - (int) $row->outage_runs) / $counted * 100, 2) : 100.0,
                'outage_minutes' => (int) $row->outage_runs,
                'degraded_minutes' => (int) $row->degraded_runs,
                'maintenance_minutes' => $dayMaintenance,
                'requests' => null,
                'errors' => null,
            ];
        }

        $counted = $runs - $maintenanceRuns;

        return [$days, $runs > 0 ? ($counted > 0 ? round(($counted - $outageRuns) / $counted * 100, 2) : 100.0) : null];
    }

    private function overall(array $statuses): array
    {
        $outages = array_keys(array_filter($statuses, fn (ComponentStatus $s) => $s === ComponentStatus::MajorOutage));
        $worst = ComponentStatus::worst($statuses);

        $key = match (true) {
            in_array('database', $outages, true) || count($outages) >= 3 => 'major',
            $outages !== [] => 'partial',
            $worst === ComponentStatus::Degraded => 'degraded',
            in_array(ComponentStatus::Maintenance, $statuses, true) => 'maintenance',
            default => 'operational',
        };

        return [
            'level' => $key,
            'label' => __('system-status::status.status_overall_'.$key),
        ];
    }

    private function incidents(): array
    {
        return StatusIncident::query()
            ->where(fn ($query) => $query->whereNull('resolved_at')
                ->orWhere('started_at', '>=', now()->subDays(self::INCIDENT_DAYS)))
            ->orderByRaw('resolved_at IS NOT NULL')
            ->orderByDesc('started_at')
            ->limit(50)
            ->get()
            ->map(fn (StatusIncident $incident) => $this->incidentRow($incident))
            ->all();
    }

    /**
     * Active and upcoming maintenance windows.
     */
    private function maintenances(): array
    {
        return StatusMaintenance::query()
            ->where('ends_at', '>', now())
            ->orderBy('starts_at')
            ->limit(20)
            ->get()
            ->map(fn (StatusMaintenance $maintenance) => $this->maintenanceRow($maintenance))
            ->all();
    }

    public function maintenanceRow(StatusMaintenance $maintenance): array
    {
        return [
            'id' => $maintenance->id,
            'title' => $maintenance->title,
            'message' => $maintenance->message,
            'components' => array_map(fn (string $component) => [
                'key' => $component,
                'label' => $this->registry->label($component),
            ], array_values((array) $maintenance->components)),
            'starts_at' => $maintenance->starts_at->format('Y-m-d H:i'),
            'ends_at' => $maintenance->ends_at->format('Y-m-d H:i'),
            'active' => $maintenance->isActive(),
            'created_by' => $maintenance->created_by,
        ];
    }

    public function incidentRow(StatusIncident $incident): array
    {
        $end = $incident->resolved_at ?? now();

        return [
            'component' => $incident->component,
            'component_label' => $this->registry->label($incident->component),
            'severity' => $incident->severity->value,
            'severity_label' => $incident->severity->label(),
            'message' => $incident->message,
            'started_at' => $incident->started_at->format('Y-m-d H:i'),
            'resolved_at' => $incident->resolved_at?->format('Y-m-d H:i'),
            'ongoing' => $incident->resolved_at === null,
            'duration_minutes' => max(1, (int) $incident->started_at->diffInMinutes($end)),
        ];
    }
}
