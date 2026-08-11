<?php

namespace Tests\Feature;

use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Services\CandidateGateDecisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CandidateGateDecisionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_forward_and_paper_stages_store_actionable_reason_codes(): void
    {
        $model = ModelVersion::create([
            'name' => 'gate-ledger', 'strategy' => 'gbpusd_breakout_g1_a01', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing', 'parameters' => [], 'metadata' => [], 'evidence_status' => 'valid',
        ]);
        $performance = ModelMarketPerformance::create([
            'model_version_id' => $model->id, 'symbol' => 'GBPUSD', 'timeframe' => 'H1',
            'strategy_family' => 'breakout', 'status' => 'challenger', 'evidence_status' => 'valid',
        ]);

        $service = app(CandidateGateDecisionService::class);
        $forward = $service->recordForward($performance, [
            'total_trades' => 8, 'profit_factor' => .8, 'max_drawdown_percent' => 22,
            'monte_carlo' => ['risk_of_ruin_percent' => 18], 'rolling_forward_wins' => 0,
            'pf_attribution' => ['method' => 'identical_replay_execution_profiles', 'stress_cost' => ['profit_factor' => .7]],
            'statistical_evidence' => ['edge_quality' => ['worst_regime_sampled' => true, 'worst_regime_pf' => .6]],
        ]);
        $paper = $service->recordPaper($performance, ['sample_count' => 4, 'profit_factor' => 2, 'max_drawdown' => 2]);

        $this->assertSame('failed', $forward->decision);
        $this->assertContains('FAILED_TRADE_COUNT', $forward->reason_codes);
        $this->assertContains('FRESH_REPLAY_EVIDENCE_MISSING', $forward->reason_codes);
        $this->assertContains('FAILED_STRESS_COST', $forward->reason_codes);
        $this->assertSame('waiting', $paper->decision);
        $this->assertContains('WAITING_FOR_SAMPLE', $paper->reason_codes);
        $this->assertContains('FAILED_CALIBRATION', $paper->reason_codes);
    }

    public function test_attached_parent_cannot_open_forward_gate_without_benefit_proof(): void
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD', 'name' => 'Gate parent lab', 'timeframe' => 'H1',
            'strategy_families' => ['trend'], 'is_active' => true,
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'test',
            'population_size' => 1, 'status' => 'completed',
        ]);
        $parent = ModelVersion::create([
            'name' => 'gate-parent', 'strategy' => 'xauusd_trend_parent', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing', 'parameters' => [], 'metadata' => [],
            'evidence_status' => 'valid',
        ]);
        $child = ModelVersion::create([
            'name' => 'gate-child', 'strategy' => 'xauusd_trend_child', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing', 'parameters' => [], 'metadata' => [],
            'evidence_status' => 'valid',
        ]);
        $agent = LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $child->id,
            'parent_a_model_version_id' => $parent->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'trend', 'origin' => 'adaptive_parent', 'lifecycle_status' => 'full_validation',
            'parameter_diff' => ['trend_ema_period' => ['old' => 50, 'new' => 55]],
        ]);
        $performance = ModelMarketPerformance::create([
            'model_version_id' => $child->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'trend', 'status' => 'challenger', 'evidence_status' => 'valid',
        ]);

        $decision = app(CandidateGateDecisionService::class)->recordForward($performance, [
            'evidence_run_id' => 'fresh-replay-test',
            'data_manifest' => ['sha256' => str_repeat('a', 64)],
            'full_replay_runtime_policy' => ['protocol' => 'full_replay_runtime_budget_v1'],
            'execution_contract' => [],
            'paired_replay' => ['status' => 'pending'],
            'no_regression_contract' => ['status' => 'baseline_unavailable'],
        ]);

        $this->assertSame('failed', $decision->decision);
        $this->assertContains('PARENT_BENEFIT_PAIRED_REPLAY_NOT_CONFIRMED', $decision->reason_codes);
        $this->assertContains('PARENT_BENEFIT_NO_REGRESSION_NOT_PASSED', $decision->reason_codes);
        $this->assertNotNull($agent->fresh());
    }

    public function test_sealed_portfolio_forward_handoff_is_attributed_before_paper(): void
    {
        $model = ModelVersion::create([
            'name' => 'portfolio-gate-ledger', 'strategy' => 'xauusd_portfolio_v1', 'version' => 'portfolio-v1',
            'generation' => 0, 'status' => 'testing', 'parameters' => [], 'metadata' => ['portfolio_proxy' => true],
            'evidence_status' => 'valid',
        ]);
        $performance = ModelMarketPerformance::create([
            'model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'portfolio', 'status' => 'forward_validated', 'evidence_status' => 'valid',
        ]);

        $decision = app(CandidateGateDecisionService::class)->recordPortfolioForward(
            $performance,
            ['evidence_run_id' => 'portfolio-forward-test'],
            [
                'protocol' => 'portfolio_elite_passport_v1',
                'status' => 'passed',
                'portfolio_id' => 7,
                'membership_hash' => str_repeat('a', 64),
                'parameter_hash' => str_repeat('b', 64),
                'final_exam_result_hash' => str_repeat('c', 64),
            ],
        );

        $this->assertSame('passed', $decision->decision);
        $this->assertSame('portfolio_sealed', $decision->attribution_status);
        $this->assertSame('portfolio_sealed', data_get($decision->metrics, 'portfolio_forward_identity.attribution_status'));
        $this->assertDatabaseHas('candidate_gate_decisions', [
            'model_market_performance_id' => $performance->id,
            'stage' => 'statistical_forward_gate',
            'decision' => 'passed',
            'attribution_status' => 'portfolio_sealed',
        ]);
    }

    public function test_insufficient_evidence_decision_fits_the_gate_projection(): void
    {
        $lab = AiLaboratory::create([
            'symbol' => 'EURUSD', 'name' => 'Insufficient evidence lab', 'timeframe' => 'M15',
            'strategy_families' => ['mean_reversion'], 'is_active' => true,
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'test',
            'population_size' => 1, 'status' => 'screening',
        ]);
        $model = ModelVersion::create([
            'name' => 'gate-insufficient-evidence', 'strategy' => 'eurusd_mean_reversion_g1_a01', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing', 'parameters' => [], 'metadata' => [], 'evidence_status' => 'valid',
        ]);
        $agent = LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'EURUSD', 'timeframe' => 'M15', 'strategy_family' => 'mean_reversion',
            'origin' => 'test', 'lifecycle_status' => 'screening', 'parameter_diff' => [],
        ]);

        $decision = app(CandidateGateDecisionService::class)->recordScreening($agent, [
            'total_trades' => 1,
            'profit_factor' => 0,
            'promotion_evidence' => false,
            'screening_survival' => ['status' => 'insufficient_evidence'],
        ]);

        $this->assertSame('insufficient_evidence', $decision->decision);
        $this->assertDatabaseHas('candidate_gate_decisions', [
            'lab_agent_id' => $agent->id,
            'stage' => 'screening',
            'decision' => 'insufficient_evidence',
        ]);
    }
}
