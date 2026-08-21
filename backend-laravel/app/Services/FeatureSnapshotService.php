<?php

namespace App\Services;

use App\Models\FeatureSnapshot;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class FeatureSnapshotService
{
    public const PROTOCOL = 'immutable_feature_snapshot_v1';

    public function __construct(private FeatureProvenanceValidator $validator) {}

    /** @return array<string,mixed> */
    public function capture(string $symbol, string $timeframe, CarbonImmutable $asOf, array $values, array $sources = []): array
    {
        ksort($values);
        $dataHash = hash('sha256', json_encode($values, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES));
        $available = $asOf->addSecond();
        $provenance = [];
        foreach ($values as $key => $_) {
            $provenance[$key] = ['key' => $key, 'source' => $sources[$key] ?? 'market_reality_v1', 'timeframe' => strtoupper($timeframe), 'as_of' => $asOf->toIso8601String(), 'available_at' => $available->toIso8601String(), 'formula_version' => 'v1', 'normalization' => 'native_or_declared', 'freshness_seconds' => 0, 'lookahead_safe' => true, 'data_hash' => $dataHash, 'eligible_lanes' => ['research', 'paper']];
        }
        $check = $this->validator->validate($values, $provenance);
        if (! $check['valid']) {
            throw new InvalidArgumentException('Feature provenance invalid: '.implode(',', $check['reasons']));
        }
        $key = hash('sha256', implode('|', [strtoupper($symbol), strtoupper($timeframe), $asOf->toIso8601String(), $dataHash]));
        $row = FeatureSnapshot::firstOrCreate(['snapshot_key' => $key], ['symbol' => strtoupper($symbol), 'timeframe' => strtoupper($timeframe), 'as_of' => $asOf, 'available_at' => $available, 'data_hash' => $dataHash, 'values' => $values, 'provenance' => $provenance]);

        return ['protocol' => self::PROTOCOL, 'snapshot' => $row, 'valid' => true, 'promotion_evidence' => false];
    }
}
