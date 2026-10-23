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
        $incidents = StatusIncident::query()
            ->where('started_at', '>=', $start)
            ->where('started_at', '<', $end)
            ->selectRaw('component, COUNT(*) AS total')
            ->groupBy('component')
            ->pluck('total', 'component');

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
            'components' => $components,
        ];
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

    public function html(array $report): string
    {
        return view('system-status::report', ['report' => $report])->render();
    }

    public static function pdfAvailable(): bool
    {
        return class_exists(Pdf::class);
    }

    public function pdf(array $report): string
    {
        return Pdf::loadHTML($this->html($report))->output();
    }
}
