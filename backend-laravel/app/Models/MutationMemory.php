<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class MutationMemory extends Model
{
    protected $fillable = ['lab_agent_id', 'symbol', 'timeframe', 'strategy_family', 'architecture', 'parameter_key', 'old_value', 'new_value', 'forward_delta', 'market_regime', 'direction', 'volatility_regime', 'execution_contract_hash', 'independent_confirmation_count', 'non_target_regression_status', 'evidence_scope_status', 'outcome', 'confidence', 'decision', 'gate_transition', 'behavioral_effect'];
    protected $casts = ['old_value' => 'array', 'new_value' => 'array', 'gate_transition' => 'array', 'behavioral_effect' => 'array'];
    public function labAgent(): BelongsTo { return $this->belongsTo(LabAgent::class); }
}
