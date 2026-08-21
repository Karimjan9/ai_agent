<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Immutable proof that an instrument was selected and actually consumed. */
class InstrumentInvocationLedger extends Model
{
    protected $table = 'instrument_invocation_ledger';
    protected $fillable = ['invocation_key', 'lab_agent_id', 'lab_generation_id', 'paper_signal_id', 'paper_order_id', 'trading_instrument_id', 'instrument_key', 'symbol', 'timeframe', 'state_key', 'input_hash', 'output_hash', 'used_in_decision', 'used_in_execution', 'verdict', 'causal_contribution', 'control_delta', 'metadata', 'invoked_at', 'settled_at'];
    protected $casts = ['used_in_decision' => 'boolean', 'used_in_execution' => 'boolean', 'causal_contribution' => 'float', 'control_delta' => 'array', 'metadata' => 'array', 'invoked_at' => 'datetime', 'settled_at' => 'datetime'];
}
