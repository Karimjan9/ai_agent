<?php

namespace App\Services;

/**
 * Canonical, typed context identity.  It turns legacy free-form state into
 * the bounded axes used by research retrieval without deleting the original
 * evidence.  Unknown or cross-axis values fail closed as incomplete context.
 */
class ContextContractV2Service
{
    public const PROTOCOL = 'context_contract_v2';

    /** @return array<string,mixed> */
    public function project(array $state): array
    {
        $raw = [
            'regime' => $state['regime'] ?? null,
            'volatility' => $state['volatility'] ?? null,
            'session' => $state['session'] ?? null,
        ];
        $axes = [
            'regime' => $this->regime($raw['regime']),
            'volatility' => $this->volatility($raw['volatility']),
            'session' => $this->session($raw['session']),
        ];
        $invalid = [];
        foreach ($axes as $axis => $value) if ($value === null) $invalid[] = $axis;

        return [
            'protocol' => self::PROTOCOL,
            'version' => 2,
            'axes' => $axes,
            'raw_v1_axes' => $raw,
            'status' => $invalid === [] ? 'valid' : 'context_incomplete',
            'invalid_axes' => $invalid,
            'identity_hash' => hash('sha256', json_encode($axes, JSON_UNESCAPED_SLASHES)),
            'promotion_evidence' => false,
        ];
    }

    private function regime(mixed $value): ?string
    {
        return match ($this->key($value)) {
            'trend_up', 'uptrend', 'bull', 'bullish' => 'trend_up',
            'trend_down', 'downtrend', 'bear', 'bearish' => 'trend_down',
            'range', 'ranging', 'mean_reversion' => 'range',
            'transition', 'transitional' => 'transition',
            default => null,
        };
    }

    private function volatility(mixed $value): ?string
    {
        return match ($this->key($value)) {
            'low', 'low_volatility', 'compression' => 'low',
            'normal', 'normal_volatility', 'medium' => 'normal',
            'high', 'high_volatility', 'expansion' => 'high',
            default => null,
        };
    }

    private function session(mixed $value): ?string
    {
        return match ($this->key($value)) {
            'asia', 'asian' => 'asia',
            'london' => 'london',
            'new_york', 'newyork', 'ny' => 'new_york',
            'overlap', 'london_new_york_overlap', 'london_ny_overlap' => 'overlap',
            default => null,
        };
    }

    private function key(mixed $value): string
    {
        return strtolower(str_replace(['-', ' ', '/'], '_', trim((string) $value)));
    }
}
