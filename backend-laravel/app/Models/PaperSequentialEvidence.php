<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PaperSequentialEvidence extends Model { protected $table='paper_sequential_evidences'; protected $fillable=['model_market_performance_id','sample_count','e_value','likelihood_ratio','confidence_sequence','status','metrics']; protected $casts=['metrics'=>'array']; }
