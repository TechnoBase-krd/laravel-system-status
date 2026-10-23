<?php

namespace Technobase\SystemStatus\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Technobase\SystemStatus\Models\StatusDeploy;
use Technobase\SystemStatus\Services\DeployService;

class DeployCommand extends Command
{
    protected $signature = 'status:deploy
                            {release? : The version or tag (default: read from git, e.g. v1.8.2 or a1b2c3d)}
                            {--commit= : The commit (default: read from git)}
                            {--note= : A short note, e.g. "New checkout"}
                            {--by= : Who deployed (default: the system user)}
                            {--at= : When (default: now), e.g. "2026-10-25 14:05"}
                            {--list : List the last deploys}';

    protected $description = 'Record a deploy: a marker on the System Status charts, named by incidents that start soon after.';

    public function handle(DeployService $service): int
    {
        if ($this->option('list')) {
            return $this->list();
        }

        [$detectedVersion, $detectedCommit] = $this->argument('release') && $this->option('commit')
            ? [null, null]
            : $service->detect();

        $version = $this->argument('release') ?: $detectedVersion;

        if (! $version) {
            $this->error('No version given and none found in git. Run: php artisan status:deploy 1.8.2');

            return self::FAILURE;
        }

        $deploy = $service->record(
            $version,
            $this->option('commit') ?: $detectedCommit,
            $this->option('note'),
            $this->option('by') ?: (get_current_user() ?: null),
            $this->option('at') ? Carbon::parse($this->option('at')) : null,
        );

        $this->info("Deploy {$deploy->label()} recorded at {$deploy->deployed_at->format('Y-m-d H:i:s')}.");

        return self::SUCCESS;
    }

    private function list(): int
    {
        $deploys = StatusDeploy::query()->latest('deployed_at')->latest('id')->limit(20)->get();

        if ($deploys->isEmpty()) {
            $this->line('No deploys recorded yet.');

            return self::SUCCESS;
        }

        $this->table(['ID', 'Version', 'Deployed at', 'By', 'Note', 'Worse after'], $deploys->map(fn (StatusDeploy $deploy) => [
            $deploy->id,
            $deploy->label(),
            $deploy->deployed_at->format('Y-m-d H:i'),
            $deploy->deployed_by ?? '—',
            $deploy->note ?? '',
            $deploy->compared_at === null ? '—' : (collect($deploy->comparison ?? [])->pluck('component')->implode(', ') ?: 'no'),
        ])->all());

        return self::SUCCESS;
    }
}
