<?php

namespace Technobase\SystemStatus\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Technobase\SystemStatus\Enums\ComponentStatus;
use Technobase\SystemStatus\Models\StatusIncident;
use Technobase\SystemStatus\Models\StatusMaintenance;
use Technobase\SystemStatus\Probes\ProbeResult;
use Technobase\SystemStatus\Support\LatencyHistogram;
use Technobase\SystemStatus\Support\StatusMetrics;
use Technobase\SystemStatus\Support\StatusRegistry;
use Technobase\SystemStatus\Support\StatusSignal;
use Throwable;

/**
 * What one run observes for a component, before confirmation: the worst of its probe results,
 * its route error rate and p95, its signal error rate and an open manual incident.
 */
class ComponentEvaluator
{
    public function __construct(private readonly StatusRegistry $registry) {}

    /**
     * @param  array<int, ProbeResult>  $probeResults
     * @param  array{routes?: array, signal?: array}  $traffic
     * @return array{0: ComponentStatus, 1: ?string, 2: bool} status, message, configured
     */
    public function evaluate(string $component, array $definition, array $probeResults, array $traffic, ?StatusIncident $manual = null): array
    {
        $sources = [];

        // An open incident posted by hand holds the component at its severity until resolved.
        if ($manual) {
            $sources[] = [$manual->severity, $manual->title ?: $manual->message];
        }

        foreach ($probeResults as $result) {
            if (! $result->isSkipped()) {
                $sources[] = [$result->status, $result->message];
            }
        }

        $configured = empty($definition['probes'])
            || ! empty($sources)
            || count($probeResults) < count($definition['probes']);

        if (isset($traffic['routes'])) {
            $sources[] = $this->rateSource(
                $component,
                $traffic['routes'],
                (int) $this->registry->threshold($component, 'min_requests', 20),
                ':errors of :requests requests failed (5xx) in the last :minutes min',
            );
            $sources[] = $this->latencySource($component, $traffic['routes']);
        }

        if (isset($traffic['signal'])) {
            $source = $this->rateSource(
                $component,
                $traffic['signal'],
                (int) $this->registry->threshold($component, 'min_signals', 3),
                ':errors of :requests calls failed in the last :minutes min',
            );

            if ($source[0] !== ComponentStatus::Operational && ($last = StatusSignal::lastFailure($component))) {
                $source[1] .= ' — last: '.$last['reason'];
            }

            $sources[] = $source;
        }

        $status = ComponentStatus::worst(array_column($sources, 0));
        $message = null;

        foreach ($sources as [$sourceStatus, $sourceMessage]) {
            if ($sourceStatus === $status && $status !== ComponentStatus::Operational) {
                $message = $sourceMessage;
                break;
            }
        }

        return [$status, $message, $configured];
    }

    /**
     * Requests and errors per component over the window, split into routes and signals.
     *
     * @return array<string, array{routes?: array{requests: int, errors: int, latency: array<int, int>}, signal?: array{requests: int, errors: int}}>
     */
    public function traffic(CarbonInterface $now): array
    {
        $since = $now->copy()->subMinutes((int) config('system-status.thresholds.window_minutes', 15));

        $rows = DB::table('status_route_buckets')
            ->where('bucket_at', '>=', $since)
            ->selectRaw("component, CASE WHEN route = '".StatusMetrics::SIGNAL_ROUTE."' THEN 1 ELSE 0 END AS is_signal")
            ->selectRaw('SUM(requests) AS requests, SUM(errors) AS errors, '.LatencyHistogram::sumSelect())
            ->groupByRaw('component, is_signal')
            ->get();

        $traffic = [];
        foreach ($rows as $row) {
            $traffic[$row->component][(int) $row->is_signal === 1 ? 'signal' : 'routes'] = [
                'requests' => (int) $row->requests,
                'errors' => (int) $row->errors,
                'latency' => LatencyHistogram::fromRow($row),
            ];
        }

        // Errors from the work itself (a deploy, a migration) must not count once a maintenance
        // window is over: only traffic recorded after the window ended is used.
        foreach ($this->recentlyEndedMaintenance($since) as $component => $endedAt) {
            unset($traffic[$component]);

            DB::table('status_route_buckets')
                ->where('component', $component)
                ->where('bucket_at', '>=', $endedAt)
                ->selectRaw("CASE WHEN route = '".StatusMetrics::SIGNAL_ROUTE."' THEN 1 ELSE 0 END AS is_signal")
                ->selectRaw('SUM(requests) AS requests, SUM(errors) AS errors, '.LatencyHistogram::sumSelect())
                ->groupByRaw('is_signal')
                ->get()
                ->each(function ($row) use (&$traffic, $component) {
                    $traffic[$component][(int) $row->is_signal === 1 ? 'signal' : 'routes'] = [
                        'requests' => (int) $row->requests,
                        'errors' => (int) $row->errors,
                        'latency' => LatencyHistogram::fromRow($row),
                    ];
                });
        }

        return $traffic;
    }

