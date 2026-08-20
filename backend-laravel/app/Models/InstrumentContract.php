<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstrumentContract extends Model
{
    protected $fillable = ['trading_instrument_id', 'compatible_regimes', 'forbidden_regimes', 'required_inputs', 'allowed_genes', 'cost_model', 'risk_model', 'control_contract', 'contract'];

    protected $casts = ['compatible_regimes' => 'array', 'forbidden_regimes' => 'array', 'required_inputs' => 'array', 'allowed_genes' => 'array', 'cost_model' => 'array', 'risk_model' => 'array', 'control_contract' => 'array', 'contract' => 'array'];

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(TradingInstrument::class, 'trading_instrument_id');
    }
}
