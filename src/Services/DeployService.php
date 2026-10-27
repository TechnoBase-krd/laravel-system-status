<?php

namespace Technobase\SystemStatus\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use Technobase\SystemStatus\Events\DeployRecorded;
use Technobase\SystemStatus\Models\StatusDeploy;
use Technobase\SystemStatus\Support\FileManifest;
use Technobase\SystemStatus\Support\LatencyHistogram;
use Technobase\SystemStatus\Support\StatusAlerter;
use Technobase\SystemStatus\Support\StatusMetrics;
use Technobase\SystemStatus\Support\StatusRegistry;
use Throwable;

/**
 * Records deploys (status:deploy, POST {path}/deploys) and, when deploys.compare is on, compares
 * each component's error rate and p95 before and after a deploy.
 */
class DeployService
{
    public function __construct(
        private readonly StatusAlerter $alerter,
        private readonly StatusRegistry $registry,
    ) {}

    public function record(
        string $version,
        ?string $commit = null,
        ?string $note = null,
        ?string $deployedBy = null,
        ?CarbonInterface $deployedAt = null,
    ): StatusDeploy {
        $version = trim($version);

        if ($version === '') {
            throw new InvalidArgumentException('The deploy needs a version.');
        }

        $deploy = StatusDeploy::query()->create([
            'version' => mb_substr($version, 0, 100),
            'commit' => filled($commit) ? mb_substr(trim($commit), 0, 64) : null,
            'note' => filled($note) ? mb_substr(trim($note), 0, 500) : null,
            'deployed_by' => filled($deployedBy) ? mb_substr(trim($deployedBy), 0, 191) : null,
            'deployed_at' => $deployedAt ?? now(),
        ]);

        try {
            event(new DeployRecorded($deploy));
        } catch (Throwable $e) {
            Log::warning('A DeployRecorded listener failed.', ['exception' => $e::class]);
        }

        $this->alerter->event('deploy.recorded', $this->payload($deploy));

        // The code files of this deploy become the baseline of SecurityFilesProbe.
        if (config('system-status.security.enabled', false) && config('system-status.security.files.baseline_on_deploy', true)) {
            try {
                FileManifest::saveBaseline();
            } catch (Throwable $e) {
                Log::warning('The code file baseline could not be saved.', ['exception' => $e::class]);
            }
        }

        if (config('system-status.deploys.announce', false)) {
            $this->alerter->send(trim('🚀 Deployed '.$deploy->label().($deploy->note ? ' — '.$deploy->note : '')));
        }

        return $deploy;
    }

    /**
     * The version and commit of the code in base_path(), read from git: [version, commit].
     * Null when git is not available (e.g. a deploy that copies files without .git).
     *
     * @return array{0: ?string, 1: ?string}
     */
    public function detect(): array
    {
        $run = function (string $command): ?string {
            try {
                $result = Process::path(base_path())->timeout(5)->run($command);

                return $result->successful() && trim($result->output()) !== '' ? trim($result->output()) : null;
            } catch (Throwable) {
                return null;
            }
        };

        $commit = $run('git rev-parse --short HEAD');

        return [$commit ? ($run('git describe --tags --always') ?? $commit) : null, $commit];
    }

    public function payload(StatusDeploy $deploy): array
    {
        return [
            'id' => $deploy->id,
            'version' => $deploy->version,
            'commit' => $deploy->commit,
            'note' => $deploy->note,
            'deployed_by' => $deploy->deployed_by,
            'deployed_at' => $deploy->deployed_at->toIso8601String(),
        ];
    }

    /**
     * Called by every status:check run: compares deploys that are `after_minutes` old (and not
     * older than two hours, so turning the option on does not alert about old deploys).
     */
    public function compareDue(CarbonInterface $now): void
    {
        if (! config('system-status.deploys.compare.enabled', false)) {
            return;
        }

        $minutes = max(5, (int) config('system-status.deploys.compare.after_minutes', 15));

        StatusDeploy::query()
            ->whereNull('compared_at')
            ->where('deployed_at', '<=', $now->copy()->subMinutes($minutes))
            ->where('deployed_at', '>=', $now->copy()->subMinutes($minutes + 120))
            ->orderBy('deployed_at')
            ->get()
            ->each(fn (StatusDeploy $deploy) => $this->compare($deploy, $minutes, $now));
    }

