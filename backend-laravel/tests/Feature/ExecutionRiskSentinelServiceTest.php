<?php

namespace Tests\Feature;

use App\Models\ModelMarketPerformance;
use App\Services\ExecutionRiskSentinelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExecutionRiskSentinelServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sentinel_uses_capped_fractional_sizing_and_never_martingale(): void
    {
        $candidate = new ModelMarketPerformance(['symbol' => 'XAUUSD', 'timeframe' => 'M15', 'metrics' => ['risk_of_ruin_percent' => 2]]);
        $plan = app(ExecutionRiskSentinelService::class)->assess($candidate, [
            'price' => 100, 'confidence' => 80, 'volatility_regime' => 'normal_volatility', 'spread_atr_ratio' => .10,
        ], ['entry_price' => 100, 'stop_loss' => 98, 'take_profit' => 104, 'execution_contract' => ['parameters' => ['max_leverage' => 5]], 'tactical_contract' => ['sizing' => 'volatility_scaled_fractional']]);

        $this->assertTrue($plan['approved']);
        $this->assertLessThanOrEqual(.75, $plan['risk_budget_percent']);
        $this->assertSame('forbidden', $plan['guards']['martingale']);
        $this->assertSame('forbidden', $plan['guards']['full_kelly']);
        $this->assertGreaterThan(0, $plan['position_size_multiple']);
    }

    public function test_sentinel_has_final_veto_for_risk_of_ruin(): void
    {
        $candidate = new ModelMarketPerformance(['symbol' => 'XAUUSD', 'timeframe' => 'M15', 'metrics' => ['risk_of_ruin_percent' => 11]]);
        $plan = app(ExecutionRiskSentinelService::class)->assess($candidate, ['price' => 100, 'confidence' => 95], ['entry_price' => 100, 'stop_loss' => 99, 'take_profit' => 102]);

        $this->assertFalse($plan['approved']);
        $this->assertSame('RISK_OF_RUIN_LIMIT', $plan['reason_code']);
    }
}
