<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class LabSkillZooEntry extends Model { protected $fillable=['skill_key','symbol','timeframe','strategy_family','module_key','niche_key','gene_key','lab_agent_id','model_version_id','lab_mutation_response_map_id','quality_score','confidence','status','evidence']; protected $casts=['quality_score'=>'float','confidence'=>'float','evidence'=>'array']; }
