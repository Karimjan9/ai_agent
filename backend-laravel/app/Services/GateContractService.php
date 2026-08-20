<?php

namespace App\Services;

/**
 * Canonical reason-to-gate registry. Legacy semantic targets are kept by the
 * caller for historical lineage, while this registry supplies the exact
 * optimization gate so calendar, temporal and train/forward failures cannot
 * silently share one mutation lane.
 */
class GateContractService
{
    public const PROTOCOL = 'gate_contract_v1';

    /** @var array<string, array{optimization_target:string,gate:string,lane:string}> */
    private const CONTRACTS = [
        'FAILED_TEMPORAL_CHUNK_SURVIVAL' => ['optimization_target' => 'temporal_stability', 'gate' => 'temporal_stability', 'lane' => 'temporal_state'],
        'FAILED_CALENDAR_MONTH_SURVIVAL' => ['optimization_target' => 'calendar_stability', 'gate' => 'calendar_stability', 'lane' => 'calendar_session'],
        'FAILED_MONTHLY_SURVIVAL' => ['optimization_target' => 'monthly_survival', 'gate' => 'calendar_stability', 'lane' => 'calendar_session'],
        'FAILED_TRAIN_FORWARD_GAP' => ['optimization_target' => 'train_forward_robustness', 'gate' => 'train_forward_robustness', 'lane' => 'robustness_split'],
        'FAILED_TEMPORAL_SCORE_DRIFT' => ['optimization_target' => 'temporal_score_drift', 'gate' => 'temporal_score_drift', 'lane' => 'temporal_state'],
        'FAILED_STRATIFIED_HISTORICAL_SURVIVAL' => ['optimization_target' => 'temporal_stability', 'gate' => 'temporal_stability', 'lane' => 'temporal_state'],
        'FAILED_STRATIFIED_HISTORICAL_CATASTROPHIC' => ['optimization_target' => 'temporal_stability', 'gate' => 'temporal_stability', 'lane' => 'temporal_state'],
        'INSUFFICIENT_STRATIFIED_HISTORICAL_EVIDENCE' => ['optimization_target' => 'temporal_stability', 'gate' => 'temporal_stability', 'lane' => 'temporal_state'],
        'FAILED_PARAMETER_STABILITY' => ['optimization_target' => 'parameter_stability', 'gate' => 'parameter_stability', 'lane' => 'robustness_split'],
        'FAILED_SIGNAL_TIMING_STABILITY' => ['optimization_target' => 'temporal_stability', 'gate' => 'temporal_stability', 'lane' => 'temporal_state'],
        'FAILED_STRESS_COST' => ['optimization_target' => 'stress_cost', 'gate' => 'stress_cost', 'lane' => 'cost_exit'],
        'FAILED_EXECUTION_STRESS_GATE' => ['optimization_target' => 'stress_cost', 'gate' => 'stress_cost', 'lane' => 'cost_exit'],
        'FAILED_WOUND_TEMPORAL_CHUNK' => ['optimization_target' => 'temporal_stability', 'gate' => 'temporal_stability', 'lane' => 'temporal_state'],
        'FAILED_WOUND_CALENDAR_MONTH' => ['optimization_target' => 'calendar_stability', 'gate' => 'calendar_stability', 'lane' => 'calendar_session'],
        'FAILED_WOUND_TRAIN_FORWARD_GAP' => ['optimization_target' => 'train_forward_robustness', 'gate' => 'train_forward_robustness', 'lane' => 'robustness_split'],
        'FAILED_WOUND_COST_EXIT_STRESS' => ['optimization_target' => 'stress_cost', 'gate' => 'stress_cost', 'lane' => 'cost_exit'],
        'FAILED_REGIME_COVERAGE' => ['optimization_target' => 'regime_coverage', 'gate' => 'regime_coverage', 'lane' => 'regime_abstention'],
        'INSUFFICIENT_REGIME_EVIDENCE' => ['optimization_target' => 'regime_coverage', 'gate' => 'regime_coverage', 'lane' => 'regime_abstention'],
        'FAILED_TRANSITION' => ['optimization_target' => 'regime_coverage', 'gate' => 'regime_coverage', 'lane' => 'regime_abstention'],
        'FAILED_PROFIT_FACTOR' => ['optimization_target' => 'profit_factor', 'gate' => 'profit_factor', 'lane' => 'entry_quality'],
        'FAILED_NON_POSITIVE_SCORE' => ['optimization_target' => 'profit_factor', 'gate' => 'profit_factor', 'lane' => 'entry_quality'],
        'FAILED_FORWARD_SCORE' => ['optimization_target' => 'profit_factor', 'gate' => 'profit_factor', 'lane' => 'entry_quality'],
        'FAILED_TRADE_COUNT' => ['optimization_target' => 'trade_frequency', 'gate' => 'trade_frequency', 'lane' => 'opportunity_recall'],
        'FAILED_LOW_SCREEN_TRADES' => ['optimization_target' => 'trade_frequency', 'gate' => 'trade_frequency', 'lane' => 'opportunity_recall'],
        'FAILED_NO_OPPORTUNITY' => ['optimization_target' => 'trade_frequency', 'gate' => 'trade_frequency', 'lane' => 'opportunity_recall'],
        'FAILED_NON_TARGET_REGRESSION' => ['optimization_target' => 'drawdown_risk', 'gate' => 'drawdown_risk', 'lane' => 'risk_exit'],
        'FAILED_DRAWDOWN' => ['optimization_target' => 'drawdown_risk', 'gate' => 'drawdown_risk', 'lane' => 'risk_exit'],
        'FAILED_RUIN' => ['optimization_target' => 'drawdown_risk', 'gate' => 'ruin_risk', 'lane' => 'risk_exit'],
        'FAILED_RUIN_RISK' => ['optimization_target' => 'drawdown_risk', 'gate' => 'ruin_risk', 'lane' => 'risk_exit'],
        'FAILED_OVERFIT' => ['optimization_target' => 'architecture', 'gate' => 'architecture', 'lane' => 'architecture_state'],
        'FAILED_STATISTICAL' => ['optimization_target' => 'architecture', 'gate' => 'architecture', 'lane' => 'architecture_state'],
    ];

