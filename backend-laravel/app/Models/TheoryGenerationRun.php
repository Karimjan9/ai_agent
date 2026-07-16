<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TheoryGenerationRun extends Model
{
    protected $fillable = ['status', 'started_at', 'finished_at', 'theories_generated', 'battles_created', 'predictions_created', 'unified_models_created', 'summary', 'metrics'];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'metrics' => 'array',
    ];

    public function theories(): HasMany
    {
        return $this->hasMany(QuantTheory::class);
    }
}
