<?php

namespace Technobase\SystemStatus\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Technobase\SystemStatus\Models\StatusMaintenance;
use Technobase\SystemStatus\Services\MaintenanceService;
use Technobase\SystemStatus\Support\StatusRegistry;

class MaintenanceCommand extends Command
{
    protected $signature = 'status:maintenance
                            {components?* : Component keys covered by the window}
                            {--all : Cover every component}
                            {--minutes=30 : Length of the window}
                            {--start= : Start time (default: now), e.g. "2026-10-25 02:00"}
                            {--title= : Shown on the page (default: "Scheduled maintenance")}
                            {--message= : Optional details}
                            {--end= : End a window early: its ID, or "all" for every active window}
                            {--list : List active and upcoming windows}';

    protected $description = 'Schedule, end or list System Status maintenance windows (no incidents or alerts while they run).';

    public function handle(MaintenanceService $service, StatusRegistry $registry): int
    {
        if ($this->option('list')) {
            return $this->list();
        }

        if ($this->option('end') !== null) {
            return $this->end($service);
        }

        $components = $this->option('all') ? array_keys($registry->components()) : (array) $this->argument('components');

        if ($components === []) {
            $this->error('Name at least one component, or use --all.');

            return self::FAILURE;
        }

        $start = $this->option('start') ? Carbon::parse($this->option('start')) : now();
        $end = $start->copy()->addMinutes(max(1, (int) $this->option('minutes')));

        try {
            $maintenance = $service->schedule(
                $components,
                $start,
                $end,
                $this->option('title') ?: __('system-status::status.status_maintenance_default_title'),
                $this->option('message'),
                'console',
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Maintenance #{$maintenance->id}: ".implode(', ', $components)." from {$start->format('Y-m-d H:i')} to {$end->format('Y-m-d H:i')}.");

        return self::SUCCESS;
    }

    private function end(MaintenanceService $service): int
    {
        $id = (string) $this->option('end');
        $windows = $id === 'all'
            ? StatusMaintenance::query()->active()->get()
            : StatusMaintenance::query()->whereKey((int) $id)->get();

        if ($windows->isEmpty()) {
            $this->warn('No matching maintenance window.');

            return $id === 'all' ? self::SUCCESS : self::FAILURE;
        }

        $windows->each(fn (StatusMaintenance $maintenance) => $service->end($maintenance));
        $this->info('Ended: #'.$windows->pluck('id')->implode(', #'));

        return self::SUCCESS;
    }

    private function list(): int
    {
        $rows = StatusMaintenance::query()->where('ends_at', '>', now())->orderBy('starts_at')->get()
            ->map(fn (StatusMaintenance $m) => [
                $m->id,
                $m->title,
                implode(', ', (array) $m->components),
                $m->starts_at->format('Y-m-d H:i'),
                $m->ends_at->format('Y-m-d H:i'),
                $m->isActive() ? 'active' : 'upcoming',
            ])->all();

        $this->table(['ID', 'Title', 'Components', 'Starts', 'Ends', 'State'], $rows);

        return self::SUCCESS;
    }
}