    /**
     * The metric, threshold and direction are part of the same contract as
     * the reason-to-target mapping. Forward values intentionally mirror the
     * existing stricter promotion policy; no threshold is lowered here.
     *
     * @var array<string, array{paths:array<int,string>,screening:array{threshold:float,direction:string,scale:float},forward:array{threshold:float,direction:string,scale:float}}>
     */
    private const GATE_DEFINITIONS = [
        'trade_frequency' => [
            'paths' => ['total_trades', 'sample_count'],
            'screening' => ['threshold' => 10.0, 'direction' => 'higher', 'scale' => 10.0],
            'forward' => ['threshold' => 30.0, 'direction' => 'higher', 'scale' => 30.0],
        ],
        'profit_factor' => [
            'paths' => ['profit_factor'],
            'screening' => ['threshold' => 1.0, 'direction' => 'higher', 'scale' => .30],
            'forward' => ['threshold' => 1.30, 'direction' => 'higher', 'scale' => .30],
        ],
        'drawdown_risk' => [
            'paths' => ['max_drawdown_percent', 'max_drawdown'],
            'screening' => ['threshold' => 100.0, 'direction' => 'lower', 'scale' => 25.0],
            'forward' => ['threshold' => 15.0, 'direction' => 'lower', 'scale' => 15.0],
        ],
        'ruin_risk' => [
            'paths' => ['monte_carlo.risk_of_ruin_percent', 'risk_of_ruin'],
            'screening' => ['threshold' => 100.0, 'direction' => 'lower', 'scale' => 25.0],
            'forward' => ['threshold' => 10.0, 'direction' => 'lower', 'scale' => 10.0],
        ],
        'stress_cost' => [
            'paths' => ['screening_survival.stress_cost_pf', 'pf_attribution.stress_cost.profit_factor', 'stress_test.profit_factor'],
            'screening' => ['threshold' => 1.05, 'direction' => 'higher', 'scale' => .25],
            'forward' => ['threshold' => 1.05, 'direction' => 'higher', 'scale' => .25],
        ],
        'temporal_stability' => [
            'paths' => ['screening_survival.worst_temporal_chunk_pf', 'screening_survival.worst_window_pf', 'monthly_passport.worst_month_pf'],
            'screening' => ['threshold' => 1.0, 'direction' => 'higher', 'scale' => .25],
            'forward' => ['threshold' => 1.0, 'direction' => 'higher', 'scale' => .25],
        ],
        'calendar_stability' => [
            'paths' => ['window_survival.positive_windows', 'rolling_forward_wins'],
            'screening' => ['threshold' => 3.0, 'direction' => 'higher', 'scale' => 3.0],
            'forward' => ['threshold' => 3.0, 'direction' => 'higher', 'scale' => 3.0],
        ],
        'train_forward_robustness' => [
            'paths' => ['screening_survival.train_forward_gap', 'train_forward_gap'],
            'screening' => ['threshold' => 25.0, 'direction' => 'lower', 'scale' => 25.0],
            'forward' => ['threshold' => 25.0, 'direction' => 'lower', 'scale' => 25.0],
        ],
        'temporal_score_drift' => [
            'paths' => ['screening_survival.temporal_score_drift', 'screening_survival.worst_temporal_chunk_pf'],
            'screening' => ['threshold' => 25.0, 'direction' => 'lower', 'scale' => 25.0],
            'forward' => ['threshold' => 25.0, 'direction' => 'lower', 'scale' => 25.0],
        ],
        'parameter_stability' => [
            'paths' => ['screening_survival.parameter_perturbation_ratio', 'parameter_perturbation_ratio'],
            'screening' => ['threshold' => .80, 'direction' => 'higher', 'scale' => .20],
            'forward' => ['threshold' => .80, 'direction' => 'higher', 'scale' => .20],
        ],
        'regime_coverage' => [
            'paths' => ['screening_survival.worst_regime_pf', 'statistical_evidence.edge_quality.worst_regime_pf'],
            'screening' => ['threshold' => 1.0, 'direction' => 'higher', 'scale' => .25],
            'forward' => ['threshold' => 1.0, 'direction' => 'higher', 'scale' => .25],
        ],
    ];

