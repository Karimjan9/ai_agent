<?php

namespace App\Services;

use App\Models\CandidateGateDecision;
use App\Models\LabAgent;
use App\Models\ModelMarketPerformance;
use App\Models\PaperConfidenceCalibration;

class CandidateGateDecisionService
{
    public function __construct(private PaperEvidenceReadinessService $paperEvidence) {}

    public function recordScreening(LabAgent $agent, array $result): CandidateGateDecision
    {
        $reasons = $this->economicReasons($result, 10, 1.0, 100.0, 100.0, 0);
        $decision = $reasons === [] ? 'passed' : 'failed';
        $decisionRow = $this->store(null, $agent, 'screening', $decision, $reasons, $result);

        // Screening failures do not disappear into a generic rejection. They
        // explicitly enter the directed-mutation queue for the next replay.
        $funnel = (array) data_get($result, 'entry_funnel', []);
        $hasDiagnosticSignal = (int) data_get($funnel, 'raw_strategy_signals', 0) > 0
            || (int) data_get($funnel, 'flat_signal_opportunities', 0) > 0;
        $generationRescues = CandidateGateDecision::where('stage', 'diagnostic_rescue_replay')
            ->whereHas('labAgent', fn ($query) => $query->where('lab_generation_id', $agent->lab_generation_id))->count();
        $familyRescues = CandidateGateDecision::where('stage', 'diagnostic_rescue_replay')
            ->whereHas('labAgent', fn ($query) => $query->where('lab_generation_id', $agent->lab_generation_id)->where('strategy_family', $agent->strategy_family))->count();
        // Maximum 4/20 population (20%) and two per family. This preserves a
        // diagnostic lane without allowing it to compete with promotion work.
        if ($reasons !== [] && $hasDiagnosticSignal && $generationRescues < 4 && $familyRescues < 2) {
            $this->store(null, $agent, 'diagnostic_rescue_replay', 'waiting', [...$reasons, 'WAITING_FOR_EVIDENCE'], [
                'recommended_mutation_target' => data_get($agent->modelVersion?->metadata, 'generation_target'),
                'screening_metrics' => $result,
                'diagnostic_telemetry' => data_get($result, 'diagnostic_telemetry', []),
                'promotion_evidence' => false,
            ]);
        }
        return $decisionRow;
    }

    public function recordForward(ModelMarketPerformance $performance, array $result): CandidateGateDecision
    {
        $reasons = $this->economicReasons($result, 30, 1.3, 15.0, 10.0, 3);
        if ((bool) data_get($result, 'is_overfit', false)) $reasons[] = 'FAILED_OVERFIT';
        if (data_get($result, 'pf_attribution.method') === 'identical_replay_execution_profiles'
            && (float) data_get($result, 'pf_attribution.stress_cost.profit_factor', 0) < 1.05) $reasons[] = 'FAILED_STRESS_COST';
        $edge = data_get($result, 'statistical_evidence.edge_quality', []);
        if (data_get($edge, 'worst_regime_sampled', false) && (float) data_get($edge, 'worst_regime_pf', 0) < 1.0) $reasons[] = 'FAILED_REGIME_COVERAGE';
        $survival = data_get($result, 'window_survival', []);
        if ((int) data_get($survival, 'positive_windows', 0) > 0
            && ((int) data_get($survival, 'positive_windows', 0) < 3 || (int) data_get($survival, 'catastrophic_windows', 0) > 0)) $reasons[] = 'FAILED_REGIME_COVERAGE';
        if (data_get($result, 'monthly_passport.status') === 'seasonal_or_luck') $reasons[] = 'FAILED_REGIME_COVERAGE';
        if (data_get($result, 'selection_validation.status') === 'assessed'
            && (float) data_get($result, 'selection_validation.probability_of_backtest_overfitting', 1) > .5) $reasons[] = 'FAILED_OVERFIT';
        if (data_get($result, 'statistical_evidence.deflated_sharpe.status') === 'assessed'
            && (float) data_get($result, 'statistical_evidence.deflated_sharpe.deflated_sharpe_probability', 0) < .95) $reasons[] = 'FAILED_OVERFIT';
        return $this->store($performance, null, 'statistical_forward_gate', $reasons === [] ? 'passed' : 'failed', array_values(array_unique($reasons)), $result);
    }

