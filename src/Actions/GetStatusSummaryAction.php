<?php

namespace Technobase\SystemStatus\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Technobase\SystemStatus\Enums\ComponentStatus;
use Technobase\SystemStatus\Models\StatusComponent;
use Technobase\SystemStatus\Models\StatusDeploy;
use Technobase\SystemStatus\Models\StatusIncident;
use Technobase\SystemStatus\Models\StatusMaintenance;
use Technobase\SystemStatus\Models\StatusSecurityEvent;
use Technobase\SystemStatus\Services\SecurityBlocker;
use Technobase\SystemStatus\Services\SecurityMonitor;
use Technobase\SystemStatus\Services\StatusService;
use Technobase\SystemStatus\Support\StatusHeartbeat;
use Technobase\SystemStatus\Support\StatusRegistry;
use Technobase\SystemStatus\Support\StatusTimeline;
use Throwable;

/**
 * Everything the System Status page shows: the overall banner, each component with its
 * current status and its history for the chosen range (1h, 24h, 7d or 90d), and the first page
 * of incidents (open ones first; more pages come from incidentsPage()).
 */
class GetStatusSummaryAction
{
    private const HISTORY_DAYS = 90;

    public const INCIDENTS_PER_PAGE = 10;

    public const RANGES = ['1h', '24h', '7d', '90d'];

    public function __construct(
        private readonly StatusRegistry $registry,
        private readonly StatusService $service,
        private readonly StatusTimeline $timeline,
        private readonly GetStatusReportAction $report,
    ) {}

