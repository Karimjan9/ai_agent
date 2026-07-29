<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class EvaluatorReputation extends Model { protected $fillable=['validator','findings_count','forward_confirmed_count','false_positive_count','reputation_score','passport_status','evidence']; protected $casts=['evidence'=>'array']; }
