<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SealedHoldoutRelease extends Model
{
    protected $fillable=['model_market_performance_id','dataset_hash','status','score','result','opened_at','completed_at'];
    protected $casts=['result'=>'array','opened_at'=>'datetime','completed_at'=>'datetime'];
}
