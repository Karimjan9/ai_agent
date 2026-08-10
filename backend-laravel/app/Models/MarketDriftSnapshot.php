<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class MarketDriftSnapshot extends Model
{
    protected $fillable = [
        'symbol', 'timeframe', 'psi_score', 'volatility_ratio', 'mean_return_shift',
        'status', 'metrics', 'detected_at', 'provider', 'data_hash', 'candle_count',
        'first_candle_at', 'last_candle_at', 'cutoff_at', 'evidence_status',
    ];

    protected $casts = [
        'metrics' => 'array', 'detected_at' => 'datetime',
        'first_candle_at' => 'datetime', 'last_candle_at' => 'datetime',
        'cutoff_at' => 'datetime',
    ];
}
