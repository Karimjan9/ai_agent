<?php

namespace App\Services;

/** Distinguishes a genuine zero volume print from unavailable volume data. */
class VolumeProvenanceContractService
{
    public const PROTOCOL = 'volume_provenance_contract_v1';

    /** @return array<string,mixed> */
    public function compile(array $context = []): array
    {
        $value = $context['volume_value'] ?? null;
        $available = array_key_exists('volume_available', $context) ? (bool) $context['volume_available'] : $value !== null;
        $quality = (string) ($context['quality_status'] ?? ($available ? 'unknown' : 'unavailable'));
        $coverage = (float) ($context['coverage'] ?? 0);
        $minimum = (float) config('services.market_volume.minimum_coverage', .95);
        $eligible = $available && $quality === 'usable' && $coverage >= $minimum;
        return ['protocol' => self::PROTOCOL, 'provider' => $context['provider'] ?? config('services.market_volume.provider', 'dukascopy'), 'volume_type' => $context['volume_type'] ?? 'tick_volume', 'volume_value' => $value, 'volume_unavailable' => ! $available, 'coverage' => $coverage, 'quality_status' => $quality, 'available_at' => $context['available_at'] ?? null, 'mandatory_confirmation_eligible' => $eligible, 'rule' => 'zero_value_and_unavailable_are_distinct; non-eligible volume cannot be mandatory confirmation', 'promotion_evidence' => false];
    }
}
