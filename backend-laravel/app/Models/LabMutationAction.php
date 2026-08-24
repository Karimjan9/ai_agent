<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class LabMutationAction extends Model { protected $fillable=['action_key','lab_agent_id','model_version_id','symbol','timeframe','strategy_family','gene_key','direction','magnitude','context','parent_state','reward','status','evidence_run_id']; protected $casts=['magnitude'=>'float','context'=>'array','parent_state'=>'array','reward'=>'array']; }
