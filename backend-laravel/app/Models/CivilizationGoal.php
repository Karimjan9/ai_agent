<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CivilizationGoal extends Model
{
    protected $fillable = [
        'goal_key',
        'owner_agent_id',
        'title',
        'description',
        'priority_score',
        'progress_score',
        'status',
        'metrics',
        'metadata',
    ];

    protected $casts = [
        'metrics' => 'array',
        'metadata' => 'array',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(CivilizationAgent::class, 'owner_agent_id');
    }
}
