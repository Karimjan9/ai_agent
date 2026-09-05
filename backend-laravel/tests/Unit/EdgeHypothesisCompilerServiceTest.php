<?php

namespace Tests\Unit;

use App\Services\EdgeHypothesisCompilerService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Support\Collection;
use ReflectionMethod;
use Tests\TestCase;

class EdgeHypothesisCompilerServiceTest extends TestCase
{
    public function test_every_compiled_arm_value_is_distinct_and_inside_runtime_schema(): void
    {
        $compiler = app(EdgeHypothesisCompilerService::class);
        $method = new ReflectionMethod($compiler, 'armValues');
        $method->setAccessible(true);
        $schemas = app(StrategyParameterSchemaService::class);

        foreach (['m5_retest_expiry_minutes' => 20, 'time_stop_candles' => 240] as $axis => $base) {
            $values = $method->invoke($compiler, $axis, $base);
            [$type, $minimum, $maximum] = $schemas->schema('confirmation_entry_mtf')[$axis];

            $this->assertCount(5, $values);
            $this->assertCount(5, array_unique(array_values($values), SORT_REGULAR));
            $this->assertSame($base, $values['compiled_control']);
            foreach ($values as $value) {
                $this->assertGreaterThanOrEqual($minimum, $value);
                $this->assertLessThanOrEqual($maximum, $value);
                if ($type === 'integer') $this->assertIsInt($value);
            }
        }

        $entryModels = $method->invoke($compiler, 'entry_model', 'trend_continuation');
        $this->assertSame([
            'compiled_control' => 'trend_continuation',
            'compiled_primary' => 'breakout_retest',
            'compiled_refinement' => 'false_break_reversal',
            'compiled_counterfactual' => 'range_sweep',
            'compiled_negative_control' => 'htf_reversal',
        ], $entryModels);
        $this->assertCount(5, array_unique($entryModels));
        $triggerTopologies = $method->invoke($compiler, 'trigger_topology_policy', 'balanced_retest_reaction');
        $this->assertSame('balanced_retest_reaction', $triggerTopologies['compiled_control']);
        $this->assertCount(5, array_unique($triggerTopologies));
    }

    public function test_semantic_axis_debt_survives_new_generation_and_compiled_packet_names(): void
    {
        $compiler = app(EdgeHypothesisCompilerService::class);
        $semantic = new ReflectionMethod($compiler, 'semanticAxisKey');
        $semantic->setAccessible(true);
        $root = new ReflectionMethod($compiler, 'rootPacketIdentity');
        $root->setAccessible(true);

        $first = ['key' => 'break_retest_compiled_aaaaaaaaaa', 'label' => 'Break Retest Compiled old_axis',
            'strategy_id' => 'str_031', 'tactic_id' => 'break_retest', 'management_id' => 'balanced'];
        $descendant = [...$first, 'key' => 'break_retest_compiled_aaaaaaaaaa_compiled_bbbbbbbbbb',
            'label' => 'Break Retest Compiled old_axis Compiled new_axis'];

        $this->assertSame(
            $semantic->invoke($compiler, $first, 'sparse_fold_power', 'm5_retest_expiry_minutes', 'baseline-a'),
            $semantic->invoke($compiler, $descendant, 'sparse_fold_power', 'm5_retest_expiry_minutes', 'baseline-a')
        );
        $this->assertSame(
            $semantic->invoke($compiler, $first, 'sparse_fold_power', 'm5_retest_expiry_minutes', 'baseline-a'),
            $semantic->invoke($compiler, $first, 'confirmation_entry_starvation', 'm5_retest_expiry_minutes', 'baseline-a')
        );
        $this->assertNotSame(
            $semantic->invoke($compiler, $first, 'sparse_fold_power', 'm5_retest_expiry_minutes', 'baseline-a'),
            $semantic->invoke($compiler, $first, 'sparse_fold_power', 'm5_minimum_displacement_atr', 'baseline-a')
        );
        $this->assertNotSame(
            $semantic->invoke($compiler, $first, 'sparse_fold_power', 'm5_retest_expiry_minutes', 'baseline-a'),
            $semantic->invoke($compiler, $first, 'sparse_fold_power', 'm5_retest_expiry_minutes', 'baseline-b')
        );
        $this->assertSame(['key' => 'break_retest', 'label' => 'Break Retest'], $root->invoke($compiler, $descendant));
    }

