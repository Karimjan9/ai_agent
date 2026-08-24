<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentLearningCausalExperiment extends Model
{
    protected $fillable = [
        'experiment_key', 'lab_generation_id', 'symbol', 'timeframe', 'strategy_family',
        'target', 'gene_key', 'source_lesson_id', 'guided_agent_id', 'blinded_agent_id',
        'control_agent_id', 'status', 'independent_window_count', 'guided_beats_blinded',
        'guided_beats_control', 'evidence', 'confirmed_at',
    ];

    protected $casts = [
        'guided_beats_blinded' => 'boolean', 'guided_beats_control' => 'boolean',
        'evidence' => 'array', 'confirmed_at' => 'datetime',
    ];
}
