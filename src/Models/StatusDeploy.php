<?php

namespace Technobase\SystemStatus\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * A deploy of the application, shown as a marker on the status charts.
 *
 * @property string $version
 * @property ?string $commit
 * @property ?string $note
 * @property ?string $deployed_by
 * @property CarbonInterface $deployed_at
 * @property ?CarbonInterface $compared_at
 * @property ?array<int, array<string, mixed>> $comparison components that got worse
 */
class StatusDeploy extends Model
{
    protected $fillable = ['version', 'commit', 'note', 'deployed_by', 'deployed_at', 'compared_at', 'comparison'];

    protected $casts = [
        'deployed_at' => 'datetime',
        'compared_at' => 'datetime',
        'comparison' => 'array',
    ];

    /**
     * "1.8.2 (a1b2c3d)", or just the version when the commit is the version.
     */
    public function label(): string
    {
        $commit = $this->commit ? substr($this->commit, 0, 7) : null;

        return $commit && ! str_contains($this->version, $commit)
            ? "{$this->version} ({$commit})"
            : $this->version;
    }

    /**
     * The last deploy within $minutes before $at, if any.
     */
    public static function justBefore(CarbonInterface $at, ?int $minutes = null): ?self
    {
        $minutes ??= (int) config('system-status.deploys.incident_window_minutes', 30);

        if ($minutes <= 0) {
            return null;
        }

        return static::query()
            ->where('deployed_at', '<=', $at)
            ->where('deployed_at', '>=', $at->copy()->subMinutes($minutes))
            ->latest('deployed_at')
            ->latest('id')
            ->first();
    }
}
