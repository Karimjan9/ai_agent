<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AdversarialValidatorFinding extends Model { protected $fillable=['adversarial_validator_epoch_id','model_version_id','validator','verdict','evidence']; protected $casts=['evidence'=>'array']; }
