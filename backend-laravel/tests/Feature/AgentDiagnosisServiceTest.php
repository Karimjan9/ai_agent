<?php

namespace Tests\Feature;

use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Services\AgentDiagnosisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentDiagnosisServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_signal_starvation_becomes_targeted_mutation_advice(): void
    {
        $model = ModelVersion::create([
            'name' => 'trend', 'strategy' => 'xauusd_trend_g1_a01', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing', 'parameters' => [], 'metadata' => [],
        ]);
        $performance = ModelMarketPerformance::create([
            'model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'trend', 'status' => 'challenger', 'rolling_windows_count' => 4,
        ]);

        $diagnosis = app(AgentDiagnosisService::class)->diagnose($performance, [
            'total_trades' => 7, 'profit_factor' => 2.0, 'max_drawdown_percent' => 2.0,
            'monte_carlo' => ['risk_of_ruin_percent' => 0.0], 'top_mistakes' => [],
            'entry_funnel' => ['flat_signal_opportunities' => 9, 'accepted_entries' => 7, 'dominant_rejection' => null],
        ]);

        $this->assertSame('signal_starvation', $diagnosis->primary_failure);
        $this->assertContains('trend_strength_min', $diagnosis->recommended_mutations);
        $this->assertSame(9, $diagnosis->evidence['flatSignals']);
        $this->assertSame(23, $diagnosis->evidence['deficits']['trade_deficit']);
        $this->assertSame(3, $diagnosis->evidence['deficits']['rolling_deficit']);
        $this->assertSame(0, $diagnosis->evidence['deficits']['pf_deficit']);
    }
}
