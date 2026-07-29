<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PaperExecutionEvent extends Model { protected $fillable=['model_market_performance_id','paper_signal_id','paper_order_id','event_type','provider','idempotency_key','occurred_at','requested_price','filled_price','requested_units','filled_units','latency_ms','reason','retry_count','payload']; protected $casts=['occurred_at'=>'datetime','payload'=>'array']; }
