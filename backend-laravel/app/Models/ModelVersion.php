<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ModelVersion extends Model
{
    protected $fillable = [
        'name',
        'strategy',
        'version',
        'generation',
        'status',
        'best_score',
        'best_winrate',
        'best_profit',
        'best_drawdown',
        'description',
        'change_log',
        'parameters',
        'promoted_at',
        'metadata',
    ];

    protected $casts = [
        'parameters' => 'array',
        'metadata' => 'array',
        'promoted_at' => 'datetime',
    ];

    public function evolutionProposals(): HasMany
    {
        return $this->hasMany(EvolutionProposal::class);
    }
}
