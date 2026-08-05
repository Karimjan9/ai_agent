<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EliteAgentPortfolio extends Model
{
    protected $fillable = [
        'symbol', 'timeframe', 'portfolio_key', 'status', 'gate_status', 'member_count',
        'gate_reasons', 'route_policy', 'evidence', 'membership_hash', 'execution_hash',
        'last_evaluated_at',
    ];

    protected $casts = [
        'gate_reasons' => 'array', 'route_policy' => 'array', 'evidence' => 'array',
        'last_evaluated_at' => 'datetime',
    ];

    public function members(): HasMany
    {
        return $this->hasMany(EliteAgentPortfolioMember::class);
    }
}
