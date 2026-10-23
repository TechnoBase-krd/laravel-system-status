<?php

namespace Technobase\SystemStatus\Probes;

/**
 * Free space on the disk that holds `path`: degraded below `degradedBelowPercent`, major
 * outage below `outageBelowPercent`.
 */
class DiskSpaceProbe implements Probe
{
    public function __construct(
        private readonly ?string $path = null,
        private readonly float $degradedBelowPercent = 15,
        private readonly float $outageBelowPercent = 5,
    ) {}

    public function name(): string
    {
        return 'Disk space '.($this->path ?? 'storage');
    }

    public function run(): ProbeResult
    {
        $path = $this->path ?? storage_path();
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);

        if ($free === false || ! $total) {
            return ProbeResult::skipped('Disk space of '.$path.' cannot be read');
        }

        $percent = round($free / $total * 100, 1);
        $message = "{$percent}% free (".$this->size($free).' of '.$this->size($total).')';

        return match (true) {
            $percent < $this->outageBelowPercent => ProbeResult::outage('Disk almost full: '.$message),
            $percent < $this->degradedBelowPercent => ProbeResult::degraded('Disk space low: '.$message),
            default => ProbeResult::ok($message),
        };
    }

    private function size(float $bytes): string
    {
        return $bytes >= 1073741824 ? round($bytes / 1073741824, 1).' GB' : round($bytes / 1048576).' MB';
    }
}
