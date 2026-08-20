<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouterDecision extends Model
{
    protected $fillable = ['decision_key', 'symbol', 'timeframe', 'state_key', 'playbook_composition_id', 'decision', 'reason_code', 'state_fingerprint', 'candidates', 'metadata', 'decided_at'];

    protected $casts = ['state_fingerprint' => 'array', 'candidates' => 'array', 'metadata' => 'array', 'decided_at' => 'datetime'];

    public function playbook(): BelongsTo
    {
        return $this->belongsTo(PlaybookComposition::class, 'playbook_composition_id');
    }
}
