<?php

namespace Technobase\SystemStatus\Probes;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jobs that failed in the last `windowMinutes` (Laravel's failed_jobs table): degraded from
 * `degradedAt`, major outage from `outageAt`. Skipped when the table does not exist.
 */
class FailedJobsProbe implements Probe
{
    public function __construct(
        private readonly int $windowMinutes = 60,
        private readonly int $degradedAt = 5,
        private readonly int $outageAt = 50,
        private readonly ?string $table = null,
        private readonly ?string $connection = null,
    ) {}

    public function name(): string
    {
        return 'Failed jobs ('.$this->windowMinutes.' min)';
    }

    public function run(): ProbeResult
    {
        $table = $this->table ?? (string) config('queue.failed.table', 'failed_jobs');
        $connection = $this->connection ?? config('queue.failed.database');

        if (! Schema::connection($connection)->hasTable($table)) {
            return ProbeResult::skipped("No {$table} table");
        }

        $count = DB::connection($connection)->table($table)
            ->where('failed_at', '>=', now()->subMinutes($this->windowMinutes))
            ->count();

        $message = "{$count} jobs failed in the last {$this->windowMinutes} min";

        return match (true) {
            $count >= $this->outageAt => ProbeResult::outage($message),
            $count >= $this->degradedAt => ProbeResult::degraded($message),
            default => ProbeResult::ok($message),
        };
    }
}
