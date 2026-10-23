<?php

namespace Technobase\SystemStatus\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Technobase\SystemStatus\Enums\ComponentStatus;
use Technobase\SystemStatus\Events\ComponentStatusChanged;
use Technobase\SystemStatus\Events\MaintenanceEnded;
use Technobase\SystemStatus\Events\MaintenanceStarted;
use Technobase\SystemStatus\Jobs\StatusHeartbeatJob;
use Technobase\SystemStatus\Models\StatusCheck;
use Technobase\SystemStatus\Models\StatusComponent;
use Technobase\SystemStatus\Models\StatusIncident;
use Technobase\SystemStatus\Models\StatusMaintenance;
use Technobase\SystemStatus\Probes\Probe;
use Technobase\SystemStatus\Probes\ProbeResult;
use Technobase\SystemStatus\Support\LatencyHistogram;
use Technobase\SystemStatus\Support\StatusAlerter;
use Technobase\SystemStatus\Support\StatusDuration;
use Technobase\SystemStatus\Support\StatusMetrics;
use Technobase\SystemStatus\Support\StatusRegistry;
use Technobase\SystemStatus\Support\StatusSignal;
use Technobase\SystemStatus\Support\StatusSql;
use Throwable;

/**
 * One run of the System Status checks (`status:check`, every minute): flush traffic counters,
 * run the probes that are due, work out each component's status, confirm changes after
 * `confirm_runs` runs in a row, open or resolve incidents, alert Telegram and update the daily
 * rollup. All state is in the database, so a Redis outage is still detected and stored.
 */
class StatusService
{
    public function __construct(
        private readonly StatusMetrics $metrics,
        private readonly StatusRegistry $registry,
        private readonly StatusAlerter $alerter,
    ) {}

    /**
     * This run's failing components that depend on another failing component ('depends_on'),
     * mapped to the root cause.
     *
     * @var array<string, string>
     */
    private array $affectedBy = [];

    /**
     * @return array{flushed_minutes: int, probes_run: int, changes: array<int, string>}
     */
    public function run(): array
    {
        $now = now();
        $this->alerter->begin();
        $this->recordSchedulerGap($now);

        $flushed = 0;
        try {
            $flushed = $this->metrics->flush();
        } catch (Throwable $e) {
            Log::warning('Status metrics flush failed.', ['exception' => $e::class]);
        }

        [$probeResults, $probesRun] = $this->runProbes($now);
        $this->dispatchQueueHeartbeats($now);
        $traffic = $this->traffic($now);
        $maintenance = $this->maintenanceWindows($now);
        $manual = $this->openManualIncidents();

        $changes = [];
        $daily = [];
        $evaluated = [];

        foreach ($this->registry->components() as $component => $entry) {
            $evaluated[$component] = $this->evaluate(
                $component,
                $entry['definition'],
                $probeResults[$component] ?? [],
                $traffic[$component] ?? [],
                $manual[$component] ?? null,
            );
        }

        $this->affectedBy = $this->dependencyFailures($evaluated, $maintenance);

        foreach ($evaluated as $component => [$status, $message, $configured]) {
            if (isset($this->affectedBy[$component])) {
                $message = trim('Affected by '.$this->registry->label($this->affectedBy[$component], 'en').($message ? ' — '.$message : ''));
            }

            $state = isset($maintenance[$component])
                ? $this->applyMaintenance($component, $maintenance[$component], $configured, $now)
                : $this->applyStatus($component, $status, $message, $configured, $now, $changes);
            $daily[] = $this->dailyRow($component, $state->status, $now);
        }

        $this->addDaily($daily);
        $this->sendReminders($now, $maintenance);
        $this->alerter->flush($this->affectedBy, $this->registry);
        $this->alerter->flappingEnded(function (string $component) {
            $status = StatusComponent::query()->where('component', $component)->value('status');

            return $this->registry->label($component, 'en').' is '.str_replace('_', ' ', $status instanceof ComponentStatus ? $status->value : (string) $status);
        });

        return ['flushed_minutes' => $flushed, 'probes_run' => $probesRun, 'changes' => $changes];
    }

