<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UniversalDriverRanking extends Model
{
    protected $fillable = [
        'quant_law_discovery_run_id',
        'driver_key',
        'driver_label',
        'rank',
        'impact_score',
        'confidence_score',
        'evidence_count',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(QuantLawDiscoveryRun::class, 'quant_law_discovery_run_id');
    }
}
