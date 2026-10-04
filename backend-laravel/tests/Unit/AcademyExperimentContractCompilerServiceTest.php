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
        return [...app(StrategyParameterSchemaService::class)->defaults('confirmation_entry_mtf'), 'setup_topology_policy' => 'liquidity_sweep_reclaim'];
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

    public function test_nonfinite_runtime_values_and_mismatched_axes_fail_closed(): void
    {
        $compiler = app(AcademyExperimentContractCompilerService::class);
        $identity = ['symbol' => 'XAUUSD', 'laboratory_timeframe' => 'H1', 'execution_timeframe' => 'M5'];
        foreach ([NAN, INF, -INF] as $value) {
            $trial = ['axis' => 'location_tolerance_atr', 'arms' => [['role' => 'candidate', 'value' => $value]]];
            $this->assertSame('ACADEMY_RUNTIME_NONFINITE_VALUE', $compiler->compile($trial, $this->baseline(), $identity)['reason']);
            $baseline = [...$this->baseline(), 'location_tolerance_atr' => $value];
            $this->assertSame('ACADEMY_RUNTIME_NONFINITE_VALUE', $compiler->compile($trial, $baseline, $identity)['reason']);
        }
        $result = $compiler->compile(['axis' => 'location_tolerance_atr', 'arms' => [
            ['role' => 'candidate', 'value' => .5, 'changed_axis' => 'max_chase_atr'],
        ]], $this->baseline(), $identity);
        $this->assertSame('ACADEMY_ARM_AXIS_MISMATCH', $result['reason']);
    }

    public function test_control_roles_cannot_be_used_as_hidden_interventions(): void
    {
        $compiler = app(AcademyExperimentContractCompilerService::class);
        $identity = ['symbol' => 'XAUUSD', 'laboratory_timeframe' => 'H1', 'execution_timeframe' => 'M5'];
        $trial = ['axis' => 'location_tolerance_atr', 'arms' => [['role' => 'candidate', 'value' => 'frozen_current']]];
        $this->assertSame('ACADEMY_CONTROL_OPERATOR_ROLE_MISMATCH', $compiler->compile($trial, $this->baseline(), $identity)['reason']);
        $trial['arms'] = [['role' => 'frozen_control', 'value' => .5]];
        $this->assertSame('ACADEMY_FROZEN_CONTROL_MUST_EQUAL_BASELINE', $compiler->compile($trial, $this->baseline(), $identity)['reason']);
    }

    public function test_schema_legal_but_runtime_inactive_axes_are_not_executable_experiments(): void
    {
        $compiler = app(AcademyExperimentContractCompilerService::class);
        $identity = ['symbol' => 'XAUUSD', 'laboratory_timeframe' => 'H1', 'execution_timeframe' => 'M5'];
        foreach (['pullback_rejection', 'breakout_and_retest'] as $policy) {
            $baseline = [...$this->baseline(), 'setup_topology_policy' => $policy];
            $trial = ['axis' => 'location_tolerance_atr', 'arms' => [['role' => 'candidate', 'value' => .45]]];
            $this->assertSame('ACADEMY_AXIS_INACTIVE_IN_RUNTIME_MODEL', $compiler->compile($trial, $baseline, $identity)['reason']);
        }
        $trial = ['axis' => 'atr_target_multiplier', 'arms' => [['role' => 'candidate', 'value' => 3.]]];
        $this->assertSame('ACADEMY_AXIS_OVERRIDDEN_BY_STRUCTURAL_TARGET', $compiler->compile($trial, $this->baseline(), $identity)['reason']);
    }
}
