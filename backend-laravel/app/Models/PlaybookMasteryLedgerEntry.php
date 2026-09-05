<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlaybookMasteryLedgerEntry extends Model
{
    protected $fillable = ['entry_key', 'full_stack_playbook_passport_id', 'stage', 'status', 'fold_count', 'procedural_dimensions', 'economic_evidence', 'evidence', 'settled_at'];

    protected $casts = ['procedural_dimensions' => 'array', 'economic_evidence' => 'array', 'evidence' => 'array', 'settled_at' => 'datetime'];
}
