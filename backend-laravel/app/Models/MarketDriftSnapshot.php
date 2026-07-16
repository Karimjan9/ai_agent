<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class MarketDriftSnapshot extends Model
{
    protected $fillable=['symbol','timeframe','psi_score','volatility_ratio','mean_return_shift','status','metrics','detected_at'];
    protected $casts=['metrics'=>'array','detected_at'=>'datetime'];
}
