<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class PaperFill extends Model
{
    protected $fillable=['paper_order_id','fill_type','price','cost_percent','filled_at','payload'];
    protected $casts=['filled_at'=>'datetime','payload'=>'array'];
    public function order(): BelongsTo { return $this->belongsTo(PaperOrder::class,'paper_order_id'); }
}
