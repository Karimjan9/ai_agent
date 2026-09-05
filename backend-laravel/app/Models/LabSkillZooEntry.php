<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class LabSkillZooEntry extends Model { protected $fillable=['skill_key','cartridge_key','revision','symbol','timeframe','strategy_family','module_key','niche_key','gene_key','lab_agent_id','model_version_id','lab_mutation_response_map_id','causal_baseline_agent_id','genetic_parent_model_version_id','quality_score','confidence','status','component_status','organism_viability','evidence']; protected $casts=['quality_score'=>'float','confidence'=>'float','revision'=>'integer','evidence'=>'array']; }
