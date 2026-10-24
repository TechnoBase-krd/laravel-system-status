<?php

namespace Technobase\SystemStatus\Services;

use Carbon\CarbonInterface;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ReflectionMethod;
use Technobase\SystemStatus\Jobs\StatusHeartbeatJob;
use Technobase\SystemStatus\Models\StatusCheck;
use Technobase\SystemStatus\Probes\ConcurrentProbe;
use Technobase\SystemStatus\Probes\Probe;
use Technobase\SystemStatus\Probes\ProbeResult;
use Technobase\SystemStatus\Support\StatusRegistry;
use Throwable;

/**
 * Runs the probes that are due in a status:check run and stores each result in status_checks.
 * Probes that make one HTTP request (ConcurrentProbe) are sent together, so a run takes about as
 * long as the slowest of them instead of their sum.
 */
class ProbeRunner
{
    public function __construct(private readonly StatusRegistry $registry) {}

    /**
     * Returns, per component, the latest usable result of each of its probes (fresh or from an
     * earlier run), in the order they are configured, and how many probes ran.
     *
     * @return array{0: array<string, array<int, ProbeResult>>, 1: int}
     */
    public function run(CarbonInterface $now): array
    {
        $latest = StatusCheck::query()
            ->where('checked_at', '>=', $now->copy()->subHour())
            ->orderByDesc('checked_at')
            ->get()
            ->unique(fn (StatusCheck $check) => $check->component.'|'.$check->probe)
            ->keyBy(fn (StatusCheck $check) => $check->component.'|'.$check->probe);

        $results = [];
        $due = [];
        $concurrent = (bool) config('system-status.concurrent_probes', true);

        foreach ($this->registry->components() as $component => $entry) {
            foreach ($entry['definition']['probes'] ?? [] as $index => $definition) {
                $every = max(1, (int) ($definition['every'] ?? 1));

                try {
                    /** @var Probe $probe */
                    $probe = app()->make($definition['class'], $definition['with'] ?? []);
                    $probeKey = mb_substr($index.'|'.$probe->name(), 0, 128);
                } catch (Throwable $e) {
                    $results[$component][$index] = ProbeResult::outage('Probe could not be created ('.class_basename($e).')');

                    continue;
                }

                $last = $latest->get($component.'|'.$probeKey);
                $isDue = ! $last || $last->checked_at->lte($now->copy()->subSeconds($every * 60 - 15));

                if ($isDue) {
                    $due[] = [$component, $index, $probeKey, $probe];
                } elseif ($last->checked_at->gte($now->copy()->subSeconds($every * 60 * 3 + 120))) {
                    $results[$component][$index] = new ProbeResult($last->status, $last->message, $last->response_ms);
                }
            }
        }

        $pooled = $concurrent
            ? array_filter($due, fn (array $item) => $this->canPool($item[3]))
            : [];

        $fresh = $this->runPooled($pooled);

        foreach ($due as $key => [$component, $index, $probeKey, $probe]) {
            $result = $fresh[$key] ?? $this->runOne($probe);

            StatusCheck::query()->create([
                'component' => $component,
                'probe' => $probeKey,
                'status' => $result->status,
                'response_ms' => $result->responseMs,
                'message' => $result->toArray()['message'],
                'checked_at' => $now,
            ]);

            $results[$component][$index] = $result;
        }

        return [array_map(function (array $byIndex) {
            ksort($byIndex);

            return array_values($byIndex);
        }, $results), count($due)];
    }

    public function dispatchQueueHeartbeats(CarbonInterface $now): void
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
     * A ConcurrentProbe whose run() comes from the same class as its send(): a subclass that
     * overrides only run() (e.g. of HttpEndpointProbe) keeps running through run().
     */
    private function canPool(Probe $probe): bool
    {
        if (! $probe instanceof ConcurrentProbe) {
            return false;
        }

        return (new ReflectionMethod($probe, 'run'))->getDeclaringClass()->getName()
            === (new ReflectionMethod($probe, 'send'))->getDeclaringClass()->getName();
    }

    private function runOne(Probe $probe): ProbeResult
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

    /**
     * Sends the requests of the given probes in one pool. Returns results by the same keys; a
     * probe left out (the pool failed) runs on its own afterwards.
     *
     * @param  array<int, array{0: string, 1: int|string, 2: string, 3: ConcurrentProbe}>  $items
     * @return array<int, ProbeResult>
     */
    private function runPooled(array $items): array
    {
        $results = [];
        $send = [];

        foreach ($items as $key => $item) {
            try {
                $precheck = $item[3]->precheck();
            } catch (Throwable $e) {
                $precheck = ProbeResult::outage('Check failed ('.class_basename($e).')');
            }

            if ($precheck) {
                $precheck->responseMs = 0;
                $results[$key] = $precheck;
            } else {
                $send[$key] = $item[3];
            }
        }

        // One request does not need a pool.
        if (count($send) < 2) {
            return $results;
        }

        $startedAt = microtime(true);

        try {
            $responses = Http::pool(function (Pool $pool) use ($send) {
                foreach ($send as $key => $probe) {
                    $probe->send($pool->as('probe'.$key));
                }
            });
        } catch (Throwable $e) {
            Log::warning('Status probes could not be sent together; running them one by one.', ['exception' => $e::class]);

            return $results;
        }

        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        foreach ($send as $key => $probe) {
            $response = $responses['probe'.$key] ?? null;

            try {
                $result = $response instanceof Response || $response instanceof Throwable
                    ? $probe->result($response)
                    : ProbeResult::outage('Check failed (no response)');
            } catch (Throwable $e) {
                $result = ProbeResult::outage('Check failed ('.class_basename($e).')');
            }

            $seconds = $response instanceof Response ? $response->transferStats?->getTransferTime() : null;
            $result->responseMs = $seconds !== null ? (int) round($seconds * 1000) : $elapsedMs;
            $results[$key] = $result;
        }

        return $results;
    }
}