    /**
     * Runs every probe that is due and returns, per component, the latest usable result of
     * each of its probes (fresh or from an earlier run).
     *
     * @return array{0: array<string, array<int, ProbeResult>>, 1: int}
     */
    private function runProbes(CarbonInterface $now): array
    {
        $latest = StatusCheck::query()
            ->where('checked_at', '>=', $now->copy()->subHour())
            ->orderByDesc('checked_at')
            ->get()
            ->unique(fn (StatusCheck $check) => $check->component.'|'.$check->probe)
            ->keyBy(fn (StatusCheck $check) => $check->component.'|'.$check->probe);

        $results = [];
        $probesRun = 0;

        foreach ($this->registry->components() as $component => $entry) {
            foreach ($entry['definition']['probes'] ?? [] as $index => $definition) {
                $every = max(1, (int) ($definition['every'] ?? 1));

                try {
                    /** @var Probe $probe */
                    $probe = app()->make($definition['class'], $definition['with'] ?? []);
                    $probeKey = mb_substr($index.'|'.$probe->name(), 0, 128);
                } catch (Throwable $e) {
                    $results[$component][] = ProbeResult::outage('Probe could not be created ('.class_basename($e).')');

                    continue;
                }

                $last = $latest->get($component.'|'.$probeKey);
                $isDue = ! $last || $last->checked_at->lte($now->copy()->subSeconds($every * 60 - 15));

                if ($isDue) {
                    $result = $this->runProbe($probe);
                    $probesRun++;

                    StatusCheck::query()->create([
                        'component' => $component,
                        'probe' => $probeKey,
                        'status' => $result->status,
                        'response_ms' => $result->responseMs,
                        'message' => $result->toArray()['message'],
                        'checked_at' => $now,
                    ]);

                    $results[$component][] = $result;
                } elseif ($last->checked_at->gte($now->copy()->subSeconds($every * 60 * 3 + 120))) {
                    $results[$component][] = new ProbeResult($last->status, $last->message, $last->response_ms);
                }
            }
        }

        return [$results, $probesRun];
    }

    private function runProbe(Probe $probe): ProbeResult
    {
        $startedAt = microtime(true);

        try {
            $result = $probe->run();
        } catch (Throwable $e) {
            $result = ProbeResult::outage('Check failed ('.class_basename($e).')');
        }

        $result->responseMs = (int) round((microtime(true) - $startedAt) * 1000);

        return $result;
    }

    private function dispatchQueueHeartbeats(CarbonInterface $now): void
    {
        foreach (config('system-status.queues', []) as $queue) {
            try {
                Cache::add(StatusHeartbeatJob::firstDispatchKey($queue['queue']), $now->timestamp, now()->addDay());

                StatusHeartbeatJob::dispatch($queue['queue'], $now->timestamp)
                    ->onConnection($queue['connection'] ?? null)
                    ->onQueue($queue['queue']);
            } catch (Throwable $e) {
                Log::warning('Status heartbeat job could not be dispatched.', [
                    'queue' => $queue['queue'],
                    'exception' => $e::class,
                ]);
            }
        }
    }

    /**
     * Requests and errors per component over the window, split into routes and signals.
     *
     * @return array<string, array{routes?: array{requests: int, errors: int, latency: array<int, int>}, signal?: array{requests: int, errors: int}}>
     */
    private function traffic(CarbonInterface $now): array
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

