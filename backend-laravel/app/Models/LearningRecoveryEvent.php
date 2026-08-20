<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LearningRecoveryEvent extends Model
{
    protected $fillable = [
        'event_key', 'source_type', 'source_key', 'symbol', 'timeframe',
        'status', 'action', 'reason', 'metadata', 'reconciled_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'reconciled_at' => 'datetime',
    ];
}
