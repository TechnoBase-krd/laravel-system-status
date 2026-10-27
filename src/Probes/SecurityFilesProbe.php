<?php

namespace Technobase\SystemStatus\Probes;

use Technobase\SystemStatus\Support\FileManifest;

/**
 * Code files added or changed since the last deploy (security.files): major outage, because a
 * PHP file that appears outside a deploy is usually a web shell or a tampered file. Removed files
 * are degraded. Skipped until a baseline exists (status:deploy or status:security --baseline).
 */
class SecurityFilesProbe implements Probe
{
    public function name(): string
    {
        return 'Code files since the last deploy';
    }

    public function run(): ProbeResult
    {
        $diff = FileManifest::compare();

        if ($diff === null) {
            return ProbeResult::skipped('No baseline yet: run php artisan status:deploy or status:security --baseline');
        }

        $list = fn (array $files) => implode(', ', array_slice($files, 0, 5)).(count($files) > 5 ? ' (+'.(count($files) - 5).')' : '');

        if ($diff['added'] || $diff['changed']) {
            return ProbeResult::outage(trim(
                ($diff['added'] ? count($diff['added']).' new: '.$list($diff['added']).'. ' : '')
                .($diff['changed'] ? count($diff['changed']).' changed: '.$list($diff['changed']).'.' : '')
            ));
        }

        if ($diff['removed']) {
            return ProbeResult::degraded(count($diff['removed']).' removed: '.$list($diff['removed']));
        }

        return ProbeResult::ok('No code file changed since the last deploy');
    }
}
