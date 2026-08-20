<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DualTrackStatisticEvent extends Model
{
    protected $fillable = ['event_key', 'dual_track_outcome_id', 'cell_key', 'lane'];
}
