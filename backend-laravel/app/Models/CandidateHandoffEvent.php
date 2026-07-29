<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class CandidateHandoffEvent extends Model { protected $fillable=['lab_generation_id','lab_agent_id','stage','status','terminal_reason','payload','recorded_at']; protected $casts=['payload'=>'array','recorded_at'=>'datetime']; public function generation(): BelongsTo { return $this->belongsTo(LabGeneration::class, 'lab_generation_id'); } public function agent(): BelongsTo { return $this->belongsTo(LabAgent::class, 'lab_agent_id'); } }