    public function test_compiler_reads_the_exact_entry_funnel_bottleneck_before_selecting_an_axis(): void
    {
        $compiler = app(EdgeHypothesisCompilerService::class);
        $diagnose = new ReflectionMethod($compiler, 'diagnose');
        $diagnose->setAccessible(true);
        $blueprints = new ReflectionMethod($compiler, 'blueprints');
        $blueprints->setAccessible(true);
        $best = ['expectancy_r' => 0.0, 'powered_folds' => 0, 'positive_folds' => 0];
        $rows = new Collection([[
            ...$best,
            'control' => false,
            'opportunities' => 4000,
            'setups' => 85,
            'entries' => 0,
            'trades' => 0,
            'mfe_capture_ratio' => 0.0,
            'funnel_setup' => 61,
            'funnel_confirmation' => 61,
            'funnel_trigger' => 0,
            'funnel_entry_ready' => 0,
            'trigger_diagnosis' => 'setup_aligned_structure_event_absent',
        ]]);

        $result = $diagnose->invoke($compiler, $rows, $best);
        $this->assertSame('trigger_topology_starvation', $result['code']);
        $this->assertSame('setup_aligned_structure_event_absent', $result['triggerDiagnosis']);
        $this->assertSame(61, $result['funnelConfirmations']);
        $this->assertSame(0, $result['funnelTriggers']);
        $compiledBlueprints = $blueprints->invoke($compiler,
            ['m5_minimum_displacement_atr' => .5, 'entry_model' => 'trend_continuation'], $result);
        $this->assertSame('trigger_topology_policy', $compiledBlueprints[0]['axis']);
        $this->assertContains('entry_model', array_column($compiledBlueprints, 'axis'));
    }

    public function test_losing_entry_path_is_kept_as_a_research_stepping_stone_over_zero_trade_abstention(): void
    {
        $compiler = app(EdgeHypothesisCompilerService::class);
        $rank = new ReflectionMethod($compiler, 'rankSourceObservations');
        $rank->setAccessible(true);
        $rows = new Collection([
            ['model_version_id' => 1, 'expectancy_r' => 0.0, 'trades' => 0, 'entries' => 0,
                'funnel_setup' => 60, 'funnel_confirmation' => 60, 'funnel_trigger' => 0, 'funnel_entry_ready' => 0],
            ['model_version_id' => 2, 'expectancy_r' => -1.0, 'trades' => 1, 'entries' => 3,
                'funnel_setup' => 47, 'funnel_confirmation' => 47, 'funnel_trigger' => 3, 'funnel_entry_ready' => 3],
        ]);

        $ranked = $rank->invoke($compiler, $rows);
        $this->assertSame(2, $ranked->first()['model_version_id']);
        $this->assertSame(4, $ranked->first()['causal_depth']);
    }

    public function test_raw_signal_count_growth_cannot_displace_an_equivalent_frozen_control(): void
    {
        $compiler = app(EdgeHypothesisCompilerService::class);
        $rank = new ReflectionMethod($compiler, 'rankSourceObservations');
        $rank->setAccessible(true);
        $common = ['expectancy_r' => -1.0, 'trades' => 1, 'funnel_setup' => 47,
            'funnel_confirmation' => 47, 'funnel_trigger' => 3, 'funnel_entry_ready' => 3,
            'positive_folds' => 0, 'powered_folds' => 1];
        $rows = new Collection([
            [...$common, 'model_version_id' => 10, 'control' => true, 'entries' => 15],
            [...$common, 'model_version_id' => 11, 'control' => false, 'entries' => 24],
        ]);

        $ranked = $rank->invoke($compiler, $rows);
        $this->assertSame(10, $ranked->first()['model_version_id']);
        $this->assertSame(1, $ranked->first()['control_anchor']);
    }

    public function test_axis_epoch_budget_is_stable_across_descendant_parameter_hashes(): void
    {
        $compiler = app(EdgeHypothesisCompilerService::class);
        $key = new ReflectionMethod($compiler, 'axisEpochKey');
        $key->setAccessible(true);
        $packet = ['strategy_id' => 'str_031', 'tactic_id' => 'break_retest',
            'management_id' => 'balanced'];

        $this->assertSame(
            $key->invoke($compiler, $packet, 'negative_entry_quality', 'max_chase_atr'),
            $key->invoke($compiler, [...$packet, 'key' => 'descendant_compiled_hash'],
                'negative_entry_quality', 'max_chase_atr')
        );
        $this->assertNotSame(
            $key->invoke($compiler, $packet, 'negative_entry_quality', 'max_chase_atr'),
            $key->invoke($compiler, $packet, 'trigger_topology_starvation', 'max_chase_atr')
        );
    }

    public function test_compiled_registry_budget_is_scoped_to_the_professional_island(): void
    {
        $compiler = app(EdgeHypothesisCompilerService::class);
        $registeredForPacket = new ReflectionMethod($compiler, 'registeredForPacket');
        $registeredForPacket->setAccessible(true);
        $row = static fn (string $key, string $strategy, string $tactic): object => (object) [
            'definition' => json_encode(['source_packet' => [
                'key' => $key, 'strategy_id' => $strategy, 'tactic_id' => $tactic,
                'management_id' => 'balanced',
            ]]),
        ];
        $registry = new Collection([
            $row('break_retest_compiled_aaaaaaaaaa', 'str_031', 'break_retest'),
            $row('trend_pullback_compiled_bbbbbbbbbb', 'str_001', 'trend_pullback'),
        ]);

        $breakRows = $registeredForPacket->invoke($compiler, $registry, [
            'key' => 'break_retest', 'strategy_id' => 'str_031', 'tactic_id' => 'break_retest',
            'management_id' => 'balanced',
        ]);
        $this->assertCount(1, $breakRows);
        $this->assertStringContainsString('break_retest', $breakRows->first()->definition);
    }
}
