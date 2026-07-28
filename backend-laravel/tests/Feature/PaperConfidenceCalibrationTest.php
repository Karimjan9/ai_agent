<?php

namespace Tests\Feature;

use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Models\PaperConfidenceCalibration;
use App\Services\PaperConfidenceCalibrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaperConfidenceCalibrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unproven_paper_scope_preserves_raw_confidence(): void
    {
        $model = ModelVersion::create(['name' => 'candidate', 'strategy' => 'xauusd_trend_g1_a01', 'version' => 'v1', 'generation' => 1, 'status' => 'testing', 'parameters' => [], 'metadata' => []]);
        $candidate = ModelMarketPerformance::create(['model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'trend', 'status' => 'forward_validated']);

        $result = app(PaperConfidenceCalibrationService::class)->calibrate($candidate, 'trend_up', .72);

        $this->assertSame('insufficient_paper_evidence', $result['status']);
        $this->assertSame(.72, $result['confidence']);
        $this->assertTrue($result['allowed']);
    }

    public function test_calibrated_scope_uses_observed_bin_probability(): void
    {
        config(['services.paper_calibration.minimum_samples' => 20]);
        $model = ModelVersion::create(['name' => 'candidate', 'strategy' => 'xauusd_trend_g1_a01', 'version' => 'v1', 'generation' => 1, 'status' => 'testing', 'parameters' => [], 'metadata' => []]);
        $candidate = ModelMarketPerformance::create(['model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'trend', 'status' => 'forward_validated']);
        PaperConfidenceCalibration::create(['scope_key' => 'family:XAUUSD:H1:trend:trend_up', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'trend', 'market_regime' => 'trend_up', 'sample_count' => 24, 'brier_score' => .12, 'reliability_error' => .1, 'bins' => ['3' => ['posterior_win_probability' => .62]]]);

        $result = app(PaperConfidenceCalibrationService::class)->calibrate($candidate, 'trend_up', .72);

        $this->assertSame('calibrated', $result['status']);
        $this->assertSame(.62, $result['confidence']);
    }
}