    public function execute(string $range = '90d'): array
    {
        $states = StatusComponent::query()->get()->keyBy('component');
        $maintenance = StatusMaintenance::activeByComponent();
        $history = $range === '90d' ? $this->service->dailyHistory(self::HISTORY_DAYS) : collect();
        $timeline = $range === '90d' ? [] : $this->timeline->build($range, array_keys($this->registry->components()));

        // The heartbeat knows about runs that failed before they could store anything.
        $heartbeat = StatusHeartbeat::get();
        $lastRunAt = $heartbeat['last_success_at']
            ? Carbon::createFromTimestamp($heartbeat['last_success_at'], config('app.timezone'))
            : ($states->max('checked_at') ? Carbon::parse($states->max('checked_at')) : null);
        $staleMinutes = (int) config('system-status.thresholds.heartbeat_stale_minutes', 3);
        $isStale = ! $lastRunAt || $lastRunAt->lt(now()->subMinutes($staleMinutes));
        $failingSince = $isStale && $heartbeat['failing_since']
            ? Carbon::createFromTimestamp($heartbeat['failing_since'], config('app.timezone'))
            : null;

        $groups = [];
        $statuses = [];
        // The 90 dates are the same for every component.
        $dates = $range === '90d' ? $this->dates() : [];

        foreach ($this->registry->groups() as $group => $components) {
            $items = [];

            foreach (array_keys($components) as $component) {
                /** @var StatusComponent|null $state */
                $state = $states->get($component);
                $status = $state?->status ?? ComponentStatus::Operational;
                $message = $state?->message;

                if ($component === 'scheduler' && $isStale) {
                    $status = ComponentStatus::MajorOutage;
                    $message = match (true) {
                        $failingSince !== null => __('system-status::status.status_checks_failing_since', [
                            'time' => $failingSince->format('Y-m-d H:i'),
                            'error' => $heartbeat['error'] ?? '?',
                        ]),
                        $lastRunAt !== null => __('system-status::status.status_checks_not_running_since', ['time' => $lastRunAt->format('Y-m-d H:i')]),
                        default => __('system-status::status.status_checks_never_ran'),
                    };
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
                    ? $this->days($history->get($component, collect()), $dates)
                    : [$timeline[$component]['slots'], $timeline[$component]['uptime']];

                $items[] = [
                    'key' => $component,
                    'label' => $this->registry->label($component),
                    'status' => $status->value,
                    'status_label' => $configured ? $status->label() : __('system-status::status.status_not_configured'),
                    'configured' => $configured,
                    'message' => $message,
                    'changed_at' => $state?->changed_at?->format('Y-m-d H:i'),
                    'affected_by' => $state?->affected_by,
                    'affected_by_label' => $state?->affected_by ? $this->registry->label($state->affected_by) : null,
                    'uptime' => $uptime,
                    'sla_target' => $target = $this->report->target($component),
                    'sla_missed' => $range === '90d' && $target !== null && $uptime !== null && $uptime < $target,
                    'days' => $days,
                ];
            }

            $groups[] = [
                'key' => $group,
                'label' => $this->registry->groupLabel($group),
                'components' => $items,
            ];
        }

        $incidents = $this->incidentsPage(1);
        [$rangeStart, $rangeEnd] = StatusTimeline::bounds($range);
        $recentDeploys = StatusDeploy::query()->latest('deployed_at')->latest('id')->limit(5)->get();

        return [
            'overall' => $this->overall($statuses),
            'last_run_at' => $lastRunAt?->format('Y-m-d H:i:s'),
            'last_run_ago' => $lastRunAt?->diffForHumans(),
            'stale' => $isStale,
            'checks_failing' => $failingSince ? [
                'since' => $failingSince->format('Y-m-d H:i'),
                'error' => $heartbeat['error'],
            ] : null,
            'range' => [
                'key' => $range,
                'start_label' => __('system-status::status.status_range_start_'.$range),
                'uptime_label' => __('system-status::status.status_range_uptime_'.$range),
            ],
            'groups' => $groups,
            'incidents' => $incidents['data'],
            'incidents_meta' => $incidents['meta'] + [
                'open' => StatusIncident::query()->whereNull('resolved_at')->count(),
            ],
            'maintenances' => $this->maintenances(),
            // Deploys inside the range, placed on the bars (position in % from the start).
            'deploys' => StatusDeploy::query()
                ->where('deployed_at', '>=', Carbon::createFromTimestamp($rangeStart, config('app.timezone')))
                ->where('deployed_at', '<', Carbon::createFromTimestamp($rangeEnd, config('app.timezone')))
                ->orderBy('deployed_at')
                ->limit(100)
                ->get()
                ->map(fn (StatusDeploy $deploy) => $this->deployRow($deploy, $rangeStart, $rangeEnd))
                ->all(),
            'recent_deploys' => $recentDeploys->map(fn (StatusDeploy $deploy) => $this->deployRow($deploy))->all(),
            // Suspicious activity of the last 24 hours (security.enabled), newest first.
            'security' => $this->security($range),
            'report_url' => route('system_status.report', ['month' => now()->format('Y-m'), 'format' => 'html']),
        ];
    }

    /**
     * The last 90 dates, oldest first.
     *
     * @return array<int, string>
     */
    private function dates(): array
    {
        $today = now()->startOfDay();

        return array_map(fn (int $i) => $today->copy()->subDays($i)->toDateString(), range(self::HISTORY_DAYS - 1, 0));
    }

    /**
     * Zero and empty values are left out of each day to keep the response small.
     *
     * @param  array<int, string>  $dates
     * @return array{0: array<int, array>, 1: ?float}
     */
    private function days($rows, array $dates): array
    {
        $byDay = $rows->keyBy(fn ($row) => substr((string) $row->day, 0, 10));
        $days = [];
        $runs = 0;
        $outageRuns = 0;
        $maintenanceRuns = 0;

        foreach ($dates as $date) {
            $row = $byDay->get($date);

            if (! $row || (int) $row->runs === 0) {
                $days[] = ['label' => $date, 'level' => 'none', 'uptime' => null];

                continue;
            }

            // Maintenance time does not count against uptime.
            $dayMaintenance = (int) ($row->maintenance_runs ?? 0);
            $counted = (int) $row->runs - $dayMaintenance;
            $runs += (int) $row->runs;
            $outageRuns += (int) $row->outage_runs;
            $maintenanceRuns += $dayMaintenance;

            $days[] = array_filter([
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
            ], fn ($value, $key) => $value !== 0 || $key === 'uptime', ARRAY_FILTER_USE_BOTH);
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

    /**
     * One page of incidents: open ones first, then the most recent.
     *
     * @return array{data: array<int, array>, meta: array{page: int, per_page: int, total: int, has_more: bool}}
     */
    public function incidentsPage(int $page, ?int $perPage = null): array
    {
        $perPage = max(1, min(100, $perPage ?? (int) config('system-status.incidents_per_page', self::INCIDENTS_PER_PAGE)));
        $page = max(1, $page);

        $paginator = StatusIncident::query()
            ->with('updates')
            ->orderByRaw('resolved_at IS NOT NULL')
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => $paginator->getCollection()
                ->map(fn (StatusIncident $incident) => $this->incidentRow($incident))
                ->all(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $perPage,
                'total' => $paginator->total(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ];
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

    /**
     * Findings seen within the range (1h, 24h, 7d, 90d), or null while security monitoring is
     * off. While it is on, the panel always shows (with a "Live" badge), also with nothing to report.
     *
     * @return array{range: string, range_label: string, total: int, active: int, watching: int, events: array<int, array<string, mixed>>}|null
     */
    public function security(string $range = '24h'): ?array
    {
        if (! config('system-status.enabled', true) || ! config('system-status.security.enabled', false)) {
            return null;
        }

        try {
            $range = in_array($range, self::RANGES, true) ? $range : '24h';
            $since = match ($range) {
                '1h' => now()->subHour(),
                '7d' => now()->subDays(7),
                '90d' => now()->subDays(90),
                default => now()->subDay(),
            };
            $query = StatusSecurityEvent::query()->where('last_seen_at', '>=', $since);
            $rows = app(GetSecurityEventAction::class);
            $events = (clone $query)->latest('last_seen_at')->latest('id')->limit(10)->get();

            return [
                'range' => $range,
                'range_label' => __('system-status::status.status_security_range_'.$range),
                'total' => (clone $query)->count(),
                'active' => $events->filter(fn (StatusSecurityEvent $event) => $rows->active($event))->count(),
                'critical' => (clone $query)->where('severity', StatusSecurityEvent::SEVERITY_CRITICAL)->count(),
                'blocked' => app(SecurityBlocker::class)->enabled()
                    ? DB::table('status_security_blocks')->where('blocked_until', '>', now())->count()
                    : null,
                'watching' => $this->securityRules(),
                'events' => $events->map(fn (StatusSecurityEvent $event) => $rows->row($event))->all(),
            ];
        } catch (Throwable) {
            // Table not migrated yet.
            return null;
        }
    }

    /**
     * How many rules are watching: request rules, login checks, signal limits, action rules.
     */
    private function securityRules(): int
    {
        $security = (array) config('system-status.security', []);
        $logins = (array) ($security['logins'] ?? []);
        $loginsOn = SecurityMonitor::on($logins);
        $active = fn ($rule) => is_array($rule) && (int) ($rule['max'] ?? 0) > 0 && SecurityMonitor::on($rule);
        $on = fn ($rule) => is_array($rule) && SecurityMonitor::on($rule);

        return count(array_filter((array) ($security['requests'] ?? []), $active))
            + ($loginsOn ? count(array_filter([$logins['failed'] ?? null, $logins['failed_per_account'] ?? null], $active)) : 0)
            + (int) ($loginsOn && ($logins['new_country'] ?? true) && filled($logins['country_header'] ?? 'CF-IPCountry'))
            + (int) ($loginsOn && ($logins['new_ip'] ?? false))
            + count(array_filter((array) ($security['signals'] ?? []), $on))
            + count(array_filter((array) ($security['actions'] ?? []), $on))
            + (int) is_array($security['quiet_hours'] ?? null);
    }

    /**
     * A deploy for the page; with $start and $end (Unix seconds), also its position in % on the bars.
     */
    public function deployRow(StatusDeploy $deploy, ?int $start = null, ?int $end = null): array
    {
        $row = [
            'id' => $deploy->id,
            'version' => $deploy->version,
            'label' => $deploy->label(),
            'commit' => $deploy->commit,
            'note' => $deploy->note,
            'deployed_by' => $deploy->deployed_by,
            'at' => $deploy->deployed_at->format('Y-m-d H:i'),
            'ago' => $deploy->deployed_at->diffForHumans(),
            // null = not compared yet; [] = nothing got worse.
            'worse' => $deploy->compared_at === null ? null : array_map(fn (array $item) => $item + [
                'label' => $this->registry->label($item['component']),
            ], (array) $deploy->comparison),
        ];

        if ($start !== null && $end !== null && $end > $start) {
            $row['position'] = round(($deploy->deployed_at->timestamp - $start) / ($end - $start) * 100, 2);
        }

        return $row;
    }

    public function incidentRow(StatusIncident $incident): array
    {
        $end = $incident->resolved_at ?? now();
        $deploy = $incident->source === StatusIncident::SOURCE_MANUAL ? null : StatusDeploy::justBefore($incident->started_at);

        return [
            'id' => $incident->id,
            'component' => $incident->component,
            'component_label' => $this->registry->label($incident->component),
            'severity' => $incident->severity->value,
            'severity_label' => $incident->severity->label(),
            'title' => $incident->title,
            'source' => $incident->source ?? StatusIncident::SOURCE_AUTO,
            'state' => $incident->state?->value,
            'state_label' => $incident->state?->label(),
            'postmortem' => $incident->postmortem,
            'created_by' => $incident->created_by,
            'updates' => $incident->relationLoaded('updates') ? $incident->updates->map(fn ($update) => [
                'state' => $update->state->value,
                'state_label' => $update->state->label(),
                'message' => $update->message,
                'author' => $update->author,
                'at' => $update->created_at?->format('Y-m-d H:i'),
            ])->values()->all() : [],
            'message' => $incident->message,
            'started_at' => $incident->started_at->format('Y-m-d H:i'),
            'resolved_at' => $incident->resolved_at?->format('Y-m-d H:i'),
            'ongoing' => $incident->resolved_at === null,
            'duration_minutes' => max(1, (int) $incident->started_at->diffInMinutes($end)),
            // The deploy this incident started soon after (deploys.incident_window_minutes).
            'deploy' => $deploy ? [
                'label' => $deploy->label(),
                'minutes' => (int) $deploy->deployed_at->diffInMinutes($incident->started_at),
            ] : null,
        ];
    }
}
