<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Candle extends Model
{
    protected $fillable = [
        'symbol_id',
        'timeframe',
        'time',
        'open',
        'high',
        'low',
        'close',
        'volume',
        'provider',
    ];

    protected $casts = [
        'time' => 'datetime',
    ];

    public function symbol(): BelongsTo
    {
        return $this->belongsTo(Symbol::class);
    }
}
