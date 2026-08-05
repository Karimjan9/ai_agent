<?php

namespace Tests\Unit;

use App\Services\StrategyParameterSchemaService;
use Tests\TestCase;

class StrategyParameterSchemaServiceTest extends TestCase
{
    public function test_composite_runtime_keeps_its_identity_when_parent_metadata_is_stale(): void
    {
        $schemas = app(StrategyParameterSchemaService::class);

        $this->assertSame(
            'differential_router_v1',
            $schemas->runtimeBaseStrategy(
                'xauusd_differential_router_g103_a01',
                'breakout_v1',
                'differential_router',
            ),
        );
        $this->assertSame(
            'regime_ensemble_v1',
            $schemas->runtimeBaseStrategy(
                'eurusd_regime_ensemble_g4_a02',
                'breakout_v1',
                'regime_ensemble',
            ),
        );
    }
}
