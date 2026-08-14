<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketTrainingArchive extends Model
{
    protected $fillable = [
        'dataset_key',
        'provider',
        'symbol',
        'timeframe',
        'target_from',
        'target_to',
        'backfill_cursor_at',
        'status',
        'row_count',
        'completed_chunks',
        'failed_chunks',
        'first_candle_at',
        'last_candle_at',
        'last_chunk_from',
        'last_chunk_to',
        'last_attempt_at',
        'last_success_at',
        'last_error',
        'metrics',
    ];

    protected $casts = [
        'target_from' => 'datetime',
        'target_to' => 'datetime',
        'backfill_cursor_at' => 'datetime',
        'first_candle_at' => 'datetime',
        'last_candle_at' => 'datetime',
        'last_chunk_from' => 'datetime',
        'last_chunk_to' => 'datetime',
        'last_attempt_at' => 'datetime',
        'last_success_at' => 'datetime',
        'metrics' => 'array',
    ];
}
