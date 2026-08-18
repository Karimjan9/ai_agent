<?php

namespace App\Services;

use App\Models\AgentFailureCase;
use App\Models\FailureCaseRun;
use App\Models\ModelMarketPerformance;

/** Persistent regression curriculum; an ordinary replay cannot silently mark a case fixed. */
class FailureCurriculumService
{
    public function evaluate(ModelMarketPerformance $performance, array $result): array
    {
        $cases = AgentFailureCase::query()
            ->where('symbol', $performance->symbol)
            ->where('timeframe', $performance->timeframe)
            ->where('regression_status', 'open')
            ->get();
        $runs = [];

        foreach ($cases as $case) {
            $observed = $this->observedCaseResult($case->failure_type, $result);
            $variant = (array) data_get($result, "failure_curriculum_variants.{$case->failure_type}", []);
            $variantStatus = (string) data_get($variant, 'status', 'missing');
            $status = match (true) {
                $observed === null => 'not_assessed',
                $observed === false => 'failed',
                $variantStatus === 'passed' => 'passed',
                default => 'waiting_hidden_variant',
            };
            $penalty = $case->severity === 'P1_QUALITY' && $status === 'failed' ? 10 : 0;

            FailureCaseRun::updateOrCreate(
                ['agent_failure_case_id' => $case->id, 'model_market_performance_id' => $performance->id],
                [
                    'status' => $status,
                    'score_penalty' => $penalty,
                    'evaluated_at' => now(),
                    'evidence' => [
                        'failure_type' => $case->failure_type,
                        'expected_action' => $case->expected_action,
                        'observed_safe_behavior' => $observed,
                        'hidden_variant' => $variant ?: ['status' => 'missing', 'required' => true],
                        'promotion_evidence' => false,
                        'rule' => 'A visible replay can discover or reproduce a failure; only a frozen unseen variant may certify that it is fixed.',
                    ],
                ],
            );
            $runs[] = ['failure_case_id' => $case->id, 'severity' => $case->severity, 'status' => $status, 'penalty' => $penalty];
        }

        $p0 = collect($runs)->where('severity', 'P0_SAFETY');
        return [
            'protocol' => 'failure_curriculum_v2',
            'runs' => $runs,
            'p0_safety_passed' => $p0->isEmpty() || $p0->every(fn (array $run): bool => $run['status'] === 'passed'),
            'open_case_count' => $cases->count(),
            'quality_penalty' => collect($runs)->sum('penalty'),
            'rule' => 'P0 failures require a passed hidden regression variant; P1/P2 failures remain measurable quality signals and never lower promotion gates.',
        ];
    }

    private function observedCaseResult(string $failureType, array $result): ?bool
    {
        if (str_starts_with($failureType, 'wound_')) {
            $row = collect((array) data_get($result, 'wound_set.cases', []))
                ->first(fn (array $case): bool => (string) data_get($case, 'failure_type') === $failureType);

            return match ((string) data_get($row, 'status', 'not_assessed')) {
                'improved' => true,
                'failed' => false,
                default => null,
            };
        }

        return match ($failureType) {
            'cost_fragility' => (float) data_get($result, 'pf_attribution.stress_cost.profit_factor', 0) >= 1.05,
            'transition_failure' => (float) data_get($result, 'transition_homework.score', 0) >= 50,
            'edge_pf_signal_quality' => (float) data_get($result, 'profit_factor', 0) >= 1.30,
            'trade_viability_signal_frequency' => (int) data_get($result, 'total_trades', 0) >= 30,
            'regime_coverage_quality' => (float) data_get($result, 'regime_coverage.coverage_ratio', data_get($result, 'regime_coverage.score', 0)) >= 0.70,
            // Named regressions are unit tests, never calendar features.
            // They make a trend-up/range/transition repair prove that it did
            // not merely move aggregate PF while leaving its actual failure
            // signature unresolved.
            'g101_trend_up_failure' => (float) data_get($result, 'statistical_evidence.regime_profit_factor.trend_up', 0) >= 1.0,
            'range_opportunity_shortage' => $this->rangeOpportunityObserved($result),
            'april_like_volatility_session_failure' => (float) data_get($result, 'pf_attribution.stress_cost.profit_factor', 0) >= 1.05,
            'high_spread_failure' => (float) data_get($result, 'pf_attribution.stress_cost.profit_factor', 0) >= 1.05,
            'delayed_entry_failure' => data_get($result, 'counterfactual_branches.delayed_entry.status') === 'assessed',
            'overfit_structure' => ! (bool) data_get($result, 'passport.overfit', data_get($result, 'overfit', false)),
            default => null,
        };
    }

    private function rangeOpportunityObserved(array $result): ?bool
    {
        $row = data_get($result, 'opportunity_recall.by_regime.range');
        if (! is_array($row) || ! array_key_exists('opportunities', $row) || ! array_key_exists('recall', $row)) return null;
        return (int) $row['opportunities'] >= 3 && (float) $row['recall'] >= .60;
    }
}
