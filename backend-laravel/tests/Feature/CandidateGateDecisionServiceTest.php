<?php

namespace Tests\Feature;

use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
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
        $this->assertContains('FAILED_STRESS_COST', $forward->reason_codes);
        $this->assertSame('waiting', $paper->decision);
        $this->assertContains('WAITING_FOR_SAMPLE', $paper->reason_codes);
        $this->assertContains('FAILED_CALIBRATION', $paper->reason_codes);
    }
}
