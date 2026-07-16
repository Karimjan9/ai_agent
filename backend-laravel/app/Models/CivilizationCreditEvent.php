<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CivilizationCreditEvent extends Model
{
    protected $fillable = [
        'civilization_agent_id',
        'event_type',
        'amount',
        'reason',
        'source_type',
        'source_id',
        'evidence',
    ];

    protected $casts = [
        'evidence' => 'array',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(CivilizationAgent::class, 'civilization_agent_id');
    }
}
