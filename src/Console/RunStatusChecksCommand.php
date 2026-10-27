<?php

namespace Technobase\SystemStatus\Console;

use Illuminate\Console\Command;
use Technobase\SystemStatus\Services\StatusService;

class RunStatusChecksCommand extends Command
{
    protected $signature = 'status:check';

    protected $description = 'Run the System Status checks: probes, traffic error rates, incidents and alerts.';

    public function handle(StatusService $service): int
    {
        if (! config('system-status.enabled', true)) {
            $this->warn('System Status is turned off (SYSTEM_STATUS_ENABLED=false); nothing was checked.');

            return self::SUCCESS;
        }

        $result = $service->run();

        $this->info("Probes run: {$result['probes_run']}. Traffic minutes flushed: {$result['flushed_minutes']}.");

        foreach ($result['changes'] as $change) {
            $this->line('Status changed: '.$change);
        }

        return self::SUCCESS;
    }
}
