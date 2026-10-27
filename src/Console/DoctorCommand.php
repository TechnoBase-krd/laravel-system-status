<?php

namespace Technobase\SystemStatus\Console;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Routing\Router;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use NotificationChannels\Telegram\TelegramMessage;
use Technobase\SystemStatus\Models\StatusComponent;
use Technobase\SystemStatus\Probes\HttpEndpointProbe;
use Technobase\SystemStatus\Probes\Probe;
use Technobase\SystemStatus\Probes\SecurityConfigProbe;
use Technobase\SystemStatus\Support\RouteComponentResolver;
use Technobase\SystemStatus\Support\StatusAlerter;
use Technobase\SystemStatus\Support\StatusHeartbeat;
use Technobase\SystemStatus\Support\StatusRegistry;
use Throwable;

/**
 * Checks the usual installation problems and prints one line per check.
 */
class DoctorCommand extends Command
{
    protected $signature = 'status:doctor {--no-http : Skip the check that requests APP_URL/.env}';

    protected $description = 'Check the System Status setup (tables, Redis, scheduler, components, probes, labels, alerts) and dangerous settings of the application.';

    /** @var array<int, array{0: string, 1: string, 2: string}> */
    private array $rows = [];

    public function handle(StatusRegistry $registry, Router $router, RouteComponentResolver $resolver, StatusAlerter $alerter): int
    {
        if (! config('system-status.enabled', true)) {
            $this->reportWarn('Package', 'Turned off (SYSTEM_STATUS_ENABLED=false): no counting, checks or alerts, and the page answers 404.');
        }

        $this->tables();
        $this->redis();
        $this->scheduler();
        $this->components($registry, $router, $resolver);
        $this->probes($registry);
        $this->labels($registry);
        $this->alerts($alerter);
        $this->cachedConfig($registry);
        $this->security();
        $this->dangerousSettings();

        $this->table(['', 'Check', 'Result'], array_map(fn ($row) => [match ($row[0]) {
            'ok' => '<info>✓</info>',
            'warn' => '<comment>!</comment>',
            default => '<error>✗</error>',
        }, $row[1], $row[2]], $this->rows));

        $failures = count(array_filter($this->rows, fn ($row) => $row[0] === 'fail'));
        $warnings = count(array_filter($this->rows, fn ($row) => $row[0] === 'warn'));
        $this->line("{$failures} problem(s), {$warnings} warning(s).");

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function tables(): void
    {
        $expected = [
            'status_components' => ['affected_by'],
            'status_checks' => [],
            'status_daily' => ['maintenance_runs'],
            'status_route_buckets' => ['le_inf'],
            'status_incidents' => ['source', 'affected_by'],
            'status_incident_updates' => [],
            'status_maintenances' => [],
            'status_monthly' => [],
            'status_deploys' => [],
            'status_security_events' => ['severity'],
            'status_security_blocks' => [],
            'status_security_logins' => [],
        ];

        try {
            $missing = [];
            foreach ($expected as $table => $columns) {
                if (! Schema::hasTable($table)) {
                    $missing[] = $table;

                    continue;
                }
                foreach ($columns as $column) {
                    if (! Schema::hasColumn($table, $column)) {
                        $missing[] = $table.'.'.$column;
                    }
                }
            }

            $missing
                ? $this->reportFail('Database tables', 'Missing '.implode(', ', $missing).'. Run php artisan migrate.')
                : $this->reportOk('Database tables', 'All status tables and columns exist.');
        } catch (Throwable $e) {
            $this->reportFail('Database tables', 'Database not reachable ('.class_basename($e).').');
        }
    }

    private function redis(): void
    {
        try {
            Redis::connection(config('system-status.redis_connection'))->ping();
            $this->reportOk('Redis', 'Reachable (traffic counters work).');
        } catch (Throwable $e) {
            $this->reportFail('Redis', 'Not reachable ('.class_basename($e).'): requests are not counted.');

            return;
        }

        $connection = config('system-status.redis_connection') ?: 'default';
        $prefix = (string) (config("database.redis.{$connection}.prefix") ?? config('database.redis.options.prefix', ''));

        if ($prefix === '' || in_array($prefix, ['laravel_database_', 'laravel-database-'], true)) {
            $this->reportWarn('Redis prefix', 'The prefix is "'.$prefix.'". Applications sharing this Redis would read each other\'s traffic; set REDIS_PREFIX or APP_NAME.');
        } else {
            $this->reportOk('Redis prefix', '"'.$prefix.'"');
        }
    }

    private function scheduler(): void
    {
        try {
            $lastRun = StatusComponent::query()->max('checked_at');
        } catch (Throwable) {
            return;
        }

        if (! $lastRun) {
            $this->reportWarn('Scheduler', 'status:check has never run. Start the scheduler (schedule:work or a cron entry for schedule:run).');

            return;
        }

        $minutes = (int) Carbon::parse($lastRun)->diffInMinutes(now());
        $minutes > (int) config('system-status.thresholds.heartbeat_stale_minutes', 3)
            ? $this->reportFail('Scheduler', "status:check last ran {$minutes} min ago. Is the scheduler running?")
            : $this->reportOk('Scheduler', 'status:check ran '.($minutes ? "{$minutes} min ago" : 'within the last minute').'.');

        $heartbeat = StatusHeartbeat::get();
        if ($heartbeat['failing_since']) {
            $since = Carbon::createFromTimestamp($heartbeat['failing_since'], config('app.timezone'))->format('Y-m-d H:i');
            $this->reportFail('Last run', "status:check has been failing since {$since} ({$heartbeat['error']}). Run php artisan status:check to see the error.");
        }

        if (! config('system-status.schedule', true)) {
            $this->reportWarn('Schedule', 'system-status.schedule is off: register status:check (every minute) and status:prune yourself.');
        }
    }

    private function components(StatusRegistry $registry, Router $router, RouteComponentResolver $resolver): void
    {
        $components = $registry->components();
        $routed = [];

        foreach ($router->getRoutes() as $route) {
            if ($component = $resolver->resolve($route->getName(), $route->uri())) {
                $routed[$component] = true;
            }
        }

        // Fallback components collect every route no rule matched.
        foreach ((array) config('system-status.fallback', []) as $fallback) {
            $routed[$fallback] = true;
        }

        $silent = [];
        $unknownDependencies = [];

        foreach ($components as $component => $entry) {
            $definition = $entry['definition'];

            if ($component !== 'scheduler' && empty($definition['probes']) && empty($definition['signal']) && ! isset($routed[$component])) {
                $silent[] = $component;
            }

            foreach ((array) ($definition['depends_on'] ?? []) as $dependency) {
                if (! isset($components[$dependency])) {
                    $unknownDependencies[] = "{$component} → {$dependency}";
                }
            }
        }

        $silent
            ? $this->reportWarn('Component sources', 'No probe, route or signal: '.implode(', ', $silent).'. They always show "No data".')
            : $this->reportOk('Component sources', count($components).' components, each with at least one source.');

        foreach ((array) config('system-status.fallback', []) as $component) {
            if (! isset($components[$component])) {
                $this->reportFail('Fallback', "Fallback component \"{$component}\" is not in groups, so its traffic is never shown.");
            }
        }

        if ($unknownDependencies) {
            $this->reportFail('Dependencies', 'Unknown depends_on: '.implode(', ', $unknownDependencies).'.');
        }
    }

    private function probes(StatusRegistry $registry): void
    {
        $broken = [];
        $count = 0;

        foreach ($registry->components() as $component => $entry) {
            foreach ($entry['definition']['probes'] ?? [] as $definition) {
                $count++;
                try {
                    $probe = app()->make($definition['class'], $definition['with'] ?? []);
                    if (! $probe instanceof Probe) {
                        $broken[] = "{$component}: ".class_basename($definition['class']).' is not a Probe';
                    }
                } catch (Throwable $e) {
                    $broken[] = "{$component}: ".class_basename((string) ($definition['class'] ?? '?')).' ('.class_basename($e).')';
                }
            }
        }

        $broken
            ? $this->reportFail('Probes', 'Cannot be built: '.implode('; ', $broken).'.')
            : $this->reportOk('Probes', "{$count} probes can be built.");
    }

    private function labels(StatusRegistry $registry): void
    {
        $locales = array_values(array_unique(['en', 'ar', 'ku', app()->getLocale()]));
        $missing = [];

        foreach ($registry->components() as $component => $entry) {
            foreach ($locales as $locale) {
                if (! Lang::has('system-status::status.status_component_'.$component, $locale, false)) {
                    $missing[$locale][] = $component;
                }
            }
        }

        if ($missing === []) {
            $this->reportOk('Labels', 'Every component has a label in '.implode(', ', $locales).'.');

            return;
        }

        foreach ($missing as $locale => $components) {
            $this->reportWarn("Labels ({$locale})", 'Shown as the key in words: '.implode(', ', array_slice($components, 0, 8)).(count($components) > 8 ? ' …' : '').'. Add status_component_<key> in lang/vendor/system-status/'.$locale.'/status.php.');
        }
    }

    private function alerts(StatusAlerter $alerter): void
    {
        if (! config('system-status.alerts.enabled', true)) {
            $this->reportWarn('Alerts', 'Turned off (SYSTEM_STATUS_ALERTS_ENABLED=false).');

            return;
        }

        $missing = [];
        foreach ($alerter->channels() as $channel) {
            $problem = match ($channel) {
                'telegram' => blank(config('system-status.alerts.telegram_chat_id')) || blank(config('services.telegram-bot-api.token'))
                    ? 'bot token or chat id not set'
                    : (class_exists(TelegramMessage::class) ? null : 'laravel-notification-channels/telegram not installed'),
                'mail' => array_filter((array) config('system-status.alerts.mail_to', [])) ? null : 'alerts.mail_to is empty',
                'slack' => blank(config('system-status.alerts.slack_webhook')) ? 'slack_webhook not set' : null,
                'discord' => blank(config('system-status.alerts.discord_webhook')) ? 'discord_webhook not set' : null,
                default => 'unknown channel',
            };

            if ($problem) {
                $missing[] = "{$channel}: {$problem}";
            }
        }

        $missing
            ? $this->reportWarn('Alerts', implode('; ', $missing).'. Alerts are only logged. Test with status:test-alert.')
            : $this->reportOk('Alerts', 'Channels: '.implode(', ', $alerter->channels()).'. Test with status:test-alert.');
    }

    private function cachedConfig(StatusRegistry $registry): void
    {
        $closures = [];

        foreach ($registry->components() as $component => $entry) {
            foreach ($entry['definition']['probes'] ?? [] as $definition) {
                foreach ($definition['with'] ?? [] as $value) {
                    if ($value instanceof Closure) {
                        $closures[] = $component;
                    }
                }
            }
        }

        if ($closures) {
            $this->reportWarn('Config cache', 'Closures in probe config ('.implode(', ', array_unique($closures)).') break php artisan config:cache. Use an invokable class.');
        }
    }

    private function security(): void
    {
        if (! config('system-status.security.enabled', false)) {
            $this->reportWarn('Suspicious activity', 'Off. SYSTEM_STATUS_SECURITY_ENABLED=true watches attack patterns, login abuse, signal volume and risky actions.');

            return;
        }

        $store = config('system-status.security.store') ?: config('cache.default');
        $driver = config("cache.stores.{$store}.driver");

        if (in_array($driver, ['array', 'null'], true)) {
            $this->reportFail('Suspicious activity', "Cache store \"{$store}\" ({$driver}) forgets the counters after each request: nothing is ever detected. Set security.store.");
        } elseif ($driver === 'file') {
            $this->reportWarn('Suspicious activity', "On, counters in the \"{$store}\" file cache: with several servers each one counts on its own.");
        } else {
            $this->reportOk('Suspicious activity', "On, counters in the \"{$store}\" cache. Behind a proxy, check that trusted proxies are set so request IPs are real.");
        }

        $block = (array) config('system-status.security.block', []);
        if (! ($block['enabled'] ?? false)) {
            $this->reportWarn('Auto-block', 'Off: attackers are reported but not stopped. SYSTEM_STATUS_SECURITY_BLOCK=true blocks them.');
        } else {
            $this->reportOk('Auto-block', 'On for '.(int) ($block['minutes'] ?? 60).' min'.(filled($block['cloudflare']['token'] ?? null) && filled($block['cloudflare']['zone_id'] ?? null) ? ', also at Cloudflare.' : ' (local only; add a Cloudflare token to block at the edge).'));
        }
    }

    private function dangerousSettings(): void
    {
        if (config('app.debug') && app()->environment('production')) {
            $this->reportFail('APP_DEBUG', 'On in production: error pages show stack traces, queries and settings to visitors.');
        }

        if (blank(config('app.key'))) {
            $this->reportFail('APP_KEY', 'Empty: sessions and encrypted values are not safe. Run php artisan key:generate.');
        }

        $pending = SecurityConfigProbe::pendingMigrations();
        if ($pending) {
            $this->reportWarn('Migrations', "{$pending} pending migration(s). Run php artisan migrate.");
        }

        if (! $this->option('no-http') && app()->environment('production') && ($url = SecurityConfigProbe::envFileExposed())) {
            $this->reportFail('.env file', "Readable at {$url}: every secret of the application is public. Point the web server at public/.");
        }

        if (! app()->environment('production')) {
            return;
        }

        if (! str_starts_with((string) config('app.url'), 'https://')) {
            $this->reportWarn('HTTPS', 'APP_URL is not https://: passwords and session cookies can be read on the way.');
        } elseif (! $this->option('no-http')) {
            $this->hsts();
        }

        if (! config('session.secure')) {
            $this->reportWarn('Session cookie', 'SESSION_SECURE_COOKIE is off: the session cookie is also sent over plain http.');
        }

        if (config('session.http_only', true) === false) {
            $this->reportFail('Session cookie', 'session.http_only is off: any injected script can steal sessions.');
        }

        $origins = (array) config('cors.allowed_origins', []);
        if (in_array('*', $origins, true)) {
            config('cors.supports_credentials')
                ? $this->reportFail('CORS', 'allowed_origins is * with supports_credentials: any website can call the API as the signed-in user.')
                : $this->reportWarn('CORS', 'allowed_origins is *: any website can call the API from a browser.');
        }

        if (class_exists('Barryvdh\\Debugbar\\ServiceProvider') && config('debugbar.enabled')) {
            $this->reportFail('Debugbar', 'On in production: it shows queries, sessions and request data.');
        }

        if (class_exists('Laravel\\Telescope\\Telescope') && config('telescope.enabled')) {
            $this->reportWarn('Telescope', 'On in production: check that its gate (viewTelescope) only lets admins in.');
        }
    }

    private function hsts(): void
    {
        try {
            $response = Http::timeout(5)
                ->withOptions(['allow_redirects' => false, 'verify' => (bool) config('system-status.probe_verify_ssl', true)])
                ->withHeaders([HttpEndpointProbe::HEADER => '1'])
                ->get(rtrim((string) config('app.url'), '/').'/');

            if (! $response->header('Strict-Transport-Security')) {
                $this->reportWarn('HSTS', 'No Strict-Transport-Security header: browsers may still try plain http first. Add it in the web server or Cloudflare.');
            }
        } catch (Throwable) {
            // The site is not reachable from here; the HTTP probes report that.
        }
    }

    private function reportOk(string $check, string $result): void
    {
        $this->rows[] = ['ok', $check, $result];
    }

    private function reportWarn(string $check, string $result): void
    {
        $this->rows[] = ['warn', $check, $result];
    }

    private function reportFail(string $check, string $result): void
    {
        $this->rows[] = ['fail', $check, $result];
    }
}
