<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Immutable manifest; lifecycle projections are owned by SpecialistCouncilLifecycleService. */
class SpecialistCouncilVersion extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'manifest' => 'array', 'assessment' => 'array', 'paper_authority' => 'array',
        'sealed_at' => 'immutable_datetime', 'approved_at' => 'immutable_datetime',
        'effective_at' => 'immutable_datetime', 'activated_at' => 'immutable_datetime',
        'retired_at' => 'immutable_datetime',
    ];

    protected function asJson($value, $flags = 0)
    {
        return json_encode($value, $flags | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            foreach (['council_id', 'version', 'creator_id', 'manifest', 'manifest_hash', 'sealed_at'] as $field) {
                if ($version->isDirty($field)) throw new \LogicException('A sealed council manifest cannot be rewritten.');
            }
        });
    }
}
