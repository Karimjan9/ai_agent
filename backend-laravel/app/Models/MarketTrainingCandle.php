<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketTrainingCandle extends Model
{
    protected $fillable = [
        'dataset_key',
        'provider',
        'symbol',
        'timeframe',
        'time',
        'open',
        'high',
        'low',
        'close',
        'volume',
    ];

    protected $casts = [
        'time' => 'datetime',
    ];
}
