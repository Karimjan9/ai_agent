<?php

namespace Tests\Unit;

use App\Services\LearningReflectionService;
use App\Services\TradingOperatingSystemScorecardService;
use Tests\TestCase;

class TradingOperatingSystemScorecardServiceTest extends TestCase
{
    public function test_missing_management_and_independent_windows_are_evidence_tasks_not_zero_scores(): void
    {
        $scorecard = app(TradingOperatingSystemScorecardService::class)->assess($this->replay());

        $this->assertSame('incomplete_evidence', $scorecard['status']);
        $this->assertNull($scorecard['system_integrity_score']);
        $this->assertSame('UNRATED', $scorecard['quality_grade']);
        $this->assertNull(data_get($scorecard, 'blocks.management.score'));
        $this->assertContains('management', data_get($scorecard, 'evidence_coverage.missing_blocks'));
        $this->assertContains('learning', data_get($scorecard, 'evidence_coverage.missing_blocks'));
        $this->assertSame('collect_missing_evidence', data_get($scorecard, 'evolution_directive.status'));
        $this->assertFalse(data_get($scorecard, 'evolution_directive.one_axis_repair_authorized'));
    }

    public function test_all_powered_blocks_produce_multiplicative_grade_and_measured_repair_target(): void
    {
        $result = $this->replay();
        $result['management_evidence'] = [
            'target_capture_ratio' => .9,
            'stop_efficiency' => .8,
            'premature_stop_rate' => .1,
        ];
        $result['walk_forward'] = ['forward_window_protocol' => [
            'powered_windows' => 9,
            'positive_windows' => 6,
        ]];

        $scorecard = app(TradingOperatingSystemScorecardService::class)->assess($result);

        $this->assertSame('complete_powered', $scorecard['status']);
        $this->assertIsFloat($scorecard['system_integrity_score']);
        $this->assertNotSame('UNRATED', $scorecard['quality_grade']);
        $this->assertSame('measured_bottleneck', data_get($scorecard, 'evolution_directive.status'));
        $this->assertTrue(data_get($scorecard, 'invariants.missing_evidence_is_not_zero'));
    }

    public function test_replay_management_power_contract_overrides_total_trade_count(): void
    {
        $result = $this->replay();
        $result['management_evidence'] = [
            'target_capture_ratio' => .9,
            'observed_trades' => 30,
            'measured_winner_paths' => 2,
            'powered' => false,
            'path_precision' => 'candle_extrema_including_exit_bar',
        ];
        $result['walk_forward'] = ['forward_window_protocol' => [
            'powered_windows' => 9,
            'positive_windows' => 6,
        ]];

        $scorecard = app(TradingOperatingSystemScorecardService::class)->assess($result);

        $this->assertSame('underpowered', $scorecard['status']);
        $this->assertFalse(data_get($scorecard, 'blocks.management.powered'));
        $this->assertContains('management', data_get($scorecard, 'evidence_coverage.underpowered_blocks'));
        $this->assertNull($scorecard['system_integrity_score']);
    }

    public function test_selection_funnel_scope_mismatch_cannot_be_powered(): void
    {
        $result = $this->replay();
        $result['entry_funnel'] = ['accepted_entries' => 60];

        $scorecard = app(TradingOperatingSystemScorecardService::class)->assess($result);

        $this->assertFalse(data_get($scorecard, 'blocks.selection.powered'));
        $this->assertFalse(data_get($scorecard, 'blocks.selection.evidence.funnel_scope_consistent'));
        $this->assertContains('selection', data_get($scorecard, 'evidence_coverage.underpowered_blocks'));
        $this->assertSame('evidence_completion', $scorecard['evolution_directive']['target']);
    }

    public function test_incomplete_scorecard_routes_to_evidence_completion_not_a_false_component_cause(): void
    {
        $service = app(TradingOperatingSystemScorecardService::class);
        $attribution = $service->causalAttribution([
            'status' => 'incomplete_evidence',
            'blocks' => [
                'edge_context' => ['score' => .8],
                'selection' => ['score' => .4],
                'risk' => ['score' => .9],
            ],
            'weakest_observed_block' => ['block' => 'selection'],
            'evidence_coverage' => ['missing_blocks' => ['management']],
        ]);

        $this->assertSame('evidence_completion', $attribution['primary_cause']);
        $this->assertSame([], $attribution['contributions']);
        $this->assertContains('management', $attribution['evidence_gaps']);
    }

    public function test_complete_powered_attribution_uses_measured_deficits_instead_of_fixed_weights(): void
    {
        $service = app(TradingOperatingSystemScorecardService::class);
        $attribution = $service->causalAttribution([
            'status' => 'complete_powered',
            'blocks' => [
                'edge_context' => ['score' => .8],
                'selection' => ['score' => .4],
                'risk' => ['score' => .9],
            ],
            'weakest_observed_block' => ['block' => 'selection'],
            'evidence_coverage' => ['missing_blocks' => [], 'underpowered_blocks' => []],
        ]);

        $this->assertSame('selection', $attribution['primary_cause']);
        $this->assertGreaterThan($attribution['contributions']['edge_context'], $attribution['contributions']['selection']);
        $this->assertSame([], $attribution['evidence_gaps']);
    }

    public function test_learning_reflection_routes_only_a_fully_measured_bottleneck(): void
    {
        $reflection = app(LearningReflectionService::class)->reflect([
            'metrics' => ['trading_operating_system_scorecard' => [
                'evolution_directive' => [
                    'status' => 'measured_bottleneck',
                    'target' => 'selection_quality',
                ],
            ]],
        ], ['vetoes' => [], 'selection_reward' => .2]);

        $this->assertSame('selection_error', $reflection['failure']);
        $this->assertSame('mutate_selection_quality', $reflection['next_action']);
        $this->assertSame('one_gene_paired_replay', $reflection['test']);
    }

    /** @return array<string,mixed> */
    private function replay(): array
    {
        return [
            'total_trades' => 30,
            'profit_factor' => 1.3,
            'max_drawdown_percent' => 4,
            'monte_carlo' => ['risk_of_ruin_percent' => 2],
            'statistical_evidence' => [
                'deflated_sharpe' => ['deflated_sharpe_probability' => .8],
            ],
            'fitness_breakdown' => ['components' => ['regime_coverage' => .75]],
            'opportunity_recall' => [
                'opportunities' => 40,
                'opportunity_recall' => .7,
                'abstention_precision' => .8,
            ],
            'pf_attribution' => ['summary' => [
                'net_pf' => 1.3,
                'cost_to_gross_profit_percent' => 10,
            ]],
            'data_quality' => ['status' => 'passed'],
            'execution_contract' => ['status' => 'matched'],
            'proof_carrying_replay' => ['status' => 'passed'],
            'temporal_firewall_passed' => true,
        ];
    }
}
