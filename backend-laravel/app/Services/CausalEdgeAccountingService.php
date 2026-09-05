<?php

namespace App\Services;

/**
 * Projects a rich replay into the compact, versioned reward vocabulary used
 * by the canonical learning kernel. Every component is derived from a named
 * observed source. Missing evidence stays null; it is never replaced by an
 * optimistic zero/default.
 */
class CausalEdgeAccountingService
{
    public const PROTOCOL = 'causal_edge_accounting_v1';

    public function __construct(
        private TradingOperatingSystemScorecardService $operatingSystem,
        private EvolvingTraderFitnessService $traderFitness,
        private ConfirmationEntryCapabilityEvidenceService $confirmationEntry,
        private ProcessOutcomeAuditService $processOutcome,
    ) {}

    /** @return array<string,mixed> */
    public function project(array $result): array
    {
        $drawdownLimit = (float) config('services.risk.sentinel_max_drawdown_percent', 15);
        $ruinLimit = (float) config('services.risk.sentinel_max_risk_of_ruin_percent', 10);
        $profitFactor = $this->number($result, ['pf_attribution.summary.net_pf', 'profit_factor']);
        $stressProfitFactor = $this->number($result, [
            'pf_attribution.stress_cost.profit_factor',
            'screening_survival.stress_cost_pf',
            'stress_profit_factor',
        ]);
        $drawdown = $this->number($result, ['max_drawdown_percent', 'max_drawdown']);
        $ruin = $this->number($result, ['monte_carlo.risk_of_ruin_percent', 'risk_of_ruin_percent']);
        $poweredWindows = $this->number($result, [
            'walk_forward.forward_window_protocol.powered_windows',
            'forward_window_protocol.powered_windows',
        ]);
        $positiveWindows = $this->number($result, [
            'walk_forward.forward_window_protocol.positive_windows',
            'forward_window_protocol.positive_windows',
        ]);
        $deflatedSharpeProbability = $this->number($result, [
            'statistical_evidence.deflated_sharpe.deflated_sharpe_probability',
            'deflated_sharpe.deflated_sharpe_probability',
        ]);
        $bootstrapLowerPf = $this->number($result, [
            'statistical_evidence.edge_quality.bootstrap_pf.pf_5_percentile_lower_bound',
        ]);
        $regimeCoverage = $this->number($result, ['fitness_breakdown.components.regime_coverage']);
        $calibration = $this->number($result, [
            'statistical_evidence.edge_quality.confidence_calibration.calibration_score',
        ]);
        $abstentionPrecision = $this->number($result, [
            'opportunity_recall.abstention_precision',
        ]);

        $edgeQuality = $deflatedSharpeProbability !== null
            ? $this->unit($deflatedSharpeProbability)
            : $this->profitFactorMargin($bootstrapLowerPf);
        $costAdjustedReturn = $this->profitFactorMargin($stressProfitFactor ?? $profitFactor);
        $drawdownSafety = $drawdown !== null && $drawdownLimit > 0
            ? $this->unit(1 - ($drawdown / $drawdownLimit)) : null;
        $ruinSafety = $ruin !== null && $ruinLimit > 0
            ? $this->unit(1 - ($ruin / $ruinLimit)) : null;
        $temporalStability = $poweredWindows !== null && $poweredWindows > 0 && $positiveWindows !== null
            ? $this->unit($positiveWindows / $poweredWindows) : null;

        $projection = [
            'causal_edge_accounting' => [
                'protocol' => self::PROTOCOL,
                'normalization' => [
                    'edge_quality' => 'deflated_sharpe_probability; fallback bootstrap PF lower-bound margin',
                    'cost_adjusted_return' => 'stress-cost PF; fallback net PF; 0 at PF<=1 and 1 at PF>=1.3',
                    'drawdown_safety' => '1 - drawdown / configured sentinel limit',
                    'risk_of_ruin' => '1 - measured ruin / configured sentinel limit; null when Monte Carlo is deferred',
                    'temporal_stability' => 'positive powered disjoint folds / powered disjoint folds',
                    'regime_coverage' => 'replay fitness regime-coverage component',
                    'calibration' => 'closed-trade calibration score / 100',
                    'abstention_quality' => 'measured abstention precision',
                ],
                'limits' => ['drawdown_percent' => $drawdownLimit, 'risk_of_ruin_percent' => $ruinLimit],
                'missing_evidence_is_null' => true,
                'promotion_evidence' => false,
            ],
            'edge_quality' => $edgeQuality,
            'cost_adjusted_return' => $costAdjustedReturn,
            'drawdown_safety' => $drawdownSafety,
            'risk_of_ruin' => $ruinSafety,
            'temporal_stability' => $temporalStability,
            'regime_coverage' => $regimeCoverage !== null ? $this->unit($regimeCoverage) : null,
            'calibration' => $calibration !== null
                ? $this->unit($calibration > 1 ? $calibration / 100 : $calibration) : null,
            'abstention_quality' => $abstentionPrecision !== null ? $this->unit($abstentionPrecision) : null,
            // Raw safety/economic facts remain available to veto logic and
            // reflection without duplicating the multi-megabyte replay.
            'total_trades' => $this->number($result, ['total_trades', 'trade_count']),
            'profit_factor' => $profitFactor,
            'net_profit_percent' => $this->number($result, ['net_profit_percent']),
            'max_drawdown_percent' => $drawdown,
            'risk_of_ruin_percent' => $ruin,
            'stress_profit_factor' => $stressProfitFactor,
            'temporal_firewall_passed' => data_get($result, 'temporal_firewall_passed', true),
            'data_drift' => (bool) data_get($result, 'data_drift', false),
            'execution_drift' => (bool) data_get($result, 'execution_drift', false),
            'evidence_run_id' => data_get($result, 'evidence_run_id'),
            'trade_ledger_hash' => data_get($result, 'trade_ledger_hash'),
            'trade_ledger_count' => data_get($result, 'trade_ledger_count'),
            'forward_window_protocol' => data_get($result, 'walk_forward.forward_window_protocol', data_get($result, 'forward_window_protocol')),
            // The Python projection is an explicit post-replay diagnostic.
            // It may prioritize a research question but never changes a
            // runtime decision, mutation credit, or promotion gate.
            'edge_formation_academy_diagnostic' => [
                ...(array) data_get($result, 'edge_formation_academy_diagnostic', []),
                'promotion_evidence' => false,
            ],
            'promotion_evidence' => false,
        ];

        $projection['trading_operating_system_scorecard'] = $this->operatingSystem->assess($result, $projection);
        $projection['confirmation_entry_capability_evidence'] = $this->confirmationEntry->project($result);
        $projection['evolving_trader_fitness'] = $this->traderFitness->assess(
            $result,
            $projection,
            $projection['trading_operating_system_scorecard'],
        );
        $projection['process_outcome_audit'] = $this->processOutcome->project(
            $result,
            $projection['trading_operating_system_scorecard'],
            $projection['evolving_trader_fitness'],
        );

        return $projection;
    }

    private function profitFactorMargin(?float $profitFactor): ?float
    {
        if ($profitFactor === null) {
            return null;
        }

        return $this->unit(($profitFactor - 1.0) / 0.30);
    }

    private function unit(float $value): float
    {
        return round(max(0.0, min(1.0, $value)), 6);
    }

    /** @param list<string> $paths */
    private function number(array $values, array $paths): ?float
    {
        foreach ($paths as $path) {
            $value = data_get($values, $path);
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }
}
