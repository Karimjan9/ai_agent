<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentLearningSettlement extends Model
{
    protected $fillable = ['settlement_id', 'episode_id', 'source_key', 'source_type', 'source_id', 'outcome_status', 'failure_class', 'evidence_state', 'selection_reward', 'hard_failure', 'outcome', 'reward_components', 'reflection', 'settled_at'];
    protected $casts = ['outcome' => 'array', 'reward_components' => 'array', 'reflection' => 'array', 'selection_reward' => 'float', 'hard_failure' => 'boolean', 'settled_at' => 'datetime'];
    public function episode(): BelongsTo { return $this->belongsTo(AgentLearningEpisode::class, 'episode_id'); }
}
