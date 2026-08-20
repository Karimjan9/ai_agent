<?php

namespace Tests\Feature;

use App\Models\ModelVersion;
use App\Services\StrategyCurriculumService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StrategyCurriculumServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_strategy_agents_receive_a_bounded_mastery_contract_and_only_validated_specialists_can_innovate(): void
    {
        $model = ModelVersion::create([
            'name' => 'fib-structure', 'strategy' => 'fibonacci_structure_pullback_v1', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing', 'parameters' => [], 'metadata' => [], 'evidence_status' => 'valid',
        ]);
        $service = app(StrategyCurriculumService::class);
        $contract = $service->enroll($model);

        $this->assertSame('fibonacci_structure', $contract->mastery_lane);
        $this->assertSame('liquidity_sweep_rejection', $contract->tactic_id);
        $this->assertSame('volatility_scaled_fractional', data_get($contract->sizing_contract, 'method'));
        $this->assertContains('dynamic_fibonacci_zone', $contract->allowed_instruments);
        $this->assertSame(0, $contract->innovation_budget);

        $passport = $service->assessMaster($model, [
            'protocol_adherence' => true, 'target_regime_coverage' => .7, 'net_edge_after_cost' => .2,
            'temporal_survival' => true, 'non_target_regression' => 0, 'abstention_quality' => .8,
            'incremental_lift' => .1, 'independent_windows' => 3,
        ]);
        $this->assertSame('validated', $passport->status);

        $contract->update(['training_stage' => 'validated_specialist', 'innovation_budget' => 1]);
        $trial = $service->proposeInnovation($contract->fresh(), ['dynamic_fibonacci_zone', 'liquidity_sweep'], ['trade_set_changed' => true]);
        $this->assertSame('innovation_trial', $trial->status);
        $this->assertFalse((bool) data_get($trial->evidence, 'promotion_evidence'));
    }
}
