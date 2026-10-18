<?php

namespace Technobase\SystemStatus\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Technobase\SystemStatus\Enums\ComponentStatus;

class StatusIncident extends Model
{
    protected $fillable = ['component', 'severity', 'message', 'started_at', 'resolved_at'];

    protected $casts = [
        'severity' => ComponentStatus::class,
        'started_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }
}
