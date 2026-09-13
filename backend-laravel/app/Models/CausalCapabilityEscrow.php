<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CausalCapabilityEscrow extends Model
{
    protected $fillable = [
        'escrow_key', 'agent_learning_causal_experiment_id', 'lab_learning_lane_pair_id',
        'agent_learning_settlement_id', 'protocol_epoch', 'symbol', 'timeframe',
        'strategy_family', 'target', 'gene_key', 'context_hash', 'lattice_state',
        'component_confirmed', 'composition_eligible', 'organism_viable',
        'reproductive_authority', 'evidence', 'evaluated_at',
    ];

    protected $casts = [
        'component_confirmed' => 'boolean', 'composition_eligible' => 'boolean',
        'organism_viable' => 'boolean', 'reproductive_authority' => 'boolean',
        'evidence' => 'array', 'evaluated_at' => 'datetime',
    ];
}
