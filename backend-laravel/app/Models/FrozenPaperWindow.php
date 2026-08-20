<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FrozenPaperWindow extends Model
{
    protected $fillable = [
        'dataset_key', 'provider', 'symbol', 'timeframe', 'window_key',
        'training_starts_at', 'training_ends_at',
        'paper_starts_at', 'paper_ends_at', 'months',
        'snapshot_path', 'snapshot_sha256', 'row_count', 'frozen_at',
    ];

    protected $casts = [
        'training_starts_at' => 'datetime',
        'training_ends_at' => 'datetime',
        'paper_starts_at' => 'datetime',
        'paper_ends_at' => 'datetime',
        'frozen_at' => 'datetime',
    ];
}
