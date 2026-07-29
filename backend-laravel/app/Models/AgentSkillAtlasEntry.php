<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AgentSkillAtlasEntry extends Model { protected $fillable=['model_market_performance_id','model_version_id','symbol','timeframe','niche_key','regime','volatility','direction','role','quality_score','capabilities','evidence','validated_at']; protected $casts=['capabilities'=>'array','evidence'=>'array','validated_at'=>'datetime']; }
