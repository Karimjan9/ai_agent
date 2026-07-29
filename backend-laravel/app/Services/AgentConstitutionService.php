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
                'high_volatility_risk_multiplier' => (float) ($parameters['high_volatility_risk_multiplier'] ?? .5)],
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
        $integrity = is_string($hash) && hash_equals($hash, $this->hash($document));
        $architectureMatches = data_get($model->metadata, 'strategy_architecture') === ($document['architecture'] ?? null);
        $falsified = (float) data_get($result, 'pf_attribution.stress_cost.profit_factor', 99) < 1.05
            || (float) data_get($result, 'max_drawdown_percent', data_get($result, 'max_drawdown', 0)) > 15
            || (float) data_get($result, 'monte_carlo.risk_of_ruin_percent', 0) > 10;
        return ['status' => $integrity && $architectureMatches ? ($falsified ? 'falsified' : 'verified') : 'invalid',
            'integrity' => $integrity, 'architecture_matches' => $architectureMatches, 'falsified_by_evidence' => $falsified,
            'hash' => $hash, 'document' => [...$document, 'hash' => $hash]];
    }

    private function hash(array $document): string
    {
        unset($document['hash']); ksort($document);
        return hash('sha256', json_encode($document, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES));
    }
}
