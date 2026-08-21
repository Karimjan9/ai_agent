<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class FeatureProvenanceValidator
{
    /** @return array{valid:bool,reasons:array<int,string>} */
    public function validate(array $values, array $provenance, string $lane = 'paper'): array
    {
        $reasons = [];
        foreach ($values as $key => $_) {
            $meta = (array) ($provenance[$key] ?? []);
            if (! (bool) ($meta['lookahead_safe'] ?? false)) {
                $reasons[] = "{$key}:LOOKAHEAD_UNSAFE";
            }
            if (! filled($meta['source'] ?? null) || ! filled($meta['timeframe'] ?? null) || ! filled($meta['data_hash'] ?? null)) {
                $reasons[] = "{$key}:MISSING_PROVENANCE";
            }
            if (! in_array($lane, (array) ($meta['eligible_lanes'] ?? []), true)) {
                $reasons[] = "{$key}:LANE_NOT_ELIGIBLE";
            }
            if (isset($meta['available_at'], $meta['as_of']) && CarbonImmutable::parse($meta['available_at'])->lt(CarbonImmutable::parse($meta['as_of']))) {
                $reasons[] = "{$key}:AVAILABILITY_INVALID";
            }
        }

        return ['valid' => $reasons === [], 'reasons' => $reasons];
    }
}