        return $traffic;
    }

    /**
     * @return array{0: ComponentStatus, 1: ?string, 2: bool} status, message, configured
     */
    private function evaluate(string $component, array $definition, array $probeResults, array $traffic, ?StatusIncident $manual = null): array
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
     * The most severe open manual incident of each component.
     *
     * @return array<string, StatusIncident>
     */
    private function openManualIncidents(): array
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

    /**
     * Failing components whose dependency ('depends_on') is failing too, mapped to the root
     * cause: the first failing dependency up the chain that does not fail because of another.
     *
     * @param  array<string, array{0: ComponentStatus, 1: ?string, 2: bool}>  $evaluated
     * @param  array<string, StatusMaintenance>  $maintenance
     * @return array<string, string>
     */
    private function dependencyFailures(array $evaluated, array $maintenance): array
    {
        $components = $this->registry->components();
        $failing = fn (string $c) => isset($evaluated[$c]) && ! isset($maintenance[$c]) && $evaluated[$c][0]->isProblem();
        $direct = [];

        foreach ($components as $component => $entry) {
            if (! $failing($component)) {
                continue;
            }

            foreach ((array) ($entry['definition']['depends_on'] ?? []) as $dependency) {
                if ($dependency !== $component && $failing($dependency)) {
                    $direct[$component] = $dependency;
                    break;
                }
            }
        }

        $roots = [];
        foreach ($direct as $component => $parent) {
            $seen = [$component => true];
            while (isset($direct[$parent]) && ! isset($seen[$parent])) {
                $seen[$parent] = true;
                $parent = $direct[$parent];
            }
            $roots[$component] = $parent;
        }

        return $roots;
    }

    /**
     * Confirms a status change once it has been seen `confirm_runs` runs in a row.
     */
    private function applyStatus(
        string $component,
        ComponentStatus $observed,
        ?string $message,
        bool $configured,
        CarbonInterface $now,
        array &$changes,
    ): StatusComponent {
        $state = StatusComponent::query()->firstOrNew(['component' => $component], [
            'status' => ComponentStatus::Operational,
            'candidate_runs' => 0,
        ]);

        $state->configured = $configured;
        $state->checked_at = $now;
        $wasAffectedBy = $state->affected_by;
        $state->affected_by = $this->affectedBy[$component] ?? null;

        // Leaving a maintenance window: take the observed status at once, without confirmation.
        // A problem found right after the work opens an incident as usual.
        if ($state->status === ComponentStatus::Maintenance) {
            $state->status = $observed;
            $state->message = $message;
            $state->changed_at = $now;
            $state->candidate_status = null;
            $state->candidate_runs = 0;
            $state->save();

            if ($observed !== ComponentStatus::Operational) {
                $changes[] = $component.': maintenance -> '.$observed->value;
                $this->recordIncident($component, ComponentStatus::Operational, $observed, $message, $now);
                $this->dispatchChange($component, ComponentStatus::Operational, $observed, $message, $now);
            }

            return $state;
        }

        if ($observed === $state->status) {
            $state->candidate_status = null;
            $state->candidate_runs = 0;
            $state->message = $message;
            $state->save();

            // The dependency recovered but this component did not: now it is its own problem.
            if ($wasAffectedBy && ! $state->affected_by && $observed->isProblem()) {
                StatusIncident::query()->open()->where('component', $component)->update(['affected_by' => null]);
                $name = $this->registry->label($component, 'en');
                $this->alerter->change($component, trim("🟠 {$name}: still {$this->statusWords($observed)} after ".$this->registry->label($wasAffectedBy, 'en').' recovered. '.($message ?? '')), $name);
            }

            return $state;
        }

        $previous = null;

        if ($state->candidate_status === $observed) {
            $state->candidate_runs++;
        } else {
            $state->candidate_status = $observed;
            $state->candidate_runs = 1;
        }

        if ($state->candidate_runs >= max(1, (int) $this->registry->threshold($component, 'confirm_runs', 2))) {
            $previous = $state->status;
            $state->status = $observed;
            $state->message = $message;
            $state->changed_at = $now;
            $state->candidate_status = null;
            $state->candidate_runs = 0;

            $changes[] = $component.': '.$previous->value.' -> '.$observed->value;
            $this->recordIncident($component, $previous, $observed, $message, $now);
        }

        $state->save();

        if ($previous !== null) {
            $this->dispatchChange($component, $previous, $observed, $message, $now);
        }

        return $state;
    }

    /**
     * A component inside a maintenance window shows Maintenance straight away: no confirmation,
     * no incident, no alert and no ComponentStatusChanged event.
     */
    private function applyMaintenance(string $component, StatusMaintenance $maintenance, bool $configured, CarbonInterface $now): StatusComponent
    {
        $state = StatusComponent::query()->firstOrNew(['component' => $component], [
            'status' => ComponentStatus::Operational,
            'candidate_runs' => 0,
        ]);

        if ($state->status !== ComponentStatus::Maintenance) {
            $state->changed_at = $now;
        }

        $state->status = ComponentStatus::Maintenance;
        $state->message = $maintenance->title;
        $state->configured = $configured;
        $state->candidate_status = null;
        $state->candidate_runs = 0;
        $state->checked_at = $now;
        $state->save();

        return $state;
    }

    /**
     * Active maintenance windows keyed by component. Fires MaintenanceStarted for windows that
     * just began and MaintenanceEnded for windows that are over.
     *
     * @return array<string, StatusMaintenance>
     */
    private function maintenanceWindows(CarbonInterface $now): array
    {
        try {
            StatusMaintenance::query()->active($now)->whereNull('announced_at')->get()
                ->each(function (StatusMaintenance $maintenance) use ($now) {
                    $maintenance->update(['announced_at' => $now]);
                    $this->safely(fn () => event(new MaintenanceStarted($maintenance)));
                    $this->alerter->event('maintenance.started', $this->maintenancePayload($maintenance));
                });

            StatusMaintenance::query()->where('ends_at', '<=', $now)->whereNull('ended_at')->get()
                ->each(function (StatusMaintenance $maintenance) use ($now) {
                    $maintenance->update(['ended_at' => $now, 'announced_at' => $maintenance->announced_at ?? $maintenance->starts_at]);
                    $this->safely(fn () => event(new MaintenanceEnded($maintenance)));
                    $this->alerter->event('maintenance.ended', $this->maintenancePayload($maintenance));
                });

            return StatusMaintenance::activeByComponent($now);
        } catch (Throwable $e) {
            Log::warning('Status maintenance windows could not be read.', ['exception' => $e::class]);

            return [];
        }
    }

    private function maintenancePayload(StatusMaintenance $maintenance): array
    {
        return [
            'id' => $maintenance->id,
            'title' => $maintenance->title,
            'components' => array_values((array) $maintenance->components),
            'starts_at' => $maintenance->starts_at->toIso8601String(),
            'ends_at' => $maintenance->ends_at->toIso8601String(),
        ];
    }

    /**
     * "Still down" reminders for major outages open longer than alerts.reminder_minutes, repeated
     * every reminder_minutes until resolved.
     *
     * @param  array<string, StatusMaintenance>  $maintenance
     */
    private function sendReminders(CarbonInterface $now, array $maintenance): void
    {
        $minutes = (int) config('system-status.alerts.reminder_minutes');
        if ($minutes <= 0) {
            return;
        }

        $due = $now->copy()->subMinutes($minutes);

        try {
            StatusIncident::query()->open()
                ->where('severity', ComponentStatus::MajorOutage->value)
                ->where('started_at', '<=', $due)
                ->where(fn ($query) => $query->whereNull('reminded_at')->orWhere('reminded_at', '<=', $due))
                ->get()
                ->each(function (StatusIncident $incident) use ($now, $maintenance) {
                    $incident->update(['reminded_at' => $now]);

                    if (isset($maintenance[$incident->component])) {
                        return;
                    }

                    $duration = StatusDuration::format((int) $incident->started_at->diffInMinutes($now));
                    $what = $incident->title ?: $incident->message;
                    $this->alerter->send(trim("🔴 Still down: {$this->registry->label($incident->component, 'en')} for {$duration}. ".($what ?? '')));
                });
        } catch (Throwable $e) {
            Log::warning('Status reminders could not be sent.', ['exception' => $e::class]);
        }
    }

    private function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            Log::warning('A System Status listener failed.', ['exception' => $e::class]);
        }
    }

    /**
     * Lets the application react to a confirmed change (ComponentStatusChanged). A failing
     * listener never stops the status run.
     */
    private function dispatchChange(
        string $component,
        ComponentStatus $previous,
        ComponentStatus $current,
        ?string $message,
        CarbonInterface $now,
    ): void {
        try {
            event(new ComponentStatusChanged($component, $this->registry->label($component), $previous, $current, $message, $now));
        } catch (Throwable $e) {
            Log::warning('A ComponentStatusChanged listener failed.', ['exception' => $e::class]);
        }

        $this->alerter->event('component.status_changed', [
            'component' => $component,
            'label' => $this->registry->label($component, 'en'),
            'previous' => $previous->value,
            'current' => $current->value,
            'message' => $message,
            'changed_at' => $now->toIso8601String(),
        ]);
    }

    private function recordIncident(
        string $component,
        ComponentStatus $previous,
        ComponentStatus $current,
        ?string $message,
        CarbonInterface $now,
    ): void {
        $open = StatusIncident::query()->open()->automatic()->where('component', $component)->latest('started_at')->first();
        $manual = StatusIncident::query()->open()->manual()->where('component', $component)->get()
            ->max(fn (StatusIncident $incident) => $incident->severity->severity());
        $name = $this->registry->label($component, 'en');

        if ($current === ComponentStatus::Operational) {
            if ($open) {
                $open->update(['resolved_at' => $now]);
                $duration = StatusDuration::format((int) $open->started_at->diffInMinutes($now));
                $text = "✅ {$name} recovered after {$duration}.";

                // Its outage was reported as part of the dependency's: no separate recovery alert.
                $open->affected_by ? Log::info('[system-status] '.$text) : $this->alerter->change($component, $text, $name, true);
            }

            return;
        }

        // People already know about it: a manual incident at this severity or worse is open.
        if (! $open && $manual !== null && $current->severity() <= $manual) {
            return;
        }

        if ($open) {
            if ($current->severity() > $open->severity->severity()) {
                $open->update(['severity' => $current, 'message' => $message]);
            }
        } else {
            StatusIncident::query()->create([
                'component' => $component,
                'severity' => $current,
                'message' => $message,
                'started_at' => $now,
                'affected_by' => $this->affectedBy[$component] ?? null,
            ]);
        }

        $icon = $current === ComponentStatus::MajorOutage ? '🔴' : '🟠';
        $label = $this->statusWords($current);
        $this->alerter->change($component, trim("{$icon} {$name}: {$label}. ".($message ?? '')), $name);
    }

    private function statusWords(ComponentStatus $status): string
    {
        return $status === ComponentStatus::MajorOutage ? 'major outage' : str_replace('_', ' ', $status->value);
    }

    /**
     * The scheduler cannot report its own outage, so the first run after a gap records it.
     */
    private function recordSchedulerGap(CarbonInterface $now): void
    {
        $lastRun = StatusComponent::query()->max('checked_at');
        if (! $lastRun) {
            return;
        }

        $lastRun = Carbon::parse($lastRun);
        $gapMinutes = (int) $lastRun->diffInMinutes($now);

        if ($gapMinutes <= (int) config('system-status.thresholds.heartbeat_stale_minutes', 3)) {
            return;
        }

        StatusIncident::query()->create([
            'component' => 'scheduler',
            'severity' => ComponentStatus::MajorOutage,
            'message' => "No status checks ran for {$gapMinutes} min (scheduler stopped or stuck).",
            'started_at' => $lastRun,
            'resolved_at' => $now,
        ]);

        $this->addDaily([[
            'component' => 'scheduler',
            'day' => $now->toDateString(),
            'runs' => $gapMinutes,
            'degraded_runs' => 0,
            'outage_runs' => $gapMinutes,
            'maintenance_runs' => 0,
        ]]);

        $this->alert("⚠️ Scheduler: no status checks ran from {$lastRun->format('Y-m-d H:i')} to {$now->format('H:i')} ({$gapMinutes} min). Running again now.");
    }

    private function dailyRow(string $component, ComponentStatus $status, CarbonInterface $now): array
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

    private function addDaily(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        DB::table('status_daily')->upsert(
            $rows,
            ['component', 'day'],
            StatusSql::incrementOnConflict('status_daily', ['runs', 'degraded_runs', 'outage_runs', 'maintenance_runs']),
        );
    }

    private function alert(string $text): void
    {
        $this->alerter->send($text);
    }

    /**
     * Rows of the 90-day history, keyed by component.
     *
     * @return Collection<string, Collection<int, object>>
     */
    public function dailyHistory(int $days = 90): Collection
    {
        return DB::table('status_daily')
            ->where('day', '>=', now()->subDays($days - 1)->toDateString())
            ->get()
            ->groupBy('component');
    }
}
