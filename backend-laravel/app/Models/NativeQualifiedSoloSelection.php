<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Append-only original selection result; this is not independent or paper authority. */
class NativeQualifiedSoloSelection extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = ['receipt' => 'array', 'created_at' => 'immutable_datetime'];

    protected function asJson($value, $flags = 0)
    {
        return json_encode($value, $flags | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('ORIGINAL_SOLO_SELECTION_IS_IMMUTABLE'));
        static::deleting(fn () => throw new LogicException('ORIGINAL_SOLO_SELECTION_IS_IMMUTABLE'));
    }
}
