<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShadowVetoObservation extends Model
{
    protected $fillable = [
        'lab_agent_id', 'stage', 'veto_reason', 'market_regime', 'volatility_regime', 'spread_context', 'direction',
        'signal_time', 'entry_time', 'exit_time', 'shadow_profit', 'shadow_loss',
        'shadow_profit_percent', 'p_allow', 'p_veto', 'exploration_assigned', 'outcome', 'metadata',
    ];

    protected $casts = [
        'signal_time' => 'datetime', 'entry_time' => 'datetime', 'exit_time' => 'datetime', 'metadata' => 'array', 'exploration_assigned' => 'boolean',
    ];

    public function labAgent(): BelongsTo { return $this->belongsTo(LabAgent::class); }
}
