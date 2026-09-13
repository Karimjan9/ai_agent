<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContextualInstrumentBundleEffect extends Model
{
    protected $fillable = [
        'effect_key', 'cooperative_experiment_settlement_id', 'lab_generation_id',
        'protocol_epoch', 'symbol', 'timeframe', 'context_cell_key', 'instrument_a',
        'instrument_b', 'bundle_hash', 'effect_type', 'marginal_effect',
        'interaction_effect', 'leave_one_out_effect', 'observations', 'authority_level',
        'contraindicated', 'evidence', 'observed_at',
    ];

    protected $casts = [
        'marginal_effect' => 'float', 'interaction_effect' => 'float',
        'leave_one_out_effect' => 'float', 'contraindicated' => 'boolean',
        'evidence' => 'array', 'observed_at' => 'datetime',
    ];
}
