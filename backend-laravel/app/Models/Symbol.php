<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Symbol extends Model
{
    protected $fillable = [
        'code',
        'display_name',
        'asset_class',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function candles(): HasMany
    {
        return $this->hasMany(Candle::class);
    }
}
