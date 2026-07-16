<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class PaperOrder extends Model
{
    protected $fillable = ['model_market_performance_id','paper_signal_id','broker','external_order_id','symbol','timeframe','direction','units','entry_price','stop_loss','take_profit','exit_price','profit_percent','status','opened_at','closed_at','signal_context','broker_payload','evidence_status','invalidated_at','invalidation_reason'];
    protected $casts = ['signal_context'=>'array','broker_payload'=>'array','opened_at'=>'datetime','closed_at'=>'datetime','invalidated_at'=>'datetime'];
    public function marketPerformance(): BelongsTo { return $this->belongsTo(ModelMarketPerformance::class); }
    public function fills(): HasMany { return $this->hasMany(PaperFill::class); }
    public function signal(): BelongsTo { return $this->belongsTo(PaperSignal::class, 'paper_signal_id'); }
    public function outcome(): \Illuminate\Database\Eloquent\Relations\HasOne { return $this->hasOne(PaperSignalOutcome::class); }
}
