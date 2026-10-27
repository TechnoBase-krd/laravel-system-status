<?php

namespace Technobase\SystemStatus\Probes;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Dangerous configuration: APP_DEBUG on in production (major outage: stack traces and settings
 * are shown to visitors), an empty APP_KEY (major outage), the .env file readable over HTTP
 * (major outage, when `checkEnvFile` is on) and pending migrations (degraded). Read-only: the
 * .env check sends one GET and never prints what it gets back.
 */
class SecurityConfigProbe implements Probe
{
    public function __construct(
        private readonly bool $checkDebug = true,
        private readonly bool $checkMigrations = true,
        private readonly bool $checkEnvFile = false,
        private readonly ?string $baseUrl = null,
    ) {}

    public function name(): string
    {
        return 'Security configuration';
    }

    public function run(): ProbeResult
    {
        $outage = [];
        $degraded = [];

        if ($this->checkDebug && config('app.debug') && app()->environment('production')) {
            $outage[] = 'APP_DEBUG is on in production';
        }

        if (blank(config('app.key'))) {
            $outage[] = 'APP_KEY is empty';
        }

        if ($this->checkEnvFile && ($exposed = self::envFileExposed($this->baseUrl))) {
            $outage[] = '.env is readable at '.$exposed;
        }

        if ($this->checkMigrations && ($pending = self::pendingMigrations()) > 0) {
            $degraded[] = $pending.' pending migration(s)';
        }

        return match (true) {
            $outage !== [] => ProbeResult::outage(implode('; ', [...$outage, ...$degraded])),
            $degraded !== [] => ProbeResult::degraded(implode('; ', $degraded)),
            default => ProbeResult::ok('No dangerous settings found'),
        };
    }

    /**
     * Migrations on disk that have not run, or null when that cannot be read.
     */
    public static function pendingMigrations(): ?int
    {
        try {
            /** @var Migrator $migrator */
            $migrator = app('migrator');

            if (! $migrator->repositoryExists()) {
                return null;
            }

            $files = $migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths()));
            $ran = $migrator->getRepository()->getRan();

            return count(array_diff(array_keys($files), $ran));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The URL that served the .env file, or null. A 200 response that looks like KEY=value lines
     * counts; an HTML page (a catch-all route) does not.
     */
    public static function envFileExposed(?string $baseUrl = null): ?string
    {
        $base = rtrim((string) ($baseUrl ?: config('system-status.probe_base_url') ?: config('app.url')), '/');

        if ($base === '') {
            return null;
        }

        $url = $base.'/.env';

        try {
            $response = Http::timeout(5)
                ->withOptions(['verify' => (bool) config('system-status.probe_verify_ssl', true), 'allow_redirects' => false])
                ->withHeaders(['User-Agent' => (string) config('system-status.probe_user_agent', 'SystemStatusProbe/1.0'), HttpEndpointProbe::HEADER => '1'])
                ->get($url);

            return $response->successful() && preg_match('/^\s*[A-Z][A-Z0-9_]*\s*=/m', mb_substr($response->body(), 0, 4096))
                ? $url
                : null;
        } catch (Throwable) {
            return null;
        }
    }
}
