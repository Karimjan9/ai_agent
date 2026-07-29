<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class DecisionCounterfactualEdge extends Model { protected $fillable=['model_market_performance_id','lab_agent_id','edge_key','source_node','target_node','regime','cost_scenario','baseline_value','intervention_value','delta_value','lower_confidence_bound','upper_confidence_bound','sample_count','evidence_status','metadata']; protected $casts=['metadata'=>'array']; }
