<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DualTrackSnapshotManifest extends Model
{
    protected $fillable = ['snapshot_hash', 'symbol', 'timeframe', 'candle_count', 'first_candle_at', 'latest_candle_at', 'dataset_hash', 'feature_config_hash', 'execution_config_hash', 'manifest', 'status', 'promotion_evidence'];
    protected $casts = ['first_candle_at' => 'datetime', 'latest_candle_at' => 'datetime', 'manifest' => 'array', 'promotion_evidence' => 'boolean'];
}
