<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocationAtlasEntry extends Model
{
    protected $fillable = ['atlas_key', 'symbol', 'timeframe', 'location_type', 'definition_version', 'state', 'formed_at', 'available_at', 'expires_at', 'invalidated_at', 'strength', 'touch_count', 'freshness', 'distance_in_atr', 'contract'];
    protected $casts = ['formed_at' => 'datetime', 'available_at' => 'datetime', 'expires_at' => 'datetime', 'invalidated_at' => 'datetime', 'strength' => 'float', 'freshness' => 'float', 'distance_in_atr' => 'float', 'contract' => 'array'];
}
