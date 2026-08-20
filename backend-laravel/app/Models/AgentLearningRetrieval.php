<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentLearningRetrieval extends Model
{
    protected $fillable = ['retrieval_id', 'packet_id', 'episode_id', 'agent_learning_lesson_id', 'lab_agent_id', 'symbol', 'timeframe', 'strategy_family', 'retrieval_state', 'match_level', 'rank_score', 'reason_code', 'context', 'metadata', 'consumed_at', 'outcome_linked_at'];
    protected $casts = ['context' => 'array', 'metadata' => 'array', 'rank_score' => 'float', 'consumed_at' => 'datetime', 'outcome_linked_at' => 'datetime'];
}
