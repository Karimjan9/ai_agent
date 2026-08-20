<?php

namespace Tests\Feature;

use App\Models\DualTrackRun;
use App\Services\DualTrackOrchestratorService;
use App\Services\DualTrackMonitorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DualTrackLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_signal_observation_persists_both_lanes_and_keeps_shadow_incumbent(): void
    {
        config(['services.dual_track.mode' => 'shadow']);

        $result = app(DualTrackOrchestratorService::class)->observeSignal(
            [
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'task_type' => 'paper_signal',
                'market_regime' => 'trend_up', 'volatility_regime' => 'normal',
                'event_key' => 'candidate-1|candle-1',
            ],
            ['decision' => 'BUY', 'confidence' => .8, 'source' => 'champion'],
            ['decision' => 'BUY', 'confidence' => .7, 'source' => 'council'],
            ['incumbent_decision' => 'WAIT', 'constitution_integrity' => true, 'snapshot_integrity' => true],
        );

        $this->assertSame('BUY', $result['selected_decision']);
        $this->assertSame('incumbent', $result['routing']['route']);
        $this->assertTrue($result['routing']['observation_only']);
        $this->assertFalse($result['promotion_evidence']);
        $this->assertDatabaseHas('dual_track_runs', [
            'run_key' => $result['run_key'],
            'symbol' => 'XAUUSD',
            'champion_decision' => 'BUY',
            'council_decision' => 'BUY',
            'selected_lane' => 'hybrid',
            'promotion_evidence' => 0,
        ]);
        $this->assertSame(1, DualTrackRun::query()->count());
    }

    public function test_same_event_is_idempotent(): void
    {
        $service = app(DualTrackOrchestratorService::class);
        $context = ['symbol' => 'EURUSD', 'timeframe' => 'M15', 'event_key' => 'same-event'];

        $first = $service->observeSignal($context, ['decision' => 'WAIT'], ['decision' => 'WAIT']);
        $second = $service->observeSignal($context, ['decision' => 'WAIT'], ['decision' => 'WAIT']);

        $this->assertSame($first['run_key'], $second['run_key']);
        $this->assertSame(1, DualTrackRun::query()->count());
    }

    public function test_monitor_projects_cell_and_disagreement_statistics(): void
    {
        $service = app(DualTrackOrchestratorService::class);
        $service->observeSignal(
            ['symbol' => 'GBPUSD', 'timeframe' => 'H1', 'market_regime' => 'transition', 'volatility_regime' => 'high', 'event_key' => 'monitor-1'],
            ['decision' => 'BUY'],
            ['decision' => 'WAIT'],
        );

        $report = app(DualTrackMonitorService::class)->report('GBPUSD', 'H1');

        $this->assertTrue($report['available']);
        $this->assertSame(1, $report['sample_size']);
        $this->assertSame(1, $report['disagreements']);
        $this->assertCount(1, $report['cells']);
        $this->assertSame('execution_organism', $report['organisms']['champion']['profile']['identity']);
        $this->assertSame('reasoning_governance_organism', $report['organisms']['council']['profile']['identity']);
        $this->assertFalse($report['promotion_evidence']);
    }
}