    /**
     * @return array<int, array<string, mixed>> the components that got worse
     */
    public function compare(StatusDeploy $deploy, int $minutes, ?CarbonInterface $now = null): array
    {
        $at = $deploy->deployed_at;
        $before = $this->window($at->copy()->subMinutes($minutes), $at);
        $after = $this->window($at, $at->copy()->addMinutes($minutes));

        $rateIncrease = (float) config('system-status.deploys.compare.error_rate_increase', 2);
        $p95Increase = (float) config('system-status.deploys.compare.p95_increase_percent', 50);
        $worse = [];

        foreach ($after as $component => $counts) {
            if (! $this->registry->exists($component)) {
                continue;
            }

            $minimum = (int) $this->registry->threshold($component, 'min_requests', 20);
            if ($counts['requests'] < $minimum) {
                continue;
            }

            $old = $before[$component] ?? ['requests' => 0, 'errors' => 0, 'latency' => []];
            $rateAfter = round($counts['errors'] / $counts['requests'] * 100, 1);
            $rateBefore = $old['requests'] > 0 ? round($old['errors'] / $old['requests'] * 100, 1) : 0.0;
            $p95After = LatencyHistogram::percentile($counts['latency'], 95);
            $p95Before = $old['requests'] >= $minimum ? LatencyHistogram::percentile($old['latency'], 95) : null;

            $errorsWorse = $rateAfter - $rateBefore >= $rateIncrease;
            $slower = $p95After !== null && $p95Before !== null
                && $p95After >= $p95Before * (1 + $p95Increase / 100)
                && $p95After - $p95Before >= 100;

            if ($errorsWorse || $slower) {
                $worse[] = [
                    'component' => $component,
                    'error_rate_before' => $rateBefore,
                    'error_rate_after' => $rateAfter,
                    'p95_before' => $p95Before,
                    'p95_after' => $p95After,
                    'errors_worse' => $errorsWorse,
                    'slower' => $slower,
                ];
            }
        }

        $deploy->update(['compared_at' => $now ?? now(), 'comparison' => $worse]);

        if ($worse !== []) {
            $this->alerter->send($this->regressionText($deploy, $worse, $minutes));
            $this->alerter->event('deploy.regression', $this->payload($deploy) + ['components' => $worse]);
        }

        return $worse;
    }

    /**
     * @param  array<int, array<string, mixed>>  $worse
     */
    private function regressionText(StatusDeploy $deploy, array $worse, int $minutes): string
    {
        $lines = array_map(function (array $item) {
            $parts = [];
            if ($item['errors_worse']) {
                $parts[] = "errors {$item['error_rate_before']}% → {$item['error_rate_after']}%";
            }
            if ($item['slower']) {
                $parts[] = "p95 {$item['p95_before']} → {$item['p95_after']} ms";
            }

            return $this->registry->label($item['component'], 'en').': '.implode(', ', $parts);
        }, $worse);

        return "⚠️ Worse in the {$minutes} min after deploy {$deploy->label()}: ".implode('; ', $lines).'.';
    }

    /**
     * Requests, errors and latency per component between $from and $to (route traffic only).
     *
     * @return array<string, array{requests: int, errors: int, latency: array<int, int>}>
     */
    private function window(CarbonInterface $from, CarbonInterface $to): array
    {
        return DB::table('status_route_buckets')
            ->where('bucket_at', '>=', $from)
            ->where('bucket_at', '<', $to)
            ->where('route', '!=', StatusMetrics::SIGNAL_ROUTE)
            ->select('component')
            ->selectRaw('SUM(requests) AS requests, SUM(errors) AS errors, '.LatencyHistogram::sumSelect())
            ->groupBy('component')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->component => [
                'requests' => (int) $row->requests,
                'errors' => (int) $row->errors,
                'latency' => LatencyHistogram::fromRow($row),
            ]])
            ->all();
    }
}
