<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketCandleObservation extends Model
{
    protected $fillable = ['provider', 'symbol', 'timeframe', 'time', 'open', 'high', 'low', 'close', 'volume'];

    protected $casts = ['time' => 'datetime'];
}