    public function recordDiagnosticReplay(LabAgent $agent, array $result): CandidateGateDecision
    {
        $reasons = $this->economicReasons($result, 10, 1.0, 100.0, 100.0, 0);
        return $this->store(null, $agent, 'diagnostic_rescue_replay', 'failed', $reasons, [
            'diagnostic_telemetry' => data_get($result, 'diagnostic_telemetry', []),
            'entry_funnel' => data_get($result, 'entry_funnel', []),
            'gate_deficits' => app(ForwardGateProgressService::class)->deficits($result),
            'promotion_evidence' => false,
        ]);
    }

    public function recordPaper(ModelMarketPerformance $performance, array $metrics): CandidateGateDecision
    {
        $minimum = max(50, (int) config('services.promotion.paper_min_samples', 50));
        $reasons = [];
        if ((int) data_get($metrics, 'sample_count', 0) < $minimum) $reasons[] = 'WAITING_FOR_SAMPLE';
        if ((int) data_get($metrics, 'sample_count', 0) >= $minimum) {
            if ((float) data_get($metrics, 'profit_factor', 0) < 1.3) $reasons[] = 'FAILED_PROFIT_FACTOR';
            if ((float) data_get($metrics, 'max_drawdown', 100) > 15) $reasons[] = 'FAILED_DRAWDOWN';
        }
        $calibration = PaperConfidenceCalibration::query()->where('model_market_performance_id', $performance->id)->orderByDesc('sample_count')->first();
        if (! $calibration || $calibration->sample_count < (int) config('services.paper_calibration.minimum_samples', 20)) $reasons[] = 'FAILED_CALIBRATION';
        $readiness = $this->paperEvidence->inspect();
        if (! data_get($readiness, 'gates.feed_uptime', false)) $reasons[] = 'FAILED_FEED_UPTIME';
        $decision = in_array('WAITING_FOR_SAMPLE', $reasons, true) ? 'waiting' : ($reasons === [] ? 'passed' : 'failed');
        return $this->store($performance, null, 'paper_observation', $decision, $reasons, [...$metrics, 'global_paper_readiness' => $readiness]);
    }

    /** Operational trace only: this never participates in promotion decisions. */
    public function recordPaperCapture(ModelMarketPerformance $performance, string $reason, array $metrics = []): CandidateGateDecision
    {
        return $this->store($performance, null, 'paper_signal_capture', 'waiting', [$reason, 'WAITING_FOR_SAMPLE'], [
            ...$metrics,
            'promotion_evidence' => false,
            'observability_only' => true,
        ]);
    }

    public function recordHoldout(ModelMarketPerformance $performance, array $holdout): CandidateGateDecision
    {
        $result = (array) data_get($holdout, 'result', []);
        $reasons = $this->economicReasons($result, 30, 1.3, 15.0, 10.0, 0);
        if ((float) data_get($holdout, 'score', 0) < 50) $reasons[] = 'FAILED_FORWARD_SCORE';
        return $this->store($performance, null, 'sealed_holdout', $reasons === [] ? 'passed' : 'failed', array_values(array_unique($reasons)), $holdout);
    }

    private function economicReasons(array $metrics, int $minimumTrades, float $minimumPf, float $maxDrawdown, float $maxRuin, int $minimumRollingWins): array
    {
        $reasons = [];
        if ((int) data_get($metrics, 'total_trades', data_get($metrics, 'sample_count', 0)) < $minimumTrades) $reasons[] = 'FAILED_TRADE_COUNT';
        if ((float) data_get($metrics, 'profit_factor', 0) < $minimumPf) $reasons[] = 'FAILED_PROFIT_FACTOR';
        if ((float) data_get($metrics, 'max_drawdown_percent', data_get($metrics, 'max_drawdown', 100)) > $maxDrawdown) $reasons[] = 'FAILED_DRAWDOWN';
        if ((float) data_get($metrics, 'monte_carlo.risk_of_ruin_percent', 0) > $maxRuin) $reasons[] = 'FAILED_RUIN_RISK';
        if ($minimumRollingWins > 0 && (int) data_get($metrics, 'rolling_forward_wins', 0) < $minimumRollingWins) $reasons[] = 'FAILED_REGIME_COVERAGE';
        return $reasons;
    }

    private function store(?ModelMarketPerformance $performance, ?LabAgent $agent, string $stage, string $decision, array $reasons, array $metrics): CandidateGateDecision
    {
        return CandidateGateDecision::updateOrCreate(
            ['model_market_performance_id' => $performance?->id, 'lab_agent_id' => $agent?->id, 'stage' => $stage],
            ['decision' => $decision, 'reason_codes' => array_values(array_unique($reasons)), 'metrics' => $metrics, 'evaluated_at' => now()],
        );
    }
}
