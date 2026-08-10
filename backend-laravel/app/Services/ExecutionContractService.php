<?php

namespace App\Services;

/**
 * One versioned execution profile for every replay lane.
 *
 * The hash covers the actual parameter map sent to Python. Symbol/timeframe
 * remain descriptive metadata so a parent and child on the same lane can be
 * compared without silently accepting a spread or gap-policy change.
 */
class ExecutionContractService
{
    public const PROTOCOL = 'canonical_market_execution_v1';
    public const VERSION = 'canonical_market_execution_v1';

    /** @return array<string, mixed> */
    public function for(string $symbol, string $timeframe = 'H1'): array
    {
        $parameters = $this->parameters($symbol);

        return [
            'protocol' => self::PROTOCOL,
            'version' => self::VERSION,
            'symbol' => $this->normalizeSymbol($symbol),
            'timeframe' => strtoupper($timeframe),
            'parameters' => $parameters,
            'execution_hash' => $this->hashParameters($parameters),
            'status' => 'sealed',
            'promotion_evidence' => true,
            'rule' => 'Lab, full replay, paper and sealed holdout must use this exact parameter map.',
        ];
    }

    /** @return array<string, mixed> */
    public function parameters(string $symbol): array
    {
        $isMetal = str_starts_with($this->normalizeSymbol($symbol), 'XAU');
        $defaults = (array) config('services.execution_contract', []);

        return [
            'spread_points' => (float) ($isMetal
                ? ($defaults['xau_spread_points'] ?? config('services.risk.xau_spread_points', 35))
                : ($defaults['fx_spread_points'] ?? config('services.risk.fx_spread_points', 12))),
            'point_size' => (float) ($isMetal ? ($defaults['xau_point_size'] ?? 0.01) : ($defaults['fx_point_size'] ?? 0.00001)),
            'commission_percent' => (float) ($defaults['commission_percent'] ?? 0.01),
            'slippage_points' => (float) ($defaults['slippage_points'] ?? config('services.risk.slippage_points', 2)),
            'swap_per_day_percent' => (float) ($defaults['swap_per_day_percent'] ?? 0.002),
            'allowed_sessions_utc' => array_values((array) ($defaults['allowed_sessions_utc'] ?? ['1-22'])),
            'min_volume' => null,
            'intrabar_policy' => (string) ($defaults['intrabar_policy'] ?? 'conservative'),
            'max_gap_multiple' => (float) ($defaults['max_gap_multiple'] ?? 96),
            // Fail closed consistently. Expected weekend/holiday closures are
            // handled by the canonical data-quality calendar, not by this
            // switch being relaxed in lab or paper.
            'reject_unexpected_gaps' => (bool) ($defaults['reject_unexpected_gaps'] ?? true),
            'stop_loss_percent' => (float) ($defaults['stop_loss_percent'] ?? 0.5),
            'take_profit_percent' => (float) ($defaults['take_profit_percent'] ?? 1.0),
            'max_leverage' => (float) ($defaults['max_leverage'] ?? 5),
        ];
    }

    public function hashParameters(array $parameters): string
    {
        return hash('sha256', json_encode($this->canonicalize($parameters), JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES));
    }

    public function matches(array $contract, string $symbol, string $timeframe = 'H1'): bool
    {
        $expected = $this->for($symbol, $timeframe);
        $receivedHash = (string) data_get($contract, 'execution_hash', '');

        return data_get($contract, 'protocol') === self::PROTOCOL
            && data_get($contract, 'version') === self::VERSION
            && $receivedHash !== ''
            && hash_equals($expected['execution_hash'], $receivedHash)
            && $this->hashParameters((array) data_get($contract, 'parameters', [])) === $expected['execution_hash'];
    }

    private function normalizeSymbol(string $symbol): string
    {
        return strtoupper(str_replace(['/', '_', '-'], '', trim($symbol)));
    }

    private function canonicalize(array $value): array
    {
        if (! array_is_list($value)) ksort($value);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            } elseif (is_int($item) || is_float($item)) {
                // JSON/PDO may round-trip 35.0 as integer 35. Preserve one
                // numeric identity so the same contract hashes identically
                // before and after database serialization.
                $value[$key] = (float) $item;
            }
        }
        return $value;
    }
}
