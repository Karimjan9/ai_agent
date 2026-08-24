<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class LabEvolutionDirector extends Model { protected $fillable=['director_key','symbol','timeframe','director_type','policy','reward_score','budget_share','status','evidence']; protected $casts=['policy'=>'array','reward_score'=>'float','budget_share'=>'float','evidence'=>'array']; }
