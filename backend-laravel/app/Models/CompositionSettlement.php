<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An idempotent record of a terminal composition-learning settlement. */
class CompositionSettlement extends Model
{
    protected $fillable = [
        'settlement_key', 'lab_agent_id', 'model_version_id', 'symbol', 'timeframe',
        'composition_id', 'status', 'components', 'evidence', 'settled_at',
    ];

    protected $casts = [
        'components' => 'array', 'evidence' => 'array', 'settled_at' => 'datetime',
    ];
}
