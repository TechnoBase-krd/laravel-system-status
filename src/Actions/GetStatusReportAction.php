<?php

namespace Technobase\SystemStatus\Actions;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Technobase\SystemStatus\Models\StatusIncident;
use Technobase\SystemStatus\Support\StatusRegistry;

/**
 * Uptime per component for one month against its SLA target (per component 'sla', else
 * system-status.sla.default_target). Maintenance time does not count against uptime.
 */
class GetStatusReportAction
{
    public function __construct(private readonly StatusRegistry $registry) {}

    /**
     * @param  string  $month  YYYY-MM
     */
    public function execute(string $month): array
    {
        $start = Carbon::createFromFormat('Y-m-d H:i:s', $month.'-01 00:00:00', config('app.timezone'));
        $end = $start->copy()->addMonth();

        $rows = DB::table('status_monthly')->where('month', $month)->get()->keyBy('component');
        $incidentRows = StatusIncident::query()
            ->where('started_at', '>=', $start)
            ->where('started_at', '<', $end)
            ->orderBy('started_at')
            ->get();
        $incidents = $incidentRows->countBy('component');
        $daily = $this->dailyLevels($start, $end);

        $components = [];

        foreach ($this->registry->groups() as $group => $definitions) {
            foreach ($definitions as $component => $definition) {
                $row = $rows->get($component);
                $runs = (int) ($row->runs ?? 0);
                $maintenance = (int) ($row->maintenance_runs ?? 0);
                $outage = (int) ($row->outage_runs ?? 0);
                $counted = $runs - $maintenance;
                $uptime = $runs === 0 ? null : ($counted > 0 ? round(($counted - $outage) / $counted * 100, 3) : 100.0);
                $target = $this->target($component);

                $components[] = [
                    'key' => $component,
                    'label' => $this->registry->label($component),
                    'group' => $group,
                    'group_label' => $this->registry->groupLabel($group),
                    'uptime' => $uptime,
                    'target' => $target,
                    'met' => $target === null || $uptime === null ? null : $uptime >= $target,
                    'outage_minutes' => $outage,
                    'degraded_minutes' => (int) ($row->degraded_runs ?? 0),
                    'maintenance_minutes' => $maintenance,
                    'checked_minutes' => $runs,
                    'incidents' => (int) ($incidents[$component] ?? 0),
                    'days' => $daily[$component] ?? $this->emptyDays($start, $end),
                ];
            }
        }

        $measured = array_filter($components, fn ($c) => $c['uptime'] !== null);

        return [
            'month' => $month,
            'month_label' => $start->translatedFormat('F Y'),
            'generated_at' => now()->format('Y-m-d H:i'),
            'app' => config('app.name'),
            'average_uptime' => $measured ? round(array_sum(array_column($measured, 'uptime')) / count($measured), 3) : null,
            'missed' => count(array_filter($components, fn ($c) => $c['met'] === false)),
            'with_target' => count(array_filter($components, fn ($c) => $c['met'] !== null)),
            'outage_minutes' => array_sum(array_column($components, 'outage_minutes')),
            'maintenance_minutes' => array_sum(array_column($components, 'maintenance_minutes')),
            'incident_count' => $incidentRows->count(),
            'components' => $components,
            'incidents' => $incidentRows->map(fn (StatusIncident $incident) => [
                'component' => $this->registry->label($incident->component),
                'severity' => $incident->severity->value,
                'severity_label' => $incident->severity->label(),
                'title' => $incident->title ?: $incident->message,
                'source' => $incident->source,
                'started_at' => $incident->started_at->format('Y-m-d H:i'),
                'resolved_at' => $incident->resolved_at?->format('Y-m-d H:i'),
                'duration_minutes' => max(1, (int) $incident->started_at->diffInMinutes($incident->resolved_at ?? now())),
                'postmortem' => $incident->postmortem,
                'affected_by' => $incident->affected_by ? $this->registry->label($incident->affected_by) : null,
            ])->values()->all(),
        ];
    }

    /**
     * A level per day of the month for each component, from status_daily (kept 90 days by
     * default; older days show as "none").
     *
     * @return array<string, array<int, array{day: int, level: string}>>
     */
    private function dailyLevels(Carbon $start, Carbon $end): array
    {
        $rows = DB::table('status_daily')
            ->where('day', '>=', $start->toDateString())
            ->where('day', '<', $end->toDateString())
            ->get()
            ->groupBy('component');

        $levels = [];
        foreach ($rows as $component => $days) {
            $byDay = $days->keyBy(fn ($row) => Carbon::parse($row->day)->toDateString());
            $levels[$component] = $this->emptyDays($start, $end, function (string $date) use ($byDay) {
                $row = $byDay->get($date);

                return match (true) {
                    ! $row || (int) $row->runs === 0 => 'none',
                    (int) $row->outage_runs >= 30 => 'major',
                    (int) $row->outage_runs > 0 => 'partial',
                    (int) $row->degraded_runs > 0 => 'degraded',
                    (int) ($row->maintenance_runs ?? 0) > 0 => 'maintenance',
                    default => 'ok',
                };
            });
        }

        return $levels;
    }

    /**
     * @return array<int, array{day: int, level: string}>
     */
    private function emptyDays(Carbon $start, Carbon $end, ?callable $level = null): array
    {
        $days = [];
        for ($day = $start->copy(); $day->lt($end); $day->addDay()) {
            $days[] = [
                'day' => $day->day,
                'level' => $day->isFuture() && ! $day->isToday() ? 'future' : ($level ? $level($day->toDateString()) : 'none'),
            ];
        }

        return $days;
    }

    public function target(string $component): ?float
    {
        $definition = $this->registry->components()[$component]['definition'] ?? [];
        $target = $definition['sla'] ?? config('system-status.sla.default_target');

        return $target === null ? null : (float) $target;
    }

    /**
     * The report as CSV (one line per component).
     */
    public function csv(array $report): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['month', 'group', 'component', 'uptime_percent', 'sla_target', 'sla_met', 'outage_minutes', 'degraded_minutes', 'maintenance_minutes', 'checked_minutes', 'incidents'], escape: '');

        foreach ($report['components'] as $c) {
            fputcsv($handle, [
                $report['month'], $c['group'], $c['key'], $c['uptime'], $c['target'],
                $c['met'] === null ? '' : ($c['met'] ? 'yes' : 'no'),
                $c['outage_minutes'], $c['degraded_minutes'], $c['maintenance_minutes'], $c['checked_minutes'], $c['incidents'],
            ], escape: '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    public function html(array $report, bool $pdf = false): string
    {
        return view('system-status::report', ['report' => $report, 'pdf' => $pdf])->render();
    }

    public static function pdfAvailable(): bool
    {
        return class_exists(Pdf::class);
    }

    public function pdf(array $report): string
    {
        return Pdf::loadHTML($this->html($report, true))->output();
    }
}
