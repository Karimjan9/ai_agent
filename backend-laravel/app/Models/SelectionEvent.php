<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SelectionEvent extends Model
{
    protected $fillable = [
        'training_session_id',
        'selection_type',
        'survivor_genome_ids',
        'archived_genome_ids',
        'criteria',
    ];

    protected $casts = [
        'survivor_genome_ids' => 'array',
        'archived_genome_ids' => 'array',
        'criteria' => 'array',
    ];

    public function trainingSession(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class);
    }
}
