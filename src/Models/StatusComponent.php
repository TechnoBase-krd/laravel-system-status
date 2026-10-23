<?php

namespace Technobase\SystemStatus\Models;

use Illuminate\Database\Eloquent\Model;
use Technobase\SystemStatus\Enums\ComponentStatus;

class StatusComponent extends Model
{
    protected $fillable = [
        'component', 'status', 'candidate_status', 'candidate_runs', 'message', 'configured', 'changed_at', 'checked_at', 'affected_by',
    ];

    protected $casts = [
        'status' => ComponentStatus::class,
        'candidate_status' => ComponentStatus::class,
        'configured' => 'boolean',
        'changed_at' => 'datetime',
        'checked_at' => 'datetime',
    ];
}
