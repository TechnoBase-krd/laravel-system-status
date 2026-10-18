<?php

namespace Technobase\SystemStatus\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\Telegram\TelegramMessage;
use Technobase\SystemStatus\Enums\ComponentStatus;
use Technobase\SystemStatus\Events\ComponentStatusChanged;
use Technobase\SystemStatus\Jobs\StatusHeartbeatJob;
use Technobase\SystemStatus\Models\StatusCheck;
use Technobase\SystemStatus\Models\StatusComponent;
use Technobase\SystemStatus\Models\StatusIncident;
use Technobase\SystemStatus\Notifications\StatusAlertNotification;
use Technobase\SystemStatus\Probes\Probe;
use Technobase\SystemStatus\Probes\ProbeResult;
use Technobase\SystemStatus\Support\StatusMetrics;
use Technobase\SystemStatus\Support\StatusRegistry;
use Technobase\SystemStatus\Support\StatusSignal;
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
    ) {}

    /**
     * @return array{flushed_minutes: int, probes_run: int, changes: array<int, string>}
     */
    public function run(): array
    {
        $now = now();
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

        $changes = [];
        $daily = [];

        foreach ($this->registry->components() as $component => $entry) {
            [$status, $message, $configured] = $this->evaluate(
                $component,
                $entry['definition'],
                $probeResults[$component] ?? [],
                $traffic[$component] ?? [],
            );

            $state = $this->applyStatus($component, $status, $message, $configured, $now, $changes);
            $daily[] = $this->dailyRow($component, $state->status, $now);
        }

        $this->addDaily($daily);

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
     * @return array<string, array{routes?: array{requests: int, errors: int}, signal?: array{requests: int, errors: int}}>
     */
    private function traffic(CarbonInterface $now): array
    {
        $since = $now->copy()->subMinutes((int) config('system-status.thresholds.window_minutes', 15));

        $rows = DB::table('status_route_buckets')
            ->where('bucket_at', '>=', $since)
            ->selectRaw("component, CASE WHEN route = '".StatusMetrics::SIGNAL_ROUTE."' THEN 1 ELSE 0 END AS is_signal")
            ->selectRaw('SUM(requests) AS requests, SUM(errors) AS errors')
            ->groupByRaw('component, is_signal')
            ->get();

        $traffic = [];
        foreach ($rows as $row) {
            $traffic[$row->component][(int) $row->is_signal === 1 ? 'signal' : 'routes'] = [
                'requests' => (int) $row->requests,
                'errors' => (int) $row->errors,
            ];
        }

        return $traffic;
    }

    /**
     * @return array{0: ComponentStatus, 1: ?string, 2: bool} status, message, configured
     */
    private function evaluate(string $component, array $definition, array $probeResults, array $traffic): array
    {
        $sources = [];

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
                $traffic['routes'],
                (int) config('system-status.thresholds.min_requests', 20),
                ':errors of :requests requests failed (5xx) in the last :minutes min',
            );
        }

        if (isset($traffic['signal'])) {
            $source = $this->rateSource(
                $traffic['signal'],
                (int) config('system-status.thresholds.min_signals', 3),
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
     * @return array{0: ComponentStatus, 1: ?string}
     */
    private function rateSource(array $counts, int $minimum, string $template): array
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
            $rate >= (float) config('system-status.thresholds.outage_error_rate', 25) => [ComponentStatus::MajorOutage, $message],
            $rate >= (float) config('system-status.thresholds.degraded_error_rate', 5) => [ComponentStatus::Degraded, $message],
            default => [ComponentStatus::Operational, null],
        };
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

        if ($observed === $state->status) {
            $state->candidate_status = null;
            $state->candidate_runs = 0;
            $state->message = $message;
            $state->save();

            return $state;
        }

        $previous = null;

        if ($state->candidate_status === $observed) {
            $state->candidate_runs++;
        } else {
            $state->candidate_status = $observed;
            $state->candidate_runs = 1;
        }

        if ($state->candidate_runs >= max(1, (int) config('system-status.thresholds.confirm_runs', 2))) {
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
    }

    private function recordIncident(
        string $component,
        ComponentStatus $previous,
        ComponentStatus $current,
        ?string $message,
        CarbonInterface $now,
    ): void {
        $open = StatusIncident::query()->open()->where('component', $component)->latest('started_at')->first();
        $name = $this->registry->label($component, 'en');

        if ($current === ComponentStatus::Operational) {
            if ($open) {
                $open->update(['resolved_at' => $now]);
                $minutes = max(1, (int) $open->started_at->diffInMinutes($now));
                $this->alert("✅ {$name} recovered after {$minutes} min.");
            }

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
            ]);
        }

        $icon = $current === ComponentStatus::MajorOutage ? '🔴' : '🟠';
        $label = $current === ComponentStatus::MajorOutage ? 'major outage' : 'degraded';
        $this->alert(trim("{$icon} {$name}: {$label}. ".($message ?? '')));
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
        ];
    }

    private function addDaily(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        DB::table('status_daily')->upsert($rows, ['component', 'day'], [
            'runs' => DB::raw('status_daily.runs + excluded.runs'),
            'degraded_runs' => DB::raw('status_daily.degraded_runs + excluded.degraded_runs'),
            'outage_runs' => DB::raw('status_daily.outage_runs + excluded.outage_runs'),
        ]);
    }

    /**
     * Sends a plain-text alert to the Telegram chat in system-status.alerts.telegram_chat_id.
     * Nothing is sent in `local`, while the bot or chat is not configured, or without the
     * laravel-notification-channels/telegram package; the alert is logged instead.
     */
    private function alert(string $text): void
    {
        $chatId = config('system-status.alerts.telegram_chat_id');

        if (! config('system-status.alerts.enabled', true)
            || app()->environment('local')
            || blank($chatId)
            || blank(config('services.telegram-bot-api.token'))
            || ! class_exists(TelegramMessage::class)) {
            Log::info('[system-status] '.$text);

            return;
        }

        try {
            $prefix = (string) config('system-status.alerts.prefix', '['.config('app.name').' status]');

            Notification::route('telegram', $chatId)->notifyNow(new StatusAlertNotification(trim($prefix.' '.$text)));
        } catch (Throwable $e) {
            Log::warning('System status alert could not be sent.', ['exception' => $e::class]);
        }
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
