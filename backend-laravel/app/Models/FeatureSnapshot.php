<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class FeatureSnapshot extends Model
{
    protected $fillable = ['snapshot_key', 'symbol', 'timeframe', 'as_of', 'available_at', 'data_hash', 'values', 'provenance'];

    protected $casts = ['as_of' => 'datetime', 'available_at' => 'datetime', 'values' => 'array', 'provenance' => 'array'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Feature snapshots are immutable.'));
        static::deleting(fn () => throw new LogicException('Feature snapshots are immutable.'));
    }
}
