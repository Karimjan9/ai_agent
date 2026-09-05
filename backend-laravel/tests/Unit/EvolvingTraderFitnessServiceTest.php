<?php

namespace Tests\Unit;

use App\Services\EvolvingTraderFitnessService;
use App\Services\LearningReflectionService;
use Tests\TestCase;

class EvolvingTraderFitnessServiceTest extends TestCase
{
    public function test_all_fifteen_powered_dimensions_produce_bottleneck_penalized_fitness(): void
    {
        $result = [
            'total_trades' => 120,
            'fitness_breakdown' => ['components' => ['regime_coverage' => .8]],
            'router_evidence' => ['context_alignment_score' => .7],
            'smart_discipline' => [
                'average_setup_quality_score' => 80,
                'average_process_adherence_score' => 95,
            ],
            'confirmation_entry_evidence' => ['confirmation_quality' => .75, 'entry_precision' => .85],
            'position_sizing_evidence' => ['score' => .9],
            'management_evidence' => ['target_capture_ratio' => .6, 'premature_stop_rate' => .2],
            'walk_forward' => ['forward_window_protocol' => ['powered_windows' => 9, 'positive_windows' => 6]],
            'market_adaptive_replay' => ['adaptation' => ['score' => .7]],
        ];
        $operatingSystem = ['blocks' => [
            'edge_context' => ['score' => .8, 'powered' => true],
            'selection' => ['score' => .7, 'powered' => true],
            'execution' => ['score' => .9, 'powered' => true],
            'risk' => ['score' => .85, 'powered' => true],
            'management' => ['score' => .65, 'powered' => true],
            'process' => ['score' => .95, 'powered' => true],
        ]];

        $fitness = app(EvolvingTraderFitnessService::class)->assess($result, [
            'edge_quality' => .8, 'regime_coverage' => .8,
        ], $operatingSystem);

        $this->assertSame('complete_powered', $fitness['status']);
        $this->assertSame(15, data_get($fitness, 'evidence_coverage.observed_dimensions'));
        $this->assertIsFloat($fitness['bottleneck_penalized_score']);
        $this->assertSame('trade_management', data_get($fitness, 'weakest_observed_dimension.dimension'));
        $this->assertSame('one_axis_paired_bottleneck_repair', data_get($fitness, 'next_deliberate_practice.action'));
        $this->assertTrue((bool) data_get($fitness, 'next_deliberate_practice.rule_change_authorized'));
    }

    public function test_missing_capability_evidence_is_a_research_task_not_a_zero_score(): void
    {
        $fitness = app(EvolvingTraderFitnessService::class)->assess([], [], ['blocks' => []]);

        $this->assertSame('incomplete_evidence', $fitness['status']);
        $this->assertNull($fitness['bottleneck_penalized_score']);
        $this->assertContains('confirmation_quality', data_get($fitness, 'evidence_coverage.missing_dimensions'));
        $this->assertSame('collect_missing_capability_evidence', data_get($fitness, 'next_deliberate_practice.action'));
        $this->assertFalse((bool) data_get($fitness, 'next_deliberate_practice.rule_change_authorized'));
    }

    public function test_powered_weakest_dimension_drives_one_axis_learning_reflection(): void
    {
        $reflection = app(LearningReflectionService::class)->reflect([
            'metrics' => [
                'evolving_trader_fitness' => [
                    'status' => 'complete_powered',
                    'next_deliberate_practice' => [
                        'action' => 'one_axis_paired_bottleneck_repair',
                        'target' => 'confirmation_quality',
                        'rule_change_authorized' => true,
                    ],
                ],
                // The fifteen-dimension passport is the more precise causal
                // director once every dimension is independently powered.
                'trading_operating_system_scorecard' => [
                    'evolution_directive' => [
                        'status' => 'measured_bottleneck',
                        'target' => 'management_quality',
                    ],
                ],
            ],
        ], ['vetoes' => [], 'selection_reward' => -.1]);

        $this->assertSame('selection_error', $reflection['failure']);
        $this->assertSame('mutate_selection_quality', $reflection['next_action']);
        $this->assertSame('one_gene_paired_replay', $reflection['test']);
    }

    public function test_unpowered_fitness_cannot_override_measured_operating_system_target(): void
    {
        $reflection = app(LearningReflectionService::class)->reflect([
            'metrics' => [
                'evolving_trader_fitness' => [
                    'status' => 'incomplete_evidence',
                    'next_deliberate_practice' => [
                        'action' => 'collect_missing_capability_evidence',
                        'targets' => ['confirmation_quality'],
                        'rule_change_authorized' => false,
                    ],
                ],
                'trading_operating_system_scorecard' => [
                    'evolution_directive' => [
                        'status' => 'measured_bottleneck',
                        'target' => 'management_quality',
                    ],
                ],
            ],
        ], ['vetoes' => [], 'selection_reward' => -.1]);

        $this->assertSame('management_error', $reflection['failure']);
    }
}
