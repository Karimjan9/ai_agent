<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class FoundryEmitterReward extends Model { protected $fillable=['reward_key','symbol','timeframe','emitter','trial_family_id','reward','penalty','status','evidence','settled_at']; protected $casts=['evidence'=>'array','reward'=>'float','penalty'=>'float','settled_at'=>'datetime']; }
