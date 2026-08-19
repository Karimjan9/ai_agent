<?php

namespace Tests\Feature;

use App\Models\DualTrackOutcome;
use App\Models\DualTrackCellPolicy;
use App\Services\CapabilityCellRouterService;
use App\Services\ChampionCouncilCanaryRouterService;
use App\Services\DualTrackCellPolicyService;
use App\Services\DualTrackEvaluatorCalibrationService;
use App\Services\DualTrackEvolutionService;
use App\Services\DualTrackMemoryService;
use App\Services\DualTrackRiskShieldService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DualTrackEvolutionContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_cell_policy_learns_a_conservative_recommendation_from_settled_outcomes(): void
    {
        $base = [
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'task_type' => 'paper_signal',
            'cell_key' => 'XAUUSD|H1|trend_up|normal|paper_signal',
            'outcome_status' => 'settled', 'promotion_evidence' => false,
        ];
        for ($i = 0; $i < 30; $i++) {
            DualTrackOutcome::create([...$base, 'outcome_key' => 'champion-'.$i, 'lane' => 'champion', 'decision' => 'BUY', 'actual_outcome' => 'win', 'reward' => 1, 'correct' => true, 'risk_percent' => .5]);
            DualTrackOutcome::create([...$base, 'outcome_key' => 'council-'.$i, 'lane' => 'council', 'decision' => 'BUY', 'actual_outcome' => 'loss', 'reward' => -1, 'correct' => false, 'risk_percent' => .5]);
        }

        $latest = DualTrackOutcome::query()->where('lane', 'champion')->latest('id')->firstOrFail();
        $result = app(DualTrackCellPolicyService::class)->update($latest);

        $this->assertSame('certified', $result['status']);
        $this->assertSame('champion', $result['recommended_lane']);
        $this->assertFalse($result['promotion_evidence']);
    }

    public function test_active_router_cannot_use_a_certified_cell_without_explicit_activation(): void
    {
        DualTrackCellPolicy::create([
            'policy_key' => 'cell:XAUUSD|H1|trend_up|normal|signal',
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'cell_key' => 'XAUUSD|H1|trend_up|normal|signal',
            'mode' => 'active', 'recommended_lane' => 'council', 'active_lane' => 'incumbent',
            'status' => 'certified', 'sample_count' => 60, 'minimum_samples' => 30,
            'confidence_margin' => 10, 'disagreement_value' => 0,
            'lane_statistics' => [], 'risk_bounds' => [], 'policy' => [],
            'policy_hash' => hash('sha256', 'test'), 'promotion_evidence' => false,
        ]);
        $previous = config('services.dual_track');
        config(['services.dual_track.mode' => 'active', 'services.dual_track.activate_certified_cells' => false]);
        try {
            $result = app(CapabilityCellRouterService::class)->decide([
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'market_regime' => 'trend_up', 'volatility_regime' => 'normal',
            ]);
        } finally {
            config(['services.dual_track' => $previous]);
        }

        $this->assertSame('incumbent', $result['route']);
        $this->assertSame('incumbent', $result['policy']['active_lane']);
    }

    public function test_risk_shield_blocks_active_lane_without_calibrated_confidence(): void
    {
        $previous = config('services.dual_track');
        config(['services.dual_track.mode' => 'active', 'services.dual_track.require_calibration_for_active' => true]);
        try {
            $result = app(DualTrackRiskShieldService::class)->assess(
                ['market_regime' => 'trend_up'],
                ['decision' => 'BUY', 'confidence' => .9],
                ['decision' => 'BUY', 'confidence' => .9],
                ['constitution_integrity' => true, 'snapshot_integrity' => true],
            );
        } finally {
            config(['services.dual_track' => $previous]);
        }

        $this->assertFalse($result['allowed']);
        $this->assertContains('calibration', $result['failed_checks']);
        $this->assertSame('WAIT', $result['decision']);
    }

    public function test_evaluator_calibration_and_layered_memory_are_not_promotion_shortcuts(): void
    {
        $calibration = app(DualTrackEvaluatorCalibrationService::class);
        for ($i = 0; $i < 20; $i++) {
            $result = $calibration->record('judge-a', 'EURUSD|M15|range|low|paper_signal', .8, true);
        }
        $this->assertSame('calibrated', $result['status']);
        $this->assertTrue(app(DualTrackEvaluatorCalibrationService::class)->trust('judge-a', 'EURUSD|M15|range|low|paper_signal')['trusted']);

        $outcome = DualTrackOutcome::create([
            'outcome_key' => 'memory-1', 'symbol' => 'EURUSD', 'timeframe' => 'M15',
            'task_type' => 'paper_signal', 'cell_key' => 'EURUSD|M15|range|low|paper_signal',
            'lane' => 'champion', 'decision' => 'BUY', 'outcome_status' => 'settled',
            'actual_outcome' => 'loss', 'reward' => -1, 'correct' => false, 'promotion_evidence' => false,
        ]);
        $memory = app(DualTrackMemoryService::class)->settle($outcome);
        $evolution = app(DualTrackEvolutionService::class)->recordOutcome($outcome);

        $this->assertSame('failure', $memory['layer']);
        $this->assertSame('research', $evolution['status']);
        $this->assertFalse($memory['promotion_evidence']);
        $this->assertFalse($evolution['promotion_evidence']);
    }
}
