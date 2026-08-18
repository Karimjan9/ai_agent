<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabGeneration;
use App\Services\FrozenControlParityService;
use App\Services\GateMarginService;
use App\Services\LabCandidateSelectionService;
use App\Services\LabPopulationService;
use App\Services\LearningProtocolSafetyService;
use App\Services\TargetedRescueProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GateMarginAndControlParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_gate_margin_preserves_strict_failure_but_ranks_the_nearest_target(): void
    {
        $result = [
            'total_trades' => 9,
            'profit_factor' => .92,
            'max_drawdown_percent' => 8,
            'monte_carlo' => ['risk_of_ruin_percent' => 4],
            'screening_survival' => [
                'stress_cost_pf' => .98,
                'worst_temporal_chunk_pf' => .91,
                'train_forward_gap' => 31,
                'parameter_perturbation_ratio' => .75,
                'worst_regime_pf' => 1.02,
            ],
            'data_manifest' => ['sha256' => str_repeat('a', 64)],
            'execution_contract' => ['execution_hash' => str_repeat('b', 64)],
        ];

        $margin = app(GateMarginService::class)->screening($result, [
            'FAILED_TEMPORAL_CHUNK_SURVIVAL',
            'FAILED_PROFIT_FACTOR',
        ]);

        $this->assertSame(GateMarginService::PROTOCOL, $margin['protocol']);
        $this->assertSame('temporal_stability', $margin['dominant_target']);
        $this->assertLessThan(100, $margin['near_miss_score']);
        $this->assertLessThan(0, $margin['gates']['temporal_stability']['margin']);
        $this->assertFalse($margin['all_known_gates_passed']);
        $this->assertFalse((bool) $margin['promotion_evidence']);
    }

    public function test_control_parity_is_not_applicable_for_an_ordinary_generation(): void
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD',
            'name' => 'Control parity test lab',
            'timeframe' => 'H1',
            'strategy_families' => ['hybrid'],
            'is_active' => true,
            'lifecycle_mode' => 'lighthouse',
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 1,
            'trigger_type' => 'test',
            'population_size' => 0,
            'status' => 'screened',
        ]);

        $parity = app(FrozenControlParityService::class)->assess($generation);

        $this->assertSame('not_applicable', $parity['status']);
        $this->assertSame(0, $parity['control_count']);
        $this->assertFalse((bool) $parity['promotion_evidence']);
    }

    public function test_paused_protocol_accepts_only_the_declared_five_seat_anchor_cohort(): void
    {
        $profile = [
            'cohort_mode' => 'four_siblings_plus_control_v1',
            'rescue_protocol' => LearningProtocolSafetyService::CONTROLLED_RESCUE_PROTOCOL,
            'temporary' => true,
            'promotion_evidence' => false,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'repair_anchors' => [['id' => 1]],
            'group_plan' => ['repair_anchor_cohort' => ['targets' => array_fill(0, 5, 'temporal_stability')]],
            'cohort_contract' => [
                'protocol' => 'four_siblings_plus_control_v1',
                'bounded_siblings' => 4,
                'frozen_control' => 1,
            ],
        ];

        $this->assertTrue(app(LearningProtocolSafetyService::class)->controlledRescueAllowed('candidate_handoff', 5, $profile));
        $this->assertFalse(app(LearningProtocolSafetyService::class)->controlledRescueAllowed('candidate_handoff', 4, $profile));
        $this->assertFalse(app(LearningProtocolSafetyService::class)->controlledRescueAllowed('new_data', 5, $profile));
    }

    public function test_train_forward_gap_uses_robustness_lane_without_mutating_the_split(): void
    {
        $result = [
            'total_trades' => 20,
            'profit_factor' => 1.1,
            'max_drawdown_percent' => 8,
            'screening_survival' => [
                'worst_temporal_chunk_pf' => 1.1,
                'train_forward_gap' => 40,
                'stress_cost_pf' => 1.1,
                'worst_regime_pf' => 1.1,
            ],
        ];
        $margin = app(GateMarginService::class)->screening($result, ['FAILED_TRAIN_FORWARD_GAP']);
        $laneMethod = new \ReflectionMethod(TargetedRescueProfileService::class, 'failureSpecificLane');
        $laneMethod->setAccessible(true);

        $this->assertSame('train_forward_robustness', $laneMethod->invoke(
            app(TargetedRescueProfileService::class),
            $margin,
            ['FAILED_TRAIN_FORWARD_GAP'],
        ));

        $geneMethod = new \ReflectionMethod(LabPopulationService::class, 'anchorCohortGenePlan');
        $geneMethod->setAccessible(true);
        $plan = $geneMethod->invoke(app(LabPopulationService::class), 'temporal_stability', 'hybrid', [
            'failure_reason' => 'FAILED_TRAIN_FORWARD_GAP',
            'parameter_snapshot' => app(\App\Services\StrategyParameterSchemaService::class)->defaults('hybrid'),
        ]);

        $this->assertSame([
            'confidence_calibration_min_samples',
            'weak_regime_min_samples',
            'meta_label_min_history',
            'cooldown_shadow_min_samples',
        ], array_column($plan, 'gene'));
        $this->assertNotContains('train_forward_split', array_column($plan, 'gene'));
    }

    public function test_special_replay_selector_fails_closed_until_control_parity_is_passed(): void
    {
        $method = new \ReflectionMethod(LabCandidateSelectionService::class, 'frozenControlParity');
        $method->setAccessible(true);
        $agent = (object) [
            'lab_generation_id' => 999999,
            'modelVersion' => (object) [
                'metadata' => [
                    'repair_anchor' => [
                        'cohort_contract' => 'four_siblings_plus_control_v1',
                    ],
                ],
            ],
        ];

        $parity = $method->invoke(app(LabCandidateSelectionService::class), collect([$agent]));

        $this->assertTrue($parity['required']);
        $this->assertFalse($parity['allowed']);
        $this->assertSame('incomplete', $parity['status']);
    }
}
