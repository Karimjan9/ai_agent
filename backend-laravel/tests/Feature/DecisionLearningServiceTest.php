<?php

namespace Tests\Feature;

use App\Models\AgentMemory;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Services\DecisionLearningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DecisionLearningServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_entry_and_stop_decisions_become_next_generation_advice(): void
    {
        $model = ModelVersion::create(['name' => 'trend', 'strategy' => 'xauusd_trend_g1_a01', 'version' => 'v1', 'generation' => 1, 'status' => 'testing', 'parameters' => [], 'metadata' => []]);
        $performance = ModelMarketPerformance::create(['model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'trend', 'status' => 'rejected', 'rolling_windows_count' => 4, 'metrics' => ['profit_factor' => .8]]);
        $result = [
            'total_trades' => 30, 'profit_factor' => .8,
            'regime_performance' => ['trend_up' => ['trades' => 12, 'profit_percent' => -4]],
            'top_mistakes' => [['type' => 'stop_loss_too_close', 'count' => 7]],
            'pf_attribution' => [
                'by_direction' => ['BUY' => ['trades' => 8, 'net_pf' => .7]],
                'by_exit_reason' => ['intrabar_stop' => ['trades' => 7, 'net_pf' => .4]],
            ],
        ];

        app(DecisionLearningService::class)->learn($performance, $result);
        $advice = app(DecisionLearningService::class)->advice('XAUUSD', 'H1', 'trend', 'market:trend_up');

        $this->assertSame(3, AgentMemory::count());
        $this->assertContains('trend_strength_min', $advice['prioritize']);
        $this->assertContains('atr_stop_multiplier', $advice['prioritize']);
        $this->assertContains('partial_take_profit_fraction', $advice['prioritize']);
    }
}
