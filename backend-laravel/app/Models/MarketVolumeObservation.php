<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketVolumeObservation extends Model
{
    protected $fillable = [
        'source_contract', 'symbol', 'timeframe', 'time', 'raw_volume',
        'semantic', 'unit', 'status',
    ];

    protected $casts = [
        'time' => 'datetime',
        'raw_volume' => 'float',
    ];
}
