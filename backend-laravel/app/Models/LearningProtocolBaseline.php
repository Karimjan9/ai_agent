<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LearningProtocolBaseline extends Model
{
    protected $fillable = ['protocol_version', 'lab_generation_id', 'snapshot_hash', 'snapshot', 'frozen_at'];

    protected $casts = ['snapshot' => 'array', 'frozen_at' => 'datetime'];

    public function generation(): BelongsTo
    {
        return $this->belongsTo(LabGeneration::class, 'lab_generation_id');
    }
}
