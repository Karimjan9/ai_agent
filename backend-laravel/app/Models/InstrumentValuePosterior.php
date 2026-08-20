<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstrumentValuePosterior extends Model
{
    protected $fillable = ['trading_instrument_id', 'symbol', 'timeframe', 'state_key', 'observations', 'net_value', 'uncertainty', 'decay_state', 'value_vector', 'last_observed_at'];

    protected $casts = ['value_vector' => 'array', 'last_observed_at' => 'datetime'];

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(TradingInstrument::class, 'trading_instrument_id');
    }
}
