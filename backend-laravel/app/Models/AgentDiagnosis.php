<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class AgentDiagnosis extends Model
{
    protected $fillable=['lab_agent_id','model_market_performance_id','primary_failure','evidence','recommended_mutations','blocked_mutations','explanation','confidence'];
    protected $casts=['evidence'=>'array','recommended_mutations'=>'array','blocked_mutations'=>'array'];
    public function modelMarketPerformance(): BelongsTo { return $this->belongsTo(ModelMarketPerformance::class); }
}
