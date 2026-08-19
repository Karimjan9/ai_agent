<?php

namespace App\Domains\Laboratory\Services;

use App\Models\ModelVersion;

/** Immutable trading-policy thesis; parameters may tune it, never silently replace it. */
class AgentConstitutionService
{
    public function draft(string $symbol, string $timeframe, string $family, string $architecture, array $parameters): array
    {
        $allowed = match ($family) {
            'trend', 'momentum' => ['trend_up', 'trend_down'], 'mean_reversion' => ['range'],
            'breakout', 'volatility' => ['trend_up', 'trend_down', 'range'], default => ['trend_up', 'trend_down', 'range'],
        };
        $document = [
            'protocol' => 'agent_constitution_v1', 'status' => 'sealed',
            'strategy_thesis' => "{$symbol} {$family} / {$architecture} claims a cost-aware edge only in its declared operating envelope.",
            'strategy_family' => $family, 'architecture' => $architecture, 'timeframe' => $timeframe,
            'allowed_regimes' => $allowed, 'forbidden_regimes' => array_values(array_diff(['trend_up', 'trend_down', 'range'], $allowed)),
            'entry_conditions' => ['closed_candle_signal', 'next_candle_execution', 'positive_net_expected_value'],
            'exit_logic' => ['atr_stop_target', 'trailing_stop', 'time_stop'],
            'risk_limits' => ['max_drawdown_percent' => 15, 'risk_of_ruin_percent' => 10, 'stress_cost_pf_minimum' => 1.05,
                'high_volatility_risk_multiplier' => (float) ($parameters['high_volatility_risk_multiplier'] ?? .5),
                'trend_down_risk_multiplier' => (float) ($parameters['trend_down_risk_multiplier'] ?? 1.0)],
            'abstention_rules' => ['forbidden_regime', 'negative_net_ev', 'out_of_distribution', 'calendar_or_risk_veto', 'strong_council_disagreement'],
            'falsification_conditions' => ['stress_cost_pf_below_1_05', 'drawdown_above_15', 'ruin_risk_above_10', 'temporal_firewall_failure'],
            'mutation_rule' => 'A child may tune bounded parameters, but every research child must declare one executable hypothesis; a changed family, architecture or thesis requires a new constitution.',
            'evidence_contract' => [
                'protocol' => 'agent_strategy_evidence_v2',
                'exact_control_required' => true,
                'same_generation_control_required' => true,
                'behavioral_delta_required' => true,
                'signal_or_trade_or_event_ledger_delta_required' => true,
                'independent_chronological_windows_required' => 3,
                'minimum_positive_windows_required' => 2,
                'purge_and_embargo_required' => true,
                'forward_confirmation_required' => true,
                'mutation_credit_before_contract' => false,
                'promotion_evidence' => false,
            ],
        ];
        $document['hash'] = $this->hash($document);
        return $document;
    }

    public function verify(ModelVersion $model, array $result): array
    {
        $document = (array) data_get($model->metadata, 'agent_constitution', []);
        if ($document === []) return ['status' => 'legacy_unsealed', 'reason' => 'Model predates constitution protocol.'];
        $hash = $document['hash'] ?? null; unset($document['hash']);
        $canonicalHash = $this->hash($document); $legacyHash = $this->legacyHash($document);
        $canonicalMatch = is_string($hash) && hash_equals($hash, $canonicalHash);
        $legacyMatch = is_string($hash) && hash_equals($hash, $legacyHash);
        $integrity = $canonicalMatch || $legacyMatch;
        $architectureMatches = data_get($model->metadata, 'strategy_architecture') === ($document['architecture'] ?? null);
        $falsified = (float) data_get($result, 'pf_attribution.stress_cost.profit_factor', 99) < 1.05 || (float) data_get($result, 'max_drawdown_percent', data_get($result, 'max_drawdown', 0)) > 15 || (float) data_get($result, 'monte_carlo.risk_of_ruin_percent', 0) > 10;
        $evidence = $this->evidenceStatus($document, $result);

        return [
            'status' => $integrity && $architectureMatches ? ($falsified ? 'falsified' : 'verified') : 'invalid',
            'integrity' => $integrity,
            'architecture_matches' => $architectureMatches,
            'falsified_by_evidence' => $falsified,
            'strategic_evidence' => $evidence,
            'hash' => $hash,
            'hash_version' => $canonicalMatch ? 'canonical_v2' : ($legacyMatch ? 'legacy_v1' : null),
            'document' => [...$document, 'hash' => $hash],
        ];
    }

    /** @return array<string, mixed> */
    private function evidenceStatus(array $document, array $result): array
    {
        $contract = (array) data_get($document, 'evidence_contract', []);
        if ($contract === []) {
            return [
                'status' => 'legacy_not_required',
                'required' => false,
                'promotion_evidence' => false,
            ];
        }

        $observability = (array) data_get($result, 'mutation_observability', []);
        $windows = (array) data_get($result, 'forward_window_protocol', []);
        $checks = [
            'exact_control' => data_get($observability, 'mutation_contract.control_pair_status') === 'available',
            'behavioral_delta' => data_get($observability, 'observable_effect') === true
                && data_get($observability, 'mutation_contract.status') === 'passed',
            'ledger_delta' => data_get($observability, 'trade_ledger.changed') === true
                || data_get($observability, 'signal_decisions.changed') === true
                || data_get($observability, 'event_ledger.changed') === true,
            'three_independent_windows' => data_get($windows, 'independence_verified') === true
                && (int) data_get($windows, 'independent_windows', 0) >= (int) data_get($contract, 'independent_chronological_windows_required', 3),
            'minimum_positive_windows' => (int) data_get($windows, 'positive_windows', 0) >= (int) data_get($contract, 'minimum_positive_windows_required', 2),
            'purged_embargoed' => data_get($result, 'purged_validation.promotion_evidence') === true
                || data_get($result, 'purged_embargoed_validation.promotion_evidence') === true,
            'forward_confirmation' => data_get($result, 'forward_confirmation.status') === 'confirmed'
                || (int) data_get($result, 'challenger_protocol.observed_forward_windows', 0) >= 3
                    && (int) data_get($result, 'challenger_protocol.positive_forward_windows', 0) >= 2,
        ];

        return [
            'protocol' => (string) data_get($contract, 'protocol', 'agent_strategy_evidence_v2'),
            'status' => ! in_array(false, $checks, true) ? 'passed' : 'blocked',
            'required' => true,
            'checks' => $checks,
            'required_windows' => (int) data_get($contract, 'independent_chronological_windows_required', 3),
            'minimum_positive_windows' => (int) data_get($contract, 'minimum_positive_windows_required', 2),
            'promotion_evidence' => false,
        ];
    }

    private function hash(array $document): string { unset($document['hash']); ksort($document); return hash('sha256', json_encode($document, JSON_UNESCAPED_SLASHES)); }
    private function legacyHash(array $document): string
    {
        unset($document['hash']);
        foreach (['high_volatility_risk_multiplier', 'trend_down_risk_multiplier'] as $key) if (array_key_exists($key, (array) data_get($document, 'risk_limits'))) data_set($document, "risk_limits.{$key}", (float) data_get($document, "risk_limits.{$key}"));
        ksort($document); return hash('sha256', json_encode($document, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES));
    }
}
