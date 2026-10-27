<?php

namespace Technobase\SystemStatus\Probes;

use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Known security advisories for the installed Composer packages (`composer audit --locked`):
 * a high or critical one is a major outage, any other degraded. Run it rarely ('every' => 720);
 * skipped when composer or composer.lock is missing.
 */
class ComposerAuditProbe implements Probe
{
    public function __construct(
        private readonly string $binary = 'composer',
        private readonly int $timeoutSeconds = 60,
    ) {}

    public function name(): string
    {
        return 'Composer security advisories';
    }

    public function run(): ProbeResult
    {
        if (! is_file(base_path('composer.lock'))) {
            return ProbeResult::skipped('composer.lock not found');
        }

        try {
            $result = Process::path(base_path())
                ->timeout($this->timeoutSeconds)
                ->env(['COMPOSER_NO_INTERACTION' => '1'])
                ->run([$this->binary, 'audit', '--locked', '--format=json', '--no-interaction', '--abandoned=ignore']);
        } catch (Throwable $e) {
            return ProbeResult::skipped('composer audit could not run ('.class_basename($e).')');
        }

        $json = json_decode($this->json($result->output()), true);

        if (! is_array($json) || ! array_key_exists('advisories', $json)) {
            return ProbeResult::skipped('composer audit gave no result (exit code '.$result->exitCode().')');
        }

        $advisories = [];
        foreach ((array) $json['advisories'] as $package => $items) {
            foreach ((array) $items as $item) {
                $advisories[] = [
                    'package' => (string) ($item['packageName'] ?? $package),
                    'severity' => strtolower((string) ($item['severity'] ?? '')),
                    'cve' => (string) ($item['cve'] ?? ''),
                ];
            }
        }

        if ($advisories === []) {
            return ProbeResult::ok('No known advisories');
        }

        $serious = array_filter($advisories, fn ($a) => in_array($a['severity'], ['high', 'critical'], true));
        $packages = array_values(array_unique(array_column($advisories, 'package')));
        $message = count($advisories).' advisories in '.implode(', ', array_slice($packages, 0, 5))
            .(count($packages) > 5 ? ' (+'.(count($packages) - 5).')' : '')
            .($serious ? ' ('.count($serious).' high or critical)' : '').'. Run composer audit.';

        return $serious ? ProbeResult::outage($message) : ProbeResult::degraded($message);
    }

    /**
     * The JSON part of the output (composer may print warnings before it).
     */
    private function json(string $output): string
    {
        $start = strpos($output, '{');

        return $start === false ? '' : substr($output, $start);
    }
}
