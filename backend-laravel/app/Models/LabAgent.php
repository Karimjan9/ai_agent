<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class LabAgent extends Model
{
    protected $fillable = ['lab_generation_id', 'model_version_id', 'parent_a_model_version_id', 'parent_b_model_version_id', 'symbol', 'timeframe', 'strategy_family', 'origin', 'lifecycle_status', 'parameter_diff', 'train_score', 'validation_score', 'forward_score', 'champion_improvement', 'rolling_wins', 'sample_count', 'profit_factor', 'max_drawdown', 'risk_of_ruin', 'decision_reason'];
    protected $casts = ['parameter_diff' => 'array'];
    public function generation(): BelongsTo { return $this->belongsTo(LabGeneration::class, 'lab_generation_id'); }
    public function modelVersion(): BelongsTo { return $this->belongsTo(ModelVersion::class); }
    public function parentA(): BelongsTo { return $this->belongsTo(ModelVersion::class, 'parent_a_model_version_id'); }
    public function parentB(): BelongsTo { return $this->belongsTo(ModelVersion::class, 'parent_b_model_version_id'); }
    public function mutationMemories(): HasMany { return $this->hasMany(MutationMemory::class); }
}
