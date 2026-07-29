<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class VetoPolicyEvaluation extends Model { protected $fillable=['lab_agent_id','veto_reason','context_key','sample_count','calendar_windows','doubly_robust_value','lower_confidence_bound','status','recommended_action','evidence']; protected $casts=['evidence'=>'array']; public function labAgent(): BelongsTo { return $this->belongsTo(LabAgent::class); } }
