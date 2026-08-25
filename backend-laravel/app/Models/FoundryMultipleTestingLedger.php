<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class FoundryMultipleTestingLedger extends Model { protected $fillable=['ledger_key','symbol','timeframe','trial_family_id','experiment_id','status','deflated_sharpe_probability','pbo_probability','evidence','recorded_at']; protected $casts=['evidence'=>'array','deflated_sharpe_probability'=>'float','pbo_probability'=>'float','recorded_at'=>'datetime']; }
