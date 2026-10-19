<?php

namespace Technobase\SystemStatus\Actions;

use Illuminate\Support\Facades\DB;
use Technobase\SystemStatus\Enums\ComponentStatus;
use Technobase\SystemStatus\Models\StatusCheck;
use Technobase\SystemStatus\Models\StatusComponent;
use Technobase\SystemStatus\Models\StatusIncident;
use Technobase\SystemStatus\Support\StatusMetrics;
use Technobase\SystemStatus\Support\StatusRegistry;
use Technobase\SystemStatus\Support\StatusSignal;
use Technobase\SystemStatus\Support\StatusSql;

/**
 * Detail of one component for the last 24 hours: traffic per hour, the routes with the most
 * failures, signal counts, recent probe results and incidents.
 */
class GetStatusComponentAction
{
    public function __construct(
        private readonly StatusRegistry $registry,
        private readonly GetStatusSummaryAction $summaryAction,
    ) {}

    public function execute(string $component): array
    {
        $since = now()->subHours(24);
        $state = StatusComponent::query()->where('component', $component)->first();
        $status = $state?->status ?? ComponentStatus::Operational;

        $hourly = DB::table('status_route_buckets')
            ->where('component', $component)
            ->where('route', '!=', StatusMetrics::SIGNAL_ROUTE)
            ->where('bucket_at', '>=', $since)
            ->selectRaw(StatusSql::hour('bucket_at').' AS hour')
            ->selectRaw('SUM(requests) AS requests, SUM(errors) AS errors')
            ->groupByRaw('1')
            ->orderByRaw('1')
            ->get()
            ->keyBy('hour');

        // Every one of the last 24 hours, so hours without traffic show as gaps in the chart.
        $hours = [];
        for ($i = 23; $i >= 0; $i--) {
            $hour = now()->subHours($i)->format('Y-m-d H:00');
            $hours[] = [
                'hour' => $hour,
                'requests' => (int) ($hourly->get($hour)->requests ?? 0),
                'errors' => (int) ($hourly->get($hour)->errors ?? 0),
            ];
        }

        $routes = DB::table('status_route_buckets')
            ->where('component', $component)
            ->where('route', '!=', StatusMetrics::SIGNAL_ROUTE)
            ->where('bucket_at', '>=', $since)
            ->select('route')
            ->selectRaw('SUM(requests) AS requests, SUM(errors) AS errors, SUM(slow) AS slow, SUM(total_ms) AS total_ms')
            ->groupBy('route')
            ->orderByRaw('SUM(errors) DESC, SUM(total_ms) / NULLIF(SUM(requests), 0) DESC')
            ->limit(20)
            ->get()
            ->map(fn ($row) => [
                'route' => $row->route,
                'requests' => (int) $row->requests,
                'errors' => (int) $row->errors,
                'error_rate' => (int) $row->requests > 0 ? round($row->errors / $row->requests * 100, 1) : 0,
                'slow' => (int) $row->slow,
                'avg_ms' => (int) $row->requests > 0 ? (int) round($row->total_ms / $row->requests) : 0,
            ])
            ->all();

        $signal = DB::table('status_route_buckets')
            ->where('component', $component)
            ->where('route', StatusMetrics::SIGNAL_ROUTE)
            ->where('bucket_at', '>=', $since)
            ->selectRaw('COALESCE(SUM(requests), 0) AS calls, COALESCE(SUM(errors), 0) AS failures')
            ->first();

        $lastFailure = StatusSignal::lastFailure($component);

        $checks = StatusCheck::query()
            ->where('component', $component)
            ->latest('checked_at')
            ->limit(20)
            ->get()
            ->map(fn (StatusCheck $check) => [
                'probe' => str_contains($check->probe, '|') ? explode('|', $check->probe, 2)[1] : $check->probe,
                'status' => $check->status?->value ?? 'skipped',
                'status_label' => $check->status?->label() ?? __('system-status::status.status_not_configured'),
                'message' => $check->message,
                'response_ms' => $check->response_ms,
                'checked_at' => $check->checked_at->format('Y-m-d H:i:s'),
            ])
            ->all();

        $incidents = StatusIncident::query()
            ->where('component', $component)
            ->latest('started_at')
            ->limit(10)
            ->get()
            ->map(fn (StatusIncident $incident) => $this->summaryAction->incidentRow($incident))
            ->all();

        return [
            'key' => $component,
            'label' => $this->registry->label($component),
            'status' => $status->value,
            'status_label' => $status->label(),
            'message' => $state?->message,
            'hourly' => $hours,
            'routes' => $routes,
            'signal' => [
                'calls' => (int) ($signal->calls ?? 0),
                'failures' => (int) ($signal->failures ?? 0),
                'last_failure' => $lastFailure ? [
                    'reason' => $lastFailure['reason'],
                    'at' => date('Y-m-d H:i', (int) $lastFailure['at']),
                ] : null,
            ],
            'checks' => $checks,
            'incidents' => $incidents,
        ];
    }
}
