<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketSymbol extends Model
{
    protected $fillable = [
        'symbol',
        'provider_symbol',
        'name',
        'market_type',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
