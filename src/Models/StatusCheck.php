<?php

namespace Technobase\SystemStatus\Models;

use Illuminate\Database\Eloquent\Model;
use Technobase\SystemStatus\Enums\ComponentStatus;

class StatusCheck extends Model
{
    public $timestamps = false;

    protected $fillable = ['component', 'probe', 'status', 'response_ms', 'message', 'checked_at'];

    protected $casts = [
        'status' => ComponentStatus::class,
        'checked_at' => 'datetime',
    ];
}
