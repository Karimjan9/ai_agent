<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class LabAdversarialScenario extends Model { protected $fillable=['scenario_key','symbol','timeframe','strategy_family','lab_agent_id','scenario_type','historical_bounds','novelty_score','realism_score','failure_discovery_score','status','result']; protected $casts=['historical_bounds'=>'array','novelty_score'=>'float','realism_score'=>'float','failure_discovery_score'=>'float','result'=>'array']; }
