<?php

namespace Tests\Unit;

use App\Services\AcademyExperimentContractCompilerService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcademyExperimentContractCompilerServiceTest extends TestCase
{
    use RefreshDatabase;

    private function baseline(): array
    {
        return app(StrategyParameterSchemaService::class)->defaults('confirmation_entry_mtf');
    }

    public function test_planner_controls_resolve_to_exact_baseline_without_leaking_markers_to_runtime(): void
    {
        $result = app(AcademyExperimentContractCompilerService::class)->compile([
            'axis' => 'trigger_topology_policy', 'arms' => [
                ['role' => 'frozen_control', 'value' => 'frozen_current'],
                ['role' => 'candidate', 'value' => 'aggressive_structure_close'],
                ['role' => 'blinded_control', 'value' => 'trigger_blinded_control'],
            ],
        ], $this->baseline(), ['symbol' => 'XAUUSD', 'laboratory_timeframe' => 'H1', 'execution_timeframe' => 'M5']);

        $this->assertSame('compiled', $result['status']);
        $this->assertSame($this->baseline()['trigger_topology_policy'], $result['arms'][0]['runtime_value']);
        $this->assertSame($this->baseline()['trigger_topology_policy'], $result['arms'][2]['runtime_value']);
        $this->assertNotSame('frozen_current', $result['arms'][0]['runtime_parameters']['trigger_topology_policy']);
    }

    public function test_unrepresentable_planner_operator_fails_closed(): void
    {
        $result = app(AcademyExperimentContractCompilerService::class)->compile([
            'axis' => 'confirmation_family_policy', 'arms' => [['role' => 'candidate', 'value' => 'reaction_plus_participation']],
        ], $this->baseline(), ['symbol' => 'XAUUSD', 'laboratory_timeframe' => 'H1', 'execution_timeframe' => 'M5']);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('ACADEMY_PLANNER_OPERATOR_REQUIRES_EXPLICIT_RUNTIME_SEMANTICS', $result['reason']);
    }
}
