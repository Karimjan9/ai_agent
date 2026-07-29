<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class FailureCaseRun extends Model { protected $fillable=['agent_failure_case_id','model_market_performance_id','status','score_penalty','evidence','evaluated_at']; protected $casts=['evidence'=>'array','evaluated_at'=>'datetime']; }
