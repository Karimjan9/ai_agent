<?php

namespace App\Services;

use App\Models\ModelVersion;

/** Immutable trading-policy thesis; parameters may tune it, never silently replace it. */
class AgentConstitutionService
{
    public function draft(string $symbol, string $timeframe, string $family, string $architecture, array $parameters): array
    {
        $allowed = match ($family) {
            'trend', 'momentum' => ['trend_up', 'trend_down'],
            'mean_reversion' => ['range'],
            'breakout', 'volatility' => ['trend_up', 'trend_down', 'range'],
            default => ['trend_up', 'trend_down', 'range'],
        };
        $document = [
            'protocol' => 'agent_constitution_v1', 'status' => 'sealed',
            'strategy_thesis' => "{$symbol} {$family} / {$architecture} claims a cost-aware edge only in its declared operating envelope.",
            'strategy_family' => $family, 'architecture' => $architecture, 'timeframe' => $timeframe,
            'allowed_regimes' => $allowed,
            'forbidden_regimes' => array_values(array_diff(['trend_up', 'trend_down', 'range'], $allowed)),
            'entry_conditions' => ['closed_candle_signal', 'next_candle_execution', 'positive_net_expected_value'],
            'exit_logic' => ['atr_stop_target', 'trailing_stop', 'time_stop'],
            'risk_limits' => ['max_drawdown_percent' => 15, 'risk_of_ruin_percent' => 10, 'stress_cost_pf_minimum' => 1.05,
                'high_volatility_risk_multiplier' => (float) ($parameters['high_volatility_risk_multiplier'] ?? .5),
                'trend_down_risk_multiplier' => (float) ($parameters['trend_down_risk_multiplier'] ?? 1.0)],
            'abstention_rules' => ['forbidden_regime', 'negative_net_ev', 'out_of_distribution', 'calendar_or_risk_veto', 'strong_council_disagreement'],
            'falsification_conditions' => ['stress_cost_pf_below_1_05', 'drawdown_above_15', 'ruin_risk_above_10', 'temporal_firewall_failure'],
            'mutation_rule' => 'A child may tune bounded parameters, but a changed family, architecture or thesis requires a new constitution.',
        ];
        $document['hash'] = $this->hash($document);
        return $document;
    }

    public function verify(ModelVersion $model, array $result): array
    {
        $document = (array) data_get($model->metadata, 'agent_constitution', []);
        if ($document === []) return ['status' => 'legacy_unsealed', 'reason' => 'Model predates constitution protocol.'];
        $hash = $document['hash'] ?? null; unset($document['hash']);
        // JSON round-tripping can represent 1.0 as 1. New constitutions use a
        // numeric-type-insensitive canonical hash; the legacy comparison keeps
        // previously sealed documents auditable without rewriting their
        // evidence. Neither path permits a changed document to pass.
        $canonicalHash = $this->hash($document);
        $legacyHash = $this->legacyHash($document);
        $canonicalMatch = is_string($hash) && hash_equals($hash, $canonicalHash);
        $legacyMatch = is_string($hash) && hash_equals($hash, $legacyHash);
        $integrity = $canonicalMatch || $legacyMatch;
        $architectureMatches = data_get($model->metadata, 'strategy_architecture') === ($document['architecture'] ?? null);
        $falsified = (float) data_get($result, 'pf_attribution.stress_cost.profit_factor', 99) < 1.05
            || (float) data_get($result, 'max_drawdown_percent', data_get($result, 'max_drawdown', 0)) > 15
            || (float) data_get($result, 'monte_carlo.risk_of_ruin_percent', 0) > 10;
        return ['status' => $integrity && $architectureMatches ? ($falsified ? 'falsified' : 'verified') : 'invalid',
            'integrity' => $integrity, 'architecture_matches' => $architectureMatches, 'falsified_by_evidence' => $falsified,
            'hash' => $hash, 'hash_version' => $canonicalMatch ? 'canonical_v2' : ($legacyMatch ? 'legacy_v1' : null),
            'document' => [...$document, 'hash' => $hash]];
    }

    private function hash(array $document): string
    {
        unset($document['hash']);
        ksort($document);
        // Do not preserve a zero fraction here: a persisted JSON 1.0 and 1
        // are the same constitution value and must produce the same digest.
        return hash('sha256', json_encode($document, JSON_UNESCAPED_SLASHES));
    }

    private function legacyHash(array $document): string
    {
        unset($document['hash']);
        // The original draft always cast these two risk multipliers to float,
        // but JSON decoding may return an exact 1.0 as an integer. Recreate
        // that old typed representation only for legacy verification.
        foreach (['high_volatility_risk_multiplier', 'trend_down_risk_multiplier'] as $key) {
            if (array_key_exists($key, (array) data_get($document, 'risk_limits'))) {
                data_set($document, "risk_limits.{$key}", (float) data_get($document, "risk_limits.{$key}"));
            }
        }
        ksort($document);
        return hash('sha256', json_encode($document, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES));
    }

}
