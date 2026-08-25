<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CompositionComponentPosterior extends Model { protected $fillable=['posterior_key','symbol','timeframe','state_key','component_type','component_id','observations','after_cost_value','uncertainty','evidence','last_settled_at']; protected $casts=['evidence'=>'array','after_cost_value'=>'float','uncertainty'=>'float','last_settled_at'=>'datetime']; }
