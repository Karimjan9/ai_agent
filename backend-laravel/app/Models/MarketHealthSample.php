<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketHealthSample extends Model
{
    public const CREATED_AT = 'sampled_at';
    public const UPDATED_AT = null;

    protected $fillable = ['provider', 'symbol', 'timeframe', 'status', 'age_seconds', 'candle_time', 'sampled_at'];
    protected $casts = ['candle_time' => 'datetime', 'sampled_at' => 'datetime'];
}
