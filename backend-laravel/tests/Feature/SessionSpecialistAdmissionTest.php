<?php

namespace Tests\Feature;

use App\Models\CandidateGateDecision;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Services\EliteAgentPortfolioGateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionSpecialistAdmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_portfolio_admission_rejects_a_session_specialist_without_cross_dst_evidence(): void
    {
        $weak = $this->candidate([
            'trend_up|normal_volatility|london|BUY|+0000' => ['trades' => 5, 'net_pf' => 1.20],
            'trend_up|normal_volatility|london|BUY|+0100' => ['trades' => 5, 'net_pf' => 0.80],
        ]);
        $strong = $this->candidate([
            'trend_up|normal_volatility|london|BUY|+0000' => ['trades' => 5, 'net_pf' => 1.20],
            'trend_up|normal_volatility|london|BUY|+0100' => ['trades' => 5, 'net_pf' => 1.25],
        ]);

        $eligible = app(EliteAgentPortfolioGateService::class)
            ->eligibleResearchMembers(collect([$weak, $strong]));

        $this->assertFalse($eligible->contains('id', $weak->id));
        $this->assertTrue($eligible->contains('id', $strong->id));
    }

    /** @param array<string, array<string, int|float>> $dst */
    private function candidate(array $dst): ModelMarketPerformance
    {
        $model = ModelVersion::create([
            'name' => 'session-specialist-'.uniqid(),
            'strategy' => 'trend_pullback',
            'version' => 'v1',
            'generation' => 1,
            'status' => 'testing',
            'parameters' => [],
            'metadata' => [
                'council_specialist_contract' => [
                    'protocol' => 'agent_council_v1',
                    'role' => 'trend_up_specialist',
                    'owner_regime' => 'trend_up',
                ],
                'portfolio_research_contract' => [
                    'protocol' => 'portfolio_member_research_v1',
                    'target_regime' => 'trend_up',
                    'target_volatility' => 'normal_volatility',
                    'target_direction' => 'BUY',
                    'target_session' => 'london',
                ],
            ],
            'evidence_status' => 'valid',
        ]);
        $performance = ModelMarketPerformance::create([
            'model_version_id' => $model->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'trend',
            'status' => 'forward_validated',
            'evidence_status' => 'valid',
            'sample_count' => 30,
            'metrics' => [
                'profit_factor' => 1.40,
                'elite_agent_passport' => ['status' => 'passed'],
                'no_regression_contract' => ['status' => 'passed'],
                'monte_carlo' => ['risk_of_ruin_percent' => 2],
                'pf_attribution' => [
                    'stress_cost' => ['profit_factor' => 1.10],
                    'breakdown' => ['by_regime_volatility_session' => [
                        'trend_up|normal_volatility' => [
                            'london' => ['trades' => 10, 'net_pf' => 1.40],
                        ],
                    ]],
                ],
                'robustness_matrix' => ['dst_envelopes' => $dst],
            ],
        ]);
        CandidateGateDecision::create([
            'model_market_performance_id' => $performance->id,
            'stage' => 'statistical_forward_gate',
            'decision' => 'passed',
            'reason_codes' => [],
            'metrics' => ['elite_agent_passport' => ['status' => 'passed']],
            'evaluated_at' => now(),
        ]);

        return $performance->fresh('modelVersion');
    }
}
