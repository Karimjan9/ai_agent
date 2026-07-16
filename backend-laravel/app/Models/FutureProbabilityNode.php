<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FutureProbabilityNode extends Model
{
    protected $fillable = [
        'future_simulation_run_id',
        'parent_id',
        'node_key',
        'label',
        'probability',
        'horizon_candles',
        'node_type',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(FutureSimulationRun::class, 'future_simulation_run_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(FutureProbabilityNode::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(FutureProbabilityNode::class, 'parent_id');
    }
}