    /**
     * The most severe open manual incident of each component.
     *
     * @return array<string, StatusIncident>
     */
    public function openManualIncidents(): array
    {
        try {
            return StatusIncident::query()->open()->manual()->get()
                ->sortByDesc(fn (StatusIncident $incident) => $incident->severity->severity())
                ->unique('component')
                ->keyBy('component')
                ->all();
        } catch (Throwable $e) {
            Log::warning('Manual status incidents could not be read.', ['exception' => $e::class]);

            return [];
        }
    }

    /**
     * Components whose maintenance window ended inside the traffic window, with the end time.
     *
     * @return array<string, CarbonInterface>
     */
    private function recentlyEndedMaintenance(CarbonInterface $since): array
    {
        $ended = [];

        try {
            StatusMaintenance::query()->where('ends_at', '>', $since)->where('ends_at', '<=', now())->get()
                ->each(function (StatusMaintenance $maintenance) use (&$ended) {
                    foreach ((array) $maintenance->components as $component) {
                        if (! isset($ended[$component]) || $maintenance->ends_at->gt($ended[$component])) {
                            $ended[$component] = $maintenance->ends_at;
                        }
                    }
                });
        } catch (Throwable $e) {
            Log::warning('Status maintenance windows could not be read.', ['exception' => $e::class]);
        }

        return $ended;
    }

    /**
     * @return array{0: ComponentStatus, 1: ?string}
     */
    private function rateSource(string $component, array $counts, int $minimum, string $template): array
    {
        if ($counts['requests'] < $minimum || $counts['errors'] === 0) {
            return [ComponentStatus::Operational, null];
        }

        $rate = $counts['errors'] / $counts['requests'] * 100;
        $message = strtr($template, [
            ':errors' => $counts['errors'],
            ':requests' => $counts['requests'],
            ':minutes' => (int) config('system-status.thresholds.window_minutes', 15),
        ]).' ('.round($rate).'%)';

        return match (true) {
            $rate >= (float) $this->registry->threshold($component, 'outage_error_rate', 25) => [ComponentStatus::MajorOutage, $message],
            $rate >= (float) $this->registry->threshold($component, 'degraded_error_rate', 5) => [ComponentStatus::Degraded, $message],
            default => [ComponentStatus::Operational, null],
        };
    }

    /**
     * Slowness without errors: the p95 response time over the window against
     * latency_degraded_p95_ms / latency_outage_p95_ms (both off by default).
     *
     * @return array{0: ComponentStatus, 1: ?string}
     */
    private function latencySource(string $component, array $counts): array
    {
        $degradedMs = $this->registry->threshold($component, 'latency_degraded_p95_ms');
        $outageMs = $this->registry->threshold($component, 'latency_outage_p95_ms');

        if ((! $degradedMs && ! $outageMs)
            || $counts['requests'] < (int) $this->registry->threshold($component, 'min_requests', 20)
            || ($p95 = LatencyHistogram::percentile($counts['latency'] ?? [], 95)) === null) {
            return [ComponentStatus::Operational, null];
        }

        $minutes = (int) config('system-status.thresholds.window_minutes', 15);
        $message = fn (int $limit) => "p95 response time {$p95} ms in the last {$minutes} min (limit {$limit} ms)";

        return match (true) {
            $outageMs && $p95 > (int) $outageMs => [ComponentStatus::MajorOutage, $message((int) $outageMs)],
            $degradedMs && $p95 > (int) $degradedMs => [ComponentStatus::Degraded, $message((int) $degradedMs)],
            default => [ComponentStatus::Operational, null],
        };
    }
}
