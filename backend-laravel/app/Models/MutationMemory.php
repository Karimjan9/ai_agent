<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class MutationMemory extends Model
{
    protected $fillable = ['lab_agent_id', 'symbol', 'timeframe', 'strategy_family', 'parameter_key', 'old_value', 'new_value', 'forward_delta', 'market_regime', 'outcome', 'confidence', 'decision'];
    protected $casts = ['old_value' => 'array', 'new_value' => 'array'];
    public function labAgent(): BelongsTo { return $this->belongsTo(LabAgent::class); }
}
