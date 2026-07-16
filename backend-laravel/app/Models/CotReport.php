<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CotReport extends Model
{
    protected $fillable = [
        'symbol', 'source', 'source_record_id', 'market_name', 'report_date', 'available_at',
        'release_time_estimated', 'open_interest', 'managed_money_long', 'managed_money_short',
        'managed_money_spread', 'managed_money_net', 'commercial_long', 'commercial_short',
        'commercial_net', 'raw_payload', 'ingested_at',
    ];

    protected $casts = [
        'report_date' => 'date',
        'available_at' => 'datetime',
        'release_time_estimated' => 'boolean',
        'raw_payload' => 'array',
        'ingested_at' => 'datetime',
    ];

    public function featureSnapshots(): HasMany
    {
        return $this->hasMany(CotFeatureSnapshot::class);
    }
}
