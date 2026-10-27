<?php

namespace Technobase\SystemStatus\Probes;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Technobase\SystemStatus\Support\ProbeSignature;
use Throwable;

/**
 * Secret files anyone can download: .env and its backups, the .git folder, the Laravel log,
 * database dumps, phpinfo and Adminer. The web server serves real files in public/ without
 * running Laravel, so the suspicious activity monitor never sees those requests; this probe asks
 * for them itself. A path counts only when a 200 response looks like that kind of file, so a
 * catch-all route that answers every path with a page is not a finding. Never prints what it gets
 * back. Its requests are signed, so the monitor does not report the server as a scanner.
 */
class ExposedFilesProbe implements Probe
{
    private const ENV = '/^\s*[A-Z][A-Z0-9_]*\s*=/m';

    private const SQL = '/\b(CREATE TABLE|INSERT INTO|DROP TABLE)\b/i';

    /**
     * Path => pattern its content matches when it is the real file.
     */
    public const PATHS = [
        '.env' => self::ENV,
        '.env.backup' => self::ENV,
        '.env.bak' => self::ENV,
        '.env.old' => self::ENV,
        '.env.save' => self::ENV,
        '.env.production' => self::ENV,
        '.git/config' => '/^\s*\[core\]/m',
        '.git/HEAD' => '/^ref: refs\//',
        'storage/logs/laravel.log' => '/^\[\d{4}-\d\d-\d\d[ T][\d:.]+[^\]]*\] \w+\.[A-Z]+:/m',
        'laravel.log' => '/^\[\d{4}-\d\d-\d\d[ T][\d:.]+[^\]]*\] \w+\.[A-Z]+:/m',
        'backup.sql' => self::SQL,
        'database.sql' => self::SQL,
        'dump.sql' => self::SQL,
        'db.sql' => self::SQL,
        'phpinfo.php' => '/PHP Version|phpinfo\(\)/',
        'info.php' => '/PHP Version|phpinfo\(\)/',
        'adminer.php' => '/Adminer/',
        'auth.json' => '/"(github-oauth|http-basic|bearer|gitlab-token)"/',
    ];

    /**
     * @param  array<string, string>  $paths  extra paths => regex the real file's content matches
     * @param  array<int, string>  $skip  built-in paths not to check
     */
    public function __construct(
        private readonly array $paths = [],
        private readonly array $skip = [],
        private readonly ?string $baseUrl = null,
        private readonly int $timeoutSeconds = 5,
    ) {}

    public function name(): string
    {
        return 'Exposed files';
    }

    public function run(): ProbeResult
    {
        $base = rtrim((string) ($this->baseUrl ?: config('system-status.probe_base_url') ?: config('app.url')), '/');

        if ($base === '') {
            return ProbeResult::skipped('No base URL (APP_URL) to check');
        }

        $paths = array_diff_key([...self::PATHS, ...$this->paths], array_flip($this->skip));
        $urls = [];
        foreach (array_keys($paths) as $path) {
            $urls[$path] = $base.'/'.ltrim($path, '/');
        }

        try {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string $path) => $pool->as($path)
                    ->timeout(max(1, $this->timeoutSeconds))
                    ->withOptions(['verify' => (bool) config('system-status.probe_verify_ssl', true), 'allow_redirects' => false])
                    ->withHeaders([
                        'User-Agent' => (string) config('system-status.probe_user_agent', 'SystemStatusProbe/1.0'),
                        HttpEndpointProbe::HEADER => '1',
                        ...ProbeSignature::headers($urls[$path]),
                    ])
                    ->get($urls[$path]),
                array_keys($urls),
            ));
        } catch (Throwable $e) {
            return ProbeResult::degraded('Could not check: '.class_basename($e));
        }

        $exposed = [];
        $failed = 0;

        foreach ($paths as $path => $pattern) {
            $response = $responses[$path] ?? null;

            if (! $response instanceof Response) {
                $failed++;

                continue;
            }

            if ($response->successful() && @preg_match($pattern, mb_substr($response->body(), 0, 8192)) === 1) {
                $exposed[] = '/'.ltrim($path, '/');
            }
        }

        if ($exposed !== []) {
            return ProbeResult::outage('Anyone can download '.implode(', ', $exposed));
        }

        if ($failed === count($paths)) {
            return ProbeResult::degraded('Could not reach '.$base);
        }

        return ProbeResult::ok(count($paths) - $failed.' secret file paths checked, none exposed');
    }
}
