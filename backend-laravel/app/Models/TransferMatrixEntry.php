<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class TransferMatrixEntry extends Model { protected $fillable=['model_version_id','train_markets','test_market','test_scope','from_scratch_score','transferred_score','transfer_gain','adaptation_steps','source_regression','status','evidence']; protected $casts=['evidence'=>'array']; }
