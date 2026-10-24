<?php

namespace Technobase\SystemStatus\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PruneStatusCommand extends Command
{
    protected $signature = 'status:prune';

    protected $description = 'Delete System Status history older than the retention set in config/system-status.php.';

    public function handle(): int
    {
        $days = config('system-status.retention_days');

        $deleted = [
            'status_checks' => DB::table('status_checks')
                ->where('checked_at', '<', now()->subDays($days['checks']))->delete(),
            'status_route_buckets' => DB::table('status_route_buckets')
                ->where('bucket_at', '<', now()->subDays($days['route_buckets']))->delete(),
            'status_daily' => DB::table('status_daily')
                ->where('day', '<', now()->subDays($days['daily'])->toDateString())->delete(),
            // Timeline entries go with their incident (also on databases without foreign keys).
            'status_incident_updates' => Schema::hasTable('status_incident_updates')
                ? DB::table('status_incident_updates')->whereIn('incident_id', DB::table('status_incidents')->select('id')
                    ->whereNotNull('resolved_at')
                    ->where('resolved_at', '<', now()->subDays($days['incidents'])))->delete()
                : 0,
            'status_incidents' => DB::table('status_incidents')
                ->whereNotNull('resolved_at')
                ->where('resolved_at', '<', now()->subDays($days['incidents']))->delete(),
            'status_maintenances' => Schema::hasTable('status_maintenances')
                ? DB::table('status_maintenances')->where('ends_at', '<', now()->subDays($days['incidents']))->delete()
                : 0,
            'status_deploys' => Schema::hasTable('status_deploys')
                ? DB::table('status_deploys')->where('deployed_at', '<', now()->subDays($days['incidents']))->delete()
                : 0,
        ];

        foreach ($deleted as $table => $count) {
            $this->line("{$table}: {$count} rows deleted");
        }

        return self::SUCCESS;
    }
}
