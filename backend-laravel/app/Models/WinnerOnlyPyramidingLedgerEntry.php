<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WinnerOnlyPyramidingLedgerEntry extends Model
{
    protected $fillable = ['ledger_key', 'position_key', 'symbol', 'timeframe', 'state', 'add_number', 'open_risk_before', 'open_risk_after', 'initial_risk_limit', 'unrealized_r', 'contract', 'decided_at'];
    protected $casts = ['contract' => 'array', 'decided_at' => 'datetime'];
}
