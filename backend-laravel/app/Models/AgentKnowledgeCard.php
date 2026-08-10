<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentKnowledgeCard extends Model
{
    protected $fillable = [
        'lab_agent_id', 'model_version_id', 'symbol', 'timeframe', 'strategy_family',
        'skill_stage', 'skill_score', 'strong_regimes', 'strong_state_clusters',
        'failure_profiles', 'tested_mutations', 'blocked_mutations',
        'independent_window_count', 'confirmed_lesson_count', 'retention_status',
        'retention_score', 'abstention_status', 'abstention_precision',
        'unknown_state_action', 'drift_status', 'drift_recheck_at',
        'capability_vector', 'skill_contract', 'provenance', 'last_evidence_run_id',
        'last_observed_at',
    ];

    protected $casts = [
        'strong_regimes' => 'array', 'strong_state_clusters' => 'array',
        'failure_profiles' => 'array', 'tested_mutations' => 'array',
        'blocked_mutations' => 'array', 'retention_score' => 'float',
        'abstention_precision' => 'float', 'capability_vector' => 'array',
        'skill_contract' => 'array', 'provenance' => 'array',
        'drift_recheck_at' => 'datetime', 'last_observed_at' => 'datetime',
    ];

    public function labAgent(): BelongsTo
    {
        return $this->belongsTo(LabAgent::class);
    }

    public function modelVersion(): BelongsTo
    {
        return $this->belongsTo(ModelVersion::class);
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(AgentLearningLesson::class, 'lab_agent_id', 'lab_agent_id');
    }

    public function professionalExams(): HasMany
    {
        return $this->hasMany(AgentProfessionalExam::class, 'lab_agent_id', 'lab_agent_id');
    }
}
