<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class RegimeReservoirEntry extends Model { protected $fillable=['symbol','timeframe','regime','volatility_regime','state_signature','adapter_model_version_id','performance_posterior','known_failures','recovery_quality','last_seen_at']; protected $casts=['performance_posterior'=>'array','known_failures'=>'array','last_seen_at'=>'datetime']; }
