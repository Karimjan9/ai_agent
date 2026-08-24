<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class LabEvolutionExtinctionPlan extends Model { protected $fillable=['plan_key','ai_laboratory_id','island_key','status','retirement_fraction','trigger_metrics','preservation_contract','replacement_contract','approved_at','executed_at']; protected $casts=['retirement_fraction'=>'float','trigger_metrics'=>'array','preservation_contract'=>'array','replacement_contract'=>'array','approved_at'=>'datetime','executed_at'=>'datetime']; }
