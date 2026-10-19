<?php

namespace Technobase\SystemStatus;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\ServiceProvider;
use Technobase\SystemStatus\Console\GenerateApiTokenCommand;
use Technobase\SystemStatus\Console\ListStatusRoutesCommand;
use Technobase\SystemStatus\Console\PruneStatusCommand;
use Technobase\SystemStatus\Console\RunStatusChecksCommand;
use Technobase\SystemStatus\Http\Middleware\RecordRouteHealth;

class SystemStatusServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/system-status.php', 'system-status');
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'system-status');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'system-status');

        if (config('system-status.record_traffic', true) && $this->app->bound(Kernel::class)) {
            // Counts requests and 5xx per component after the response is sent.
            $this->app->make(Kernel::class)->pushMiddleware(RecordRouteHealth::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                RunStatusChecksCommand::class,
                PruneStatusCommand::class,
                ListStatusRoutesCommand::class,
                GenerateApiTokenCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/system-status.php' => config_path('system-status.php'),
            ], 'system-status-config');

            $this->publishes([
                __DIR__.'/../lang' => $this->app->langPath('vendor/system-status'),
            ], 'system-status-lang');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/system-status'),
            ], 'system-status-views');

            // A Claude Code skill that teaches the assistant how this package works in the app.
            $this->publishes([
                __DIR__.'/../resources/claude/SKILL.md' => base_path('.claude/skills/system-status/SKILL.md'),
            ], 'system-status-skill');
        }

        if (config('system-status.schedule', true)) {
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
                // The overlap lock expires after 5 minutes so a killed run cannot stop the checks.
                $schedule->command('status:check')->everyMinute()->withoutOverlapping(5)->runInBackground();
                $schedule->command('status:prune')->dailyAt('03:30')->withoutOverlapping();
            });
        }
    }
}
