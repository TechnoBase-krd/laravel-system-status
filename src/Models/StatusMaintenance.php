<?php

namespace Technobase\SystemStatus\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A maintenance window: the listed components show "Maintenance" between starts_at and ends_at.
 *
 * @property array<int, string> $components
 */
class StatusMaintenance extends Model
{
    protected $fillable = ['title', 'message', 'components', 'starts_at', 'ends_at', 'announced_at', 'ended_at', 'created_by'];

    protected $casts = [
        'components' => 'array',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'announced_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function scopeActive(Builder $query, ?CarbonInterface $at = null): Builder
    {
        $at ??= now();

        return $query->where('starts_at', '<=', $at)->where('ends_at', '>', $at);
    }

    public function scopeUpcoming(Builder $query, ?CarbonInterface $at = null): Builder
    {
        return $query->where('starts_at', '>', $at ?? now());
    }

    public function covers(string $component): bool
    {
        return in_array($component, (array) $this->components, true);
    }

    public function isActive(?CarbonInterface $at = null): bool
    {
        $at ??= now();

        return $this->starts_at->lte($at) && $this->ends_at->gt($at);
    }

    /**
     * Active windows keyed by component (the first one that covers it).
     *
     * @return array<string, self>
     */
    public static function activeByComponent(?CarbonInterface $at = null): array
    {
        $byComponent = [];

        foreach (self::query()->active($at)->orderBy('starts_at')->get() as $maintenance) {
            foreach ((array) $maintenance->components as $component) {
                $byComponent[$component] ??= $maintenance;
            }
        }

        return $byComponent;
    }
}
