<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CapabilityProgressScoreboard extends Model
{
    protected $fillable = ['score_key', 'symbol', 'timeframe', 'progress_score', 'metrics', 'measured_at'];

    protected $casts = ['metrics' => 'array', 'measured_at' => 'datetime'];
}