    /** @return array<string, mixed>|null */
    public function forReason(string $reason): ?array
    {
        $reason = strtoupper(trim($reason));
        $reason = preg_replace('/^FAILED_RESCUE_/', 'FAILED_', $reason) ?: $reason;

        $contract = self::CONTRACTS[$reason] ?? null;
        if ($contract === null) return null;

        $definition = self::GATE_DEFINITIONS[(string) data_get($contract, 'gate', '')] ?? null;

        return $definition === null
            ? [...$contract, 'contract_status' => 'invalid']
            : [
                ...$contract,
                'observed_metric' => data_get($definition, 'paths', []),
                'screening_contract' => is_array(data_get($definition, 'screening')) ? data_get($definition, 'screening') : [],
                'forward_contract' => is_array(data_get($definition, 'forward')) ? data_get($definition, 'forward') : [],
                'contract_status' => is_array(data_get($definition, 'screening')) && is_array(data_get($definition, 'forward'))
                    ? 'valid'
                    : 'invalid',
            ];
    }

    /**
     * Gate contract self-check. A malformed contract is an operational
     * blocker, never a strategy failure and never a reason to dispatch replay.
     *
     * @return array<string, mixed>
     */
    public function health(): array
    {
        $issues = [];

        foreach (array_keys(self::GATE_DEFINITIONS) as $gate) {
            $definition = self::GATE_DEFINITIONS[$gate] ?? null;
            foreach (['paths', 'screening', 'forward'] as $key) {
                if (! is_array(data_get($definition, $key)) || data_get($definition, $key) === []) {
                    $issues[] = sprintf('%s.%s is missing or empty', $gate, $key);
                }
            }
        }

        return [
            'protocol' => self::PROTOCOL,
            'healthy' => $issues === [],
            'issues' => array_values(array_unique($issues)),
            'promotion_evidence' => false,
        ];
    }

    public function gateForReason(string $reason): ?string
    {
        return data_get($this->forReason($reason), 'gate');
    }

    public function optimizationTargetForReason(string $reason): ?string
    {
        return data_get($this->forReason($reason), 'optimization_target');
    }

    /** @return array<string, array<string, mixed>> */
    public function gateDefinitions(string $stage = 'screening'): array
    {
        $stage = $stage === 'forward' ? 'forward' : 'screening';

        return collect(self::GATE_DEFINITIONS)->map(function (array $definition) use ($stage): array {
            return [
                'paths' => data_get($definition, 'paths', []),
                'observed_metric' => data_get($definition, 'paths', []),
                ...(is_array(data_get($definition, $stage)) ? data_get($definition, $stage) : []),
            ];
        })->all();
    }

    /** @return array<string, mixed>|null */
    public function definitionForGate(string $gate, string $stage = 'screening'): ?array
    {
        return $this->gateDefinitions($stage)[trim($gate)] ?? null;
    }

    /** @return array<int, array<string, mixed>> */
    public function contracts(array $reasons): array
    {
        $rows = [];
        foreach (array_values(array_unique(array_map('strval', $reasons))) as $reason) {
            $contract = $this->forReason($reason);
            if ($contract === null) continue;
            $rows[] = ['reason' => strtoupper($reason), ...$contract, 'protocol' => self::PROTOCOL];
        }

        return $rows;
    }
}
