<?php

namespace App\Services;

/** Counts independent information families, never repeated momentum labels. */
class EvidenceOrthogonalityService
{
    public const PROTOCOL = 'evidence_orthogonality_contract_v1';

    /** @param array<int, string|array<string,mixed>> $signals @return array<string,mixed> */
    public function assess(array $signals): array
    {
        $tagged = collect($signals)->map(function ($signal): array {
            $id = is_array($signal) ? (string) ($signal['id'] ?? $signal['feature'] ?? '') : (string) $signal;
            $family = is_array($signal) ? (string) ($signal['information_family'] ?? '') : '';
            return ['id' => $id, 'information_family' => $family !== '' ? $family : $this->familyFor($id), 'timeframe' => is_array($signal) ? ($signal['timeframe'] ?? null) : null];
        })->filter(fn (array $row): bool => $row['id'] !== '')->values();
        $families = $tagged->pluck('information_family')->filter()->unique()->values();
        $raw = $tagged->count(); $independent = $families->count();
        return ['protocol' => self::PROTOCOL, 'signals' => $tagged->all(), 'raw_confirmations' => $raw, 'independent_families' => $families->all(), 'effective_confluence' => $independent, 'redundancy_penalty' => max(0, $raw - $independent), 'fitness_contract' => ['redundant_evidence_penalty' => max(0, $raw - $independent), 'independent_evidence_requires_oos_marginal_uplift' => true], 'promotion_evidence' => false];
    }

    private function familyFor(string $id): string
    {
        $id = strtolower($id);
        return match (true) {
            str_contains($id, 'ema') || str_contains($id, 'trend') || str_contains($id, 'direction') => 'direction',
            str_contains($id, 'bos') || str_contains($id, 'choch') || str_contains($id, 'swing') || str_contains($id, 'structure') => 'market_structure',
            str_contains($id, 'liquidity') || str_contains($id, 'sweep') || str_contains($id, 'fvg') => 'liquidity',
            str_contains($id, 'rsi') || str_contains($id, 'macd') || str_contains($id, 'stoch') || str_contains($id, 'cci') || str_contains($id, 'momentum') => 'momentum',
            str_contains($id, 'atr') || str_contains($id, 'volatility') || str_contains($id, 'bollinger') => 'volatility',
            str_contains($id, 'session') || str_contains($id, 'london') || str_contains($id, 'asia') => 'session',
            str_contains($id, 'spread') || str_contains($id, 'cost') || str_contains($id, 'slippage') => 'cost',
            str_contains($id, 'entry') || str_contains($id, 'engulf') || str_contains($id, 'execution') => 'execution_quality',
            default => 'location',
        };
    }
}
