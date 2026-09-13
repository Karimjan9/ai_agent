<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\CandidateGateDecision;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Services\ContextualSpecialistEvidenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContextualSpecialistEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_persisted_pair_replays_materialize_confirmed_local_authority(): void
    {
        [$candidate, $control] = $this->pair();

        $evidence = app(ContextualSpecialistEvidenceService::class)->forCandidate($candidate);

        $this->assertTrue($evidence['exact_frozen_control']);
        $this->assertTrue($evidence['same_opportunity_calendar']);
        $this->assertTrue($evidence['frozen_control_superiority']);
        $this->assertSame(['winter', 'summer'], $evidence['qualified_dst_offset_states']);
        $this->assertSame('contextually_confirmed_specialist', data_get($evidence, 'assessment.status'));
        $this->assertFalse($evidence['local_evidence_grants_global_inheritance']);

        $metrics = $control->metrics;
        data_set($metrics, 'market_session_calendar_coverage.opportunity_calendar_hash', hash('sha256', 'different'));
        $control->update(['metrics' => $metrics]);
        $rejected = app(ContextualSpecialistEvidenceService::class)->forCandidate($candidate);
        $this->assertFalse($rejected['same_opportunity_calendar']);
        $this->assertSame('research_specialist_only', data_get($rejected, 'assessment.status'));
    }

    /** @return array{ModelMarketPerformance, ModelMarketPerformance} */
    private function pair(): array
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD', 'name' => 'calendar evidence', 'timeframe' => 'H1',
            'strategy_families' => ['hybrid'], 'is_active' => true,
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'test',
            'population_size' => 2, 'status' => 'completed',
        ]);
        $pairKey = hash('sha256', 'local-session-pair');
        $controlModel = ModelVersion::create([
            'name' => 'local-control', 'strategy' => 'hybrid', 'version' => 'v1-control',
            'generation' => 1, 'status' => 'testing', 'parameters' => ['minimum_confidence' => 1.0],
            'metadata' => ['control_pair_contract' => ['pair_key' => $pairKey, 'role' => 'control']],
            'evidence_status' => 'valid',
        ]);
        $identity = [
            'regime' => 'trend_up', 'venue_phase' => 'london_interfix', 'session' => 'london',
            'session_instance_id' => 'allocation-instance', 'volatility' => 'normal_volatility',
            'spread_liquidity' => 'liquid', 'transition_state' => 'stable', 'direction' => 'BUY',
            'trait' => 'minimum_confidence', 'instrument_bundle' => ['cost_aware_exit'],
            'strategy' => 'hybrid', 'tactic' => 'breakout_retest', 'risk' => 'atr_risk_envelope',
            'management' => 'cost_aware_exit', 'calendar_version' => 'test-calendar-v2',
        ];
        $candidateModel = ModelVersion::create([
            'name' => 'local-candidate', 'strategy' => 'hybrid', 'version' => 'v1-candidate',
            'generation' => 1, 'status' => 'testing', 'parameters' => ['minimum_confidence' => 1.1],
            'metadata' => [
                'control_pair_contract' => ['pair_key' => $pairKey, 'role' => 'candidate'],
                'contextual_specialist_identity' => [
                    'protocol' => 'contextual_specialist_identity_v1', 'status' => 'sealed',
                    'identity' => $identity, 'identity_hash' => hash('sha256', json_encode($identity)),
                ],
            ],
            'evidence_status' => 'valid',
        ]);
        $controlAgent = LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $controlModel->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'origin' => 'frozen_control', 'lifecycle_status' => 'rejected', 'parameter_diff' => [],
        ]);
        $candidateAgent = LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $candidateModel->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'origin' => 'mutation', 'lifecycle_status' => 'forward_validated',
            'parameter_diff' => ['minimum_confidence' => ['old' => 1.0, 'new' => 1.1]],
        ]);
        $coverage = [
            'protocol' => 'market_session_calendar_coverage_v1',
            'calendar_version' => 'test-calendar-v2', 'classification_coverage' => 1.0,
            'unknown_count' => 0, 'opportunity_calendar_hash' => hash('sha256', 'same-candles'),
            'target_session_instance_ids' => ['london_interfix|2026-01-05|+0000', 'london_interfix|2026-07-06|+0100'],
            'outside_scope_activation_count' => 0,
        ];
        $windows = [
            'independence_verified' => true, 'overlap_detected' => false,
            'windows' => [
                ['id' => 'w1', 'net_profit_percent' => 1.2, 'trades' => 8],
                ['id' => 'w2', 'net_profit_percent' => 1.5, 'trades' => 8],
            ],
        ];
        $controlWindows = $windows;
        $controlWindows['windows'][0]['net_profit_percent'] = .2;
        $controlWindows['windows'][1]['net_profit_percent'] = .4;
        $controlMetrics = [
            'net_profit_percent' => .6, 'market_session_calendar_coverage' => $coverage,
            'forward_window_protocol' => $controlWindows,
        ];
        $candidateMetrics = [
            'net_profit_percent' => 2.7, 'market_session_calendar_coverage' => $coverage,
            'forward_window_protocol' => $windows, 'paired_replay' => ['status' => 'confirmed'],
            'pf_attribution' => [
                'stress_cost' => ['profit_factor' => 1.12],
                'by_venue_phase' => ['london_interfix' => [
                    'trades' => 16, 'net_pf' => 1.4, 'net_profit_percent' => 2.7,
                ]],
            ],
            'selection_validation' => [
                'protocol' => 'purged_embargoed_cscv_v1', 'status' => 'assessed',
                'purge_embargo_applied' => true, 'probability_of_backtest_overfitting' => .2,
            ],
            'statistical_evidence' => [
                'edge_quality' => ['bootstrap_pf' => ['pf_5_percentile_lower_bound' => 1.12]],
                'deflated_sharpe' => ['status' => 'assessed', 'deflated_sharpe_probability' => .97],
            ],
            'no_regression_contract' => ['status' => 'passed'],
            'robustness_matrix' => ['venue_phase_envelopes' => [
                'trend_up|normal_volatility|london_interfix|BUY|winter' => ['trades' => 4, 'net_pf' => 1.2, 'net_profit_percent' => .8],
                'trend_up|normal_volatility|london_interfix|BUY|summer' => ['trades' => 4, 'net_pf' => 1.3, 'net_profit_percent' => .9],
            ]],
        ];
        $controlPerformance = ModelMarketPerformance::create([
            'model_version_id' => $controlModel->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'hybrid', 'status' => 'rejected', 'evidence_status' => 'valid',
            'sample_count' => 16, 'metrics' => $controlMetrics,
        ]);
        $candidatePerformance = ModelMarketPerformance::create([
            'model_version_id' => $candidateModel->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'hybrid', 'status' => 'forward_validated', 'evidence_status' => 'valid',
            'sample_count' => 16, 'metrics' => $candidateMetrics,
        ]);
        CandidateGateDecision::create([
            'lab_agent_id' => $candidateAgent->id, 'stage' => 'screening', 'decision' => 'passed',
            'reason_codes' => [], 'metrics' => [], 'evaluated_at' => now(),
        ]);
        CandidateGateDecision::create([
            'model_market_performance_id' => $candidatePerformance->id,
            'lab_agent_id' => $candidateAgent->id, 'stage' => 'statistical_forward_gate',
            'decision' => 'passed', 'reason_codes' => [], 'metrics' => [], 'evaluated_at' => now(),
        ]);

        return [$candidatePerformance->fresh('modelVersion'), $controlPerformance->fresh()];
    }
}
