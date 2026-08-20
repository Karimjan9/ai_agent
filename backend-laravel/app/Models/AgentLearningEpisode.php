<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AgentLearningEpisode extends Model
{
    protected $fillable = ['episode_id', 'decision_key', 'lab_agent_id', 'model_version_id', 'symbol', 'timeframe', 'strategy_family', 'stage', 'status', 'decision', 'confidence', 'risk_veto', 'context_hash', 'data_hash', 'code_hash', 'parameter_hash', 'execution_hash', 'decision_context', 'observations', 'opened_at', 'settled_at'];
    protected $casts = ['decision_context' => 'array', 'observations' => 'array', 'confidence' => 'float', 'opened_at' => 'datetime', 'settled_at' => 'datetime'];
    public function labAgent(): BelongsTo { return $this->belongsTo(LabAgent::class); }
    public function modelVersion(): BelongsTo { return $this->belongsTo(ModelVersion::class); }
    public function settlement(): HasOne { return $this->hasOne(AgentLearningSettlement::class, 'episode_id'); }
}
