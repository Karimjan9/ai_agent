<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TradingInstrument extends Model
{
    protected $fillable = ['instrument_key', 'label', 'role', 'tactic_id', 'promotion_state', 'is_abstention', 'definition'];

    protected $casts = ['is_abstention' => 'boolean', 'definition' => 'array'];

    public function contract(): HasOne
    {
        return $this->hasOne(InstrumentContract::class);
    }

    public function posteriors(): HasMany
    {
        return $this->hasMany(InstrumentValuePosterior::class);
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(InstrumentEvidence::class);
    }
}
