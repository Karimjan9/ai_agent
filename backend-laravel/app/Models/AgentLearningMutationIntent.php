<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentLearningMutationIntent extends Model
{
    protected $fillable = [
        'intent_id', 'intent_key', 'packet_id', 'lab_generation_id', 'model_version_id',
        'lab_agent_id', 'symbol', 'timeframe', 'strategy_family', 'target', 'selected_gene',
        'influence_type', 'status', 'retrieved_lesson_ids', 'selected_lesson_ids',
        'causally_applied_lesson_ids', 'rejected_lesson_ids', 'causally_applied_retrieval_ids',
        'old_value', 'new_value', 'baseline_hash', 'parameter_hash', 'mutation_hash',
        'retrieved_at', 'sealed_at', 'bound_at', 'invalidated_at', 'invalid_reason', 'metadata',
    ];

    protected $casts = [
        'retrieved_lesson_ids' => 'array', 'selected_lesson_ids' => 'array',
        'causally_applied_lesson_ids' => 'array', 'rejected_lesson_ids' => 'array',
        'causally_applied_retrieval_ids' => 'array', 'old_value' => 'array', 'new_value' => 'array',
        'retrieved_at' => 'datetime', 'sealed_at' => 'datetime', 'bound_at' => 'datetime',
        'invalidated_at' => 'datetime', 'metadata' => 'array',
    ];
}
