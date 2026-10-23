<?php

namespace Technobase\SystemStatus\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Technobase\SystemStatus\Enums\ComponentStatus;
use Technobase\SystemStatus\Enums\IncidentState;

class StatusIncident extends Model
{
    public const SOURCE_AUTO = 'auto';

    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'component', 'severity', 'message', 'started_at', 'resolved_at',
        'title', 'source', 'state', 'postmortem', 'created_by', 'reminded_at', 'affected_by',
    ];

    protected $attributes = [
        'source' => self::SOURCE_AUTO,
    ];

    protected $casts = [
        'severity' => ComponentStatus::class,
        'state' => IncidentState::class,
        'started_at' => 'datetime',
        'resolved_at' => 'datetime',
        'reminded_at' => 'datetime',
    ];

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    public function scopeManual(Builder $query): Builder
    {
        return $query->where('source', self::SOURCE_MANUAL);
    }

    public function scopeAutomatic(Builder $query): Builder
    {
        return $query->where('source', self::SOURCE_AUTO);
    }

    public function updates(): HasMany
    {
        return $this->hasMany(StatusIncidentUpdate::class, 'incident_id')->orderByDesc('created_at')->orderByDesc('id');
    }

    public function isManual(): bool
    {
        return $this->source === self::SOURCE_MANUAL;
    }
}
