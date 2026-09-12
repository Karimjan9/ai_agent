<?php

namespace Tests\Unit;

use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Services\SpecialistPassportService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SpecialistPassportSessionTest extends TestCase
{
    #[Test]
    public function london_specialist_requires_two_profitable_dst_offset_states(): void
    {
        $candidate = $this->candidate([
            'trend_up|normal_volatility|london|BUY|+0000' => ['trades' => 5, 'net_pf' => 1.20],
            'trend_up|normal_volatility|london|BUY|+0100' => ['trades' => 5, 'net_pf' => 1.25],
        ]);

        $passport = app(SpecialistPassportService::class)->build($candidate);

        $this->assertSame('passed', $passport['status']);
        $this->assertTrue($passport['checks']['dst_offset_coverage']);
        $this->assertSame(2, $passport['dst_offset_evidence']['qualified_offset_state_count']);
    }

    #[Test]
    public function one_offset_or_a_harmful_offset_cannot_certify_a_london_specialist(): void
    {
        $candidate = $this->candidate([
            'trend_up|normal_volatility|london|BUY|+0000' => ['trades' => 5, 'net_pf' => 1.20],
            'trend_up|normal_volatility|london|BUY|+0100' => ['trades' => 5, 'net_pf' => 0.80],
        ]);

        $passport = app(SpecialistPassportService::class)->build($candidate);

        $this->assertSame('failed', $passport['status']);
        $this->assertFalse($passport['checks']['dst_offset_coverage']);
        $this->assertContains('FAILED_SPECIALIST_DST_OFFSET_COVERAGE', $passport['reason_codes']);
    }

    /** @param array<string, array<string, int|float>> $dst */
    private function candidate(array $dst): ModelMarketPerformance
    {
        $model = new ModelVersion([
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
        $candidate = new ModelMarketPerformance([
            'model_version_id' => 7,
            'symbol' => 'XAUUSD',
            'timeframe' => 'MTF',
            'status' => 'forward_validated',
            'evidence_status' => 'valid',
            'sample_count' => 30,
            'metrics' => [
                'elite_agent_passport' => ['status' => 'passed'],
                'no_regression_contract' => ['status' => 'passed'],
                'pf_attribution' => ['breakdown' => ['by_regime_volatility_session' => [
                    'trend_up|normal_volatility' => [
                        'london' => ['trades' => 10, 'net_pf' => 1.4],
                    ],
                ]]],
                'robustness_matrix' => ['dst_envelopes' => $dst],
            ],
        ]);
        $candidate->setRelation('modelVersion', $model);

        return $candidate;
    }
}
