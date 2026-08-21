<?php

namespace Tests\Feature;

use App\Services\FeatureProvenanceValidator;
use App\Services\FeatureSnapshotService;
use App\Services\FeatureValueCatalogService;
use App\Services\StrategyFeatureBundleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeatureValueOperatingSystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_snapshot_and_strategy_bundle_keep_feature_provenance(): void
    {
        app(FeatureValueCatalogService::class)->seed();
        $capture = app(FeatureSnapshotService::class)->capture('XAUUSD', 'M15', CarbonImmutable::parse('2026-08-20 10:00:00Z'), [
            'confirmed_swing_high' => 3400.0, 'confirmed_swing_low' => 3380.0, 'dynamic_fib_618' => 3387.64,
            'liquidity_sweep' => 'bullish', 'displacement_atr' => 1.2, 'spread_atr_ratio' => .1,
            'session_range' => 12.0, 'state_confidence' => .72,
        ]);
        $bundle = app(StrategyFeatureBundleService::class)->for('fibonacci_structure_pullback', $capture['snapshot']);

        $this->assertTrue($capture['valid']);
        $this->assertSame('eligible', $bundle['status']);
        $this->assertSame(3387.64, $bundle['values']['dynamic_fib_618']);
        $this->assertDatabaseCount('feature_value_catalog', 54);
        $this->assertDatabaseCount('feature_snapshots', 1);
    }

    public function test_validator_rejects_lookahead_or_missing_metadata(): void
    {
        $check = app(FeatureProvenanceValidator::class)->validate(['fvg' => 'bullish'], ['fvg' => ['lookahead_safe' => false, 'source' => '', 'timeframe' => 'M15']]);
        $this->assertFalse($check['valid']);
        $this->assertContains('fvg:LOOKAHEAD_UNSAFE', $check['reasons']);
    }
}
