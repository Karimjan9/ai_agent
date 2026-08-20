<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DualTrackMemoryReplay extends Model
{
    protected $table = 'dual_track_memory_replay_queue';
    protected $fillable = ['replay_key', 'dual_track_outcome_id', 'dual_track_memory_lesson_id', 'symbol', 'timeframe', 'cell_key', 'lane', 'priority_score', 'priority_reason', 'status', 'replay_count', 'evidence', 'available_at', 'last_replayed_at'];
    protected $casts = ['priority_score' => 'float', 'evidence' => 'array', 'available_at' => 'datetime', 'last_replayed_at' => 'datetime'];
}
