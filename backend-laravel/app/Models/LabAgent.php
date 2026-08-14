<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
class LabAgent extends Model
{
    protected $fillable = ['lab_generation_id', 'model_version_id', 'parent_a_model_version_id', 'parent_b_model_version_id', 'symbol', 'timeframe', 'strategy_family', 'origin', 'lifecycle_status', 'parameter_diff', 'train_score', 'validation_score', 'forward_score', 'champion_improvement', 'rolling_wins', 'sample_count', 'profit_factor', 'max_drawdown', 'risk_of_ruin', 'decision_reason'];
    protected $casts = ['parameter_diff' => 'array'];
    public function generation(): BelongsTo { return $this->belongsTo(LabGeneration::class, 'lab_generation_id'); }
    public function modelVersion(): BelongsTo { return $this->belongsTo(ModelVersion::class); }
    public function parentA(): BelongsTo { return $this->belongsTo(ModelVersion::class, 'parent_a_model_version_id'); }
    public function parentB(): BelongsTo { return $this->belongsTo(ModelVersion::class, 'parent_b_model_version_id'); }
    public function parentLinks(): HasMany { return $this->hasMany(LabAgentParentLink::class, 'lab_agent_id'); }
    public function inheritanceAudits(): HasMany { return $this->hasMany(LabAgentInheritanceAudit::class, 'lab_agent_id'); }
    public function parentSelectionDecisions(): HasMany { return $this->hasMany(LabParentSelectionDecision::class, 'lab_agent_id'); }
    public function parentCounterfactuals(): HasMany { return $this->hasMany(LabParentCounterfactual::class, 'candidate_agent_id'); }
    public function evolutionCreditEvents(): HasMany { return $this->hasMany(LabEvolutionCreditEvent::class, 'lab_agent_id'); }
    public function evolutionArchiveEntries(): HasMany { return $this->hasMany(LabEvolutionArchiveEntry::class, 'lab_agent_id'); }
    public function mutationMemories(): HasMany { return $this->hasMany(MutationMemory::class); }
    public function knowledgeCard(): HasOne { return $this->hasOne(AgentKnowledgeCard::class); }
    public function progressCard(): HasOne { return $this->hasOne(AgentProgressCard::class); }
    public function learningLessons(): HasMany { return $this->hasMany(AgentLearningLesson::class); }
    public function professionalExams(): HasMany { return $this->hasMany(AgentProfessionalExam::class); }
}
