<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CotFeatureSnapshot extends Model
{
    protected $fillable = [
        'cot_report_id', 'symbol', 'feature_version', 'report_date', 'available_at',
        'managed_money_net', 'managed_money_delta_1w', 'managed_money_delta_4w',
        'managed_money_average_12w', 'managed_money_percentile_3y', 'commercial_percentile_3y',
        'crowding_index', 'positioning_state', 'weekly_bias', 'confidence_score', 'features',
    ];

    protected $casts = [
        'report_date' => 'date',
        'available_at' => 'datetime',
        'features' => 'array',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(CotReport::class, 'cot_report_id');
    }
}
