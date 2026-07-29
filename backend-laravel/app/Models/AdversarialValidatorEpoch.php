<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AdversarialValidatorEpoch extends Model { protected $table='adversarial_validator_epochs'; protected $fillable=['epoch_key','status','validators','commitment_hash','frozen_at','retired_at']; protected $casts=['validators'=>'array','frozen_at'=>'datetime','retired_at'=>'datetime']; }
