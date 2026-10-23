<?php

namespace Technobase\SystemStatus\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Technobase\SystemStatus\Enums\IncidentState;

class StatusIncidentUpdate extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['incident_id', 'state', 'message', 'author', 'created_at'];

    protected $casts = [
        'state' => IncidentState::class,
        'created_at' => 'datetime',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(StatusIncident::class, 'incident_id');
    }
}
