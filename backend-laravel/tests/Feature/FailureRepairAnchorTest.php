<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Models\ModelMarketPerformance;
use App\Models\LabMutationResponseMap;
use App\Services\FailureRepairAnchorService;
use App\Services\CandidateGateDecisionService;
use App\Services\LabPopulationService;
use App\Services\MutationResponseMapService;
use App\Services\SkillMentorService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FailureRepairAnchorTest extends TestCase
{
    use RefreshDatabase;

    public function test_numeric_type_only_change_cannot_satisfy_a_one_gene_constructor(): void
    {
        $service = app(LabPopulationService::class);
        $diff = new \ReflectionMethod($service, 'diff');
        $diff->setAccessible(true);

        $this->assertSame([], $diff->invoke($service, [
            'partial_take_profit_fraction' => 0,
        ], [
            'partial_take_profit_fraction' => 0.0,
        ]));

        $mutation = new \ReflectionMethod($service, 'declaredSingleGeneMutation');
        $mutation->setAccessible(true);
        $schema = app(StrategyParameterSchemaService::class);
        $base = $schema->defaults('hybrid');
        $child = $mutation->invoke(
            $service,
            $base,
            $schema->schema('hybrid'),
            'partial_take_profit_fraction',
            1,
            [],
            [],
            'decrease',
        );

        $childDiff = $diff->invoke($service, $base, $child);
        $this->assertCount(1, $childDiff);
        $this->assertSame('partial_take_profit_fraction', array_key_first($childDiff));
        $this->assertNotEquals(
            (float) $base['partial_take_profit_fraction'],
            (float) $child['partial_take_profit_fraction'],
        );
    }

    public function test_complete_strategy_failure_creates_an_immutable_anchor_and_technical_failure_does_not(): void
    {
        [$agent, $parameters] = $this->sourceAgent();
        $service = app(FailureRepairAnchorService::class);
        $evidence = [
            'screening_result' => [
                'profit_factor' => .82,
                'total_trades' => 38,
                'data_manifest' => ['sha256' => str_repeat('a', 64)],
                'execution_contract' => ['execution_hash' => str_repeat('b', 64)],
            ],
        ];

        $anchor = $service->recordForAgent($agent, 'FAILED_PROFIT_FACTOR', null, $evidence, true);
        $this->assertNotNull($anchor);
        $snapshot = $anchor->parameter_snapshot;
        $fingerprint = $anchor->parameter_fingerprint;

        // A retry is idempotent and cannot rewrite the failed vector.
        $retry = $service->recordForAgent($agent->fresh(), 'FAILED_PROFIT_FACTOR', null, [
            ...$evidence,
            'operator_note' => 'later observation must not rewrite the anchor',
        ], true);
        $this->assertSame($anchor->id, $retry?->id);
        $this->assertSame($snapshot, $retry?->fresh()->parameter_snapshot);
        $this->assertSame($fingerprint, $retry?->fresh()->parameter_fingerprint);

        $technical = $service->recordForAgent(
            $agent->fresh(),
            'FAILED_DATA_QUALITY',
            null,
            ['screening_result' => ['profit_factor' => .3]],
            true,
        );
        $this->assertNull($technical);
        $this->assertDatabaseCount('lab_failure_repair_anchors', 1);
    }

    public function test_stale_repair_anchor_rebases_without_rewriting_the_old_snapshot(): void
    {
        [$agent] = $this->sourceAgent();
        $service = app(FailureRepairAnchorService::class);
        $oldHash = str_repeat('a', 64);
        $newHash = str_repeat('c', 64);
        $executionHash = str_repeat('b', 64);

        $anchor = $service->recordForAgent($agent, 'FAILED_PROFIT_FACTOR', null, [
            'screening_result' => [
                'profit_factor' => .8,
                'total_trades' => 40,
                'data_manifest' => ['sha256' => $oldHash],
                'execution_contract' => ['execution_hash' => $executionHash],
            ],
        ], true);
        $this->assertNotNull($anchor);

        $agent->modelVersion->update(['metadata' => ['repair_anchor' => ['id' => $anchor->id]]]);
        $rebased = $service->recordForAgent($agent->fresh(['modelVersion', 'generation']), 'FAILED_PROFIT_FACTOR', null, [
            'screening_result' => [
                'profit_factor' => .75,
                'total_trades' => 40,
                'data_manifest' => ['sha256' => $newHash],
                'execution_contract' => ['execution_hash' => $executionHash],
            ],
        ], true, true);

        $this->assertNotNull($rebased);
        $this->assertNotSame($anchor->id, $rebased->id);
        $this->assertSame($oldHash, data_get($anchor->fresh()->evidence, 'screening_result.data_manifest.sha256'));
        $this->assertSame($newHash, data_get($rebased->evidence, 'screening_result.data_manifest.sha256'));
        $this->assertSame($anchor->id, data_get($rebased->evidence, 'rebase.from_anchor_id'));
        $this->assertDatabaseCount('lab_failure_repair_anchors', 2);
    }

    public function test_repair_child_uses_anchor_snapshot_and_changes_exactly_one_declared_gene_without_a_parent(): void
    {
        $schema = app(StrategyParameterSchemaService::class);
        [$source, $parameters] = $this->sourceAgent();
        $anchor = app(FailureRepairAnchorService::class)->recordForAgent(
            $source,
            'FAILED_PROFIT_FACTOR',
            null,
            ['screening_result' => ['profit_factor' => .8, 'total_trades' => 40]],
            true,
        );
        $this->assertNotNull($anchor);

        $generation = LabGeneration::create([
            'ai_laboratory_id' => $source->generation->ai_laboratory_id,
            'generation' => 2,
            'trigger_type' => 'candidate_handoff',
            'population_size' => 1,
            'status' => 'draft',
            'trigger_context' => [],
        ]);
        $method = new \ReflectionMethod(LabPopulationService::class, 'createAgent');
        $method->setAccessible(true);
        $created = $method->invoke(
            app(LabPopulationService::class),
            $generation,
            'differential_router',
            'targeted_failure_profile',
            1,
            'profit_factor',
            [
                'protocol' => 'targeted_failure_profile_v1',
                'specialist_role' => 'edge_quality_specialist',
                'failure_target' => 'profit_factor',
                'declared_gene' => 'minimum_signal_confidence',
                'repair_anchor_id' => $anchor->id,
                'repair_anchor_protocol' => FailureRepairAnchorService::PROTOCOL,
            ],
            null,
            'volatility_session_stability',
            1,
        );

        $this->assertTrue($created);
        $child = $generation->agents()->with('modelVersion')->first();
        $this->assertNotNull($child);
        $this->assertNull($child->parent_a_model_version_id);
        $this->assertNull($child->parent_b_model_version_id);
        $this->assertSame($anchor->id, (int) data_get($child->modelVersion->metadata, 'repair_anchor.id'));
        $this->assertTrue((bool) data_get($child->modelVersion->metadata, 'repair_anchor.snapshot_is_immutable'));
        $this->assertSame('repair_anchor_only', data_get($child->modelVersion->metadata, 'adaptive_parent_ecosystem.status'));

        $diff = (array) $child->parameter_diff;
        $this->assertCount(1, $diff);
        $gene = array_key_first($diff);
        $this->assertSame('minimum_signal_confidence', $gene);
        $this->assertNotSame($parameters[$gene], $child->modelVersion->parameters[$gene]);
        foreach (array_keys($schema->schema('differential_router')) as $key) {
            if ($key === $gene) continue;
            $this->assertEquals($parameters[$key] ?? null, $child->modelVersion->parameters[$key] ?? null, $key);
        }
    }

    public function test_normal_generation_is_blocked_after_screening_failures_until_a_pass_exists(): void
    {
        $generation = app(LabPopulationService::class)->build('XAUUSD', 'new_data', true);
        $this->assertNotNull($generation);
        $generation->update(['status' => 'completed']);
        $agent = $generation->agents()->firstOrFail();

        DB::table('candidate_gate_decisions')->insert([
            'lab_agent_id' => $agent->id,
            'stage' => 'screening',
            'decision' => 'failed',
            'reason_codes' => json_encode(['FAILED_PROFIT_FACTOR']),
            'metrics' => json_encode(['profit_factor' => .8]),
            'evaluated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertNull(app(LabPopulationService::class)->build('XAUUSD', 'new_data', true));
    }

    public function test_strategy_failure_aliases_compile_to_a_causal_target_but_evidence_failures_do_not(): void
    {
        $service = app(FailureRepairAnchorService::class);

        $this->assertSame('stress_cost', $service->targetForReason('FAILED_NOISE_SANITY'));
        $this->assertSame('architecture', $service->targetForReason('FAILED_STATISTICAL_FALSIFIER'));
        $this->assertSame('architecture', $service->targetForReason('FAILED_ELITE_PASSPORT'));
        $this->assertSame('trade_frequency', $service->targetForReason('FAILED_RESCUE_TRADE_COUNT'));
        $this->assertNull($service->targetForReason('FAILED_INDEPENDENT_FORWARD_WINDOW_EVIDENCE'));
        $this->assertNull($service->targetForReason('FAILED_DATA_QUALITY'));
    }

    public function test_anchor_compiles_exactly_four_bounded_siblings_and_frozen_control_is_immutable(): void
    {
        [$source] = $this->sourceAgent();
        $anchors = app(FailureRepairAnchorService::class);
        $anchor = $anchors->recordForAgent(
            $source,
            'FAILED_PROFIT_FACTOR',
            null,
            ['screening_result' => ['profit_factor' => .8, 'total_trades' => 40]],
            true,
        );
        $this->assertNotNull($anchor);

        $service = app(LabPopulationService::class);
        $method = new \ReflectionMethod($service, 'anchorSiblingPlan');
        $method->setAccessible(true);
        $plan = $method->invoke($service, $source->generation->laboratory, [
            'source_generation_id' => $source->lab_generation_id,
            'repair_anchors' => [$anchors->descriptor($anchor)],
        ], 4);

        $this->assertCount(4, $plan);
        $this->assertSame(
            ['primary_direction', 'reverse_direction', 'alternative_gene', 'frozen_control'],
            array_column(array_map(fn (array $seat): array => (array) data_get($seat, 'niche'), $plan), 'sibling_kind'),
        );
        $this->assertCount(1, collect($plan)->map(fn (array $seat): mixed => data_get($seat, 'niche.repair_anchor_sibling_cohort_id'))->unique());
        $this->assertTrue(collect($plan)->every(fn (array $seat): bool => (int) data_get($seat, 'niche.repair_anchor_id') === (int) $anchor->id));
        $this->assertSame('edge_quality_specialist', data_get($plan[0], 'niche.specialist_role'));

        $generation = LabGeneration::create([
            'ai_laboratory_id' => $source->generation->ai_laboratory_id,
            'generation' => 2,
            'trigger_type' => 'candidate_handoff',
            'population_size' => 1,
            'status' => 'draft',
            'trigger_context' => [],
        ]);
        $create = new \ReflectionMethod($service, 'createAgent');
        $create->setAccessible(true);
        $this->assertTrue($create->invoke(
            $service,
            $generation,
            $plan[0]['family'],
            $plan[0]['origin'],
            1,
            $plan[0]['target'],
            $plan[0]['niche'],
            null,
            $plan[0]['research_group'],
            1,
        ));
        $this->assertTrue($create->invoke(
            $service,
            $generation,
            $plan[3]['family'],
            $plan[3]['origin'],
            2,
            $plan[3]['target'],
            $plan[3]['niche'],
            null,
            $plan[3]['research_group'],
            4,
        ));
        $children = $generation->agents()->with('modelVersion')->orderBy('id')->get();
        $control = $children->last();
        $this->assertSame([], (array) $control->parameter_diff);
        $this->assertTrue((bool) data_get($control->modelVersion->metadata, 'repair_anchor.control_only'));
        $this->assertSame($anchor->parameter_snapshot, $control->modelVersion->parameters);
        $this->assertNull($control->parent_a_model_version_id);
        $this->assertNull($control->parent_b_model_version_id);
    }

    public function test_gate_margin_cohort_compiles_four_one_gene_siblings_plus_one_frozen_control(): void
    {
        [$source] = $this->sourceAgent();
        $anchors = app(FailureRepairAnchorService::class);
        $anchor = $anchors->recordForAgent(
            $source,
            'FAILED_TEMPORAL_CHUNK_SURVIVAL',
            null,
            ['screening_result' => ['profit_factor' => .8, 'total_trades' => 40]],
            true,
        );
        $this->assertNotNull($anchor);

        $method = new \ReflectionMethod(app(LabPopulationService::class), 'anchorSiblingPlan');
        $method->setAccessible(true);
        $plan = $method->invoke(app(LabPopulationService::class), $source->generation->laboratory, [
            'source_generation_id' => $source->lab_generation_id,
            'cohort_mode' => 'four_siblings_plus_control_v1',
            'repair_anchors' => [$anchors->descriptor($anchor)],
        ], 5);

        $this->assertCount(5, $plan);
        $this->assertSame(
            ['primary_direction', 'reverse_direction', 'alternative_gene', 'secondary_alternative_gene', 'frozen_control'],
            array_column(array_map(fn (array $seat): array => (array) data_get($seat, 'niche'), $plan), 'sibling_kind'),
        );
        $this->assertTrue(collect($plan)->every(fn (array $seat): bool =>
            data_get($seat, 'niche.cohort_contract') === 'four_siblings_plus_control_v1'
        ));
        $this->assertNull(data_get($plan[4], 'niche.declared_gene'));
    }

    public function test_temporal_state_persistence_hypothesis_replaces_repeated_indicator_window_mutations(): void
    {
        [$source] = $this->sourceAgent();
        $anchors = app(FailureRepairAnchorService::class);
        $anchor = $anchors->recordForAgent(
            $source,
            'FAILED_TEMPORAL_CHUNK_SURVIVAL',
            null,
            ['screening_result' => ['profit_factor' => .8, 'total_trades' => 40]],
            true,
        );
        $this->assertNotNull($anchor);

        $service = app(LabPopulationService::class);
        $planMethod = new \ReflectionMethod($service, 'anchorSiblingPlan');
        $planMethod->setAccessible(true);
        $plan = $planMethod->invoke($service, $source->generation->laboratory, [
            'source_generation_id' => $source->lab_generation_id,
            'cohort_mode' => 'four_siblings_plus_control_v1',
            'repair_anchors' => [$anchors->descriptor($anchor)],
            'temporal_edge_audit' => [
                'protocol' => 'temporal_failure_cell_audit_v1',
                'dominant_cells' => ['chunk' => ['cell' => 'chunk_4']],
            ],
        ], 5);

        $expectedGenes = [
            'max_loss_streak_before_wait',
            'loss_cooldown_candles',
            'loss_streak_wait_candles',
            'weak_regime_wait_candles',
        ];
        $this->assertSame($expectedGenes, array_map(
            fn (array $seat): mixed => data_get($seat, 'niche.declared_gene'),
            array_slice($plan, 0, 4),
        ));
        $this->assertSame(['decrease', 'decrease', 'increase', 'increase'], array_map(
            fn (array $seat): mixed => data_get($seat, 'niche.repair_direction'),
            array_slice($plan, 0, 4),
        ));
        $this->assertTrue(collect($plan)->every(fn (array $seat): bool =>
            data_get($seat, 'niche.temporal_mutation_hypothesis.protocol')
                === LabPopulationService::TEMPORAL_STATE_PERSISTENCE_HYPOTHESIS
        ));

        $generation = LabGeneration::create([
            'ai_laboratory_id' => $source->generation->ai_laboratory_id,
            'generation' => 2,
            'trigger_type' => 'candidate_handoff',
            'population_size' => 5,
            'status' => 'draft',
            'trigger_context' => [],
        ]);
        $create = new \ReflectionMethod($service, 'createAgent');
        $create->setAccessible(true);
        foreach ($plan as $index => $seat) {
            $this->assertTrue($create->invoke(
                $service,
                $generation,
                $seat['family'],
                $seat['origin'],
                $index + 1,
                $seat['target'],
                $seat['niche'],
                null,
                $seat['research_group'],
                $index + 1,
            ));
        }

        $children = $generation->agents()->with('modelVersion')->orderBy('id')->get();
        foreach (array_slice($children->all(), 0, 4) as $index => $child) {
            $diff = (array) $child->parameter_diff;
            $this->assertCount(1, $diff);
            $this->assertSame($expectedGenes[$index], array_key_first($diff));
            $this->assertNotSame(
                $anchor->parameter_snapshot[$expectedGenes[$index]],
                $child->modelVersion->parameters[$expectedGenes[$index]],
            );
        }
        $control = $children->last();
        $this->assertSame([], (array) $control->parameter_diff);
        $this->assertSame($anchor->parameter_snapshot, $control->modelVersion->parameters);
    }

    public function test_temporal_gate_movement_opens_one_shadow_state_machine_escape_seat(): void
    {
        [$source] = $this->sourceAgent();
        $anchors = app(FailureRepairAnchorService::class);
        $anchor = $anchors->recordForAgent(
            $source,
            'FAILED_TEMPORAL_CHUNK_SURVIVAL',
            null,
            ['screening_result' => ['profit_factor' => .8, 'total_trades' => 40]],
            true,
        );
        $this->assertNotNull($anchor);

        $descriptor = $anchors->descriptor($anchor);
        $descriptor['policy'] = [
            ...(array) data_get($descriptor, 'policy', []),
            'parameter_attempts' => 1,
            'target_improvements' => 1,
        ];
        $service = app(LabPopulationService::class);
        $method = new \ReflectionMethod($service, 'anchorSiblingPlan');
        $method->setAccessible(true);
        $plan = $method->invoke($service, $source->generation->laboratory, [
            'source_generation_id' => $source->lab_generation_id,
            'cohort_mode' => 'four_siblings_plus_control_v1',
            'repair_anchors' => [$descriptor],
        ], 5);

        $this->assertSame('architecture_escape', data_get($plan[3], 'niche.sibling_kind'));
        $this->assertSame('state_machine_variant', data_get($plan[3], 'niche.declared_gene'));
        $this->assertSame('neutral_transition_cooldown_reentry_v1', data_get($plan[3], 'niche.state_machine_variant'));
        $this->assertTrue((bool) data_get($plan[3], 'niche.shadow_only'));
        $this->assertSame('architecture', data_get($plan[3], 'niche.repair_direction'));

        $generation = LabGeneration::create([
            'ai_laboratory_id' => $source->generation->ai_laboratory_id,
            'generation' => 3,
            'trigger_type' => 'candidate_handoff',
            'population_size' => 5,
            'status' => 'draft',
            'trigger_context' => [],
        ]);
        $create = new \ReflectionMethod($service, 'createAgent');
        $create->setAccessible(true);
        $this->assertTrue($create->invoke(
            $service,
            $generation,
            $plan[3]['family'],
            $plan[3]['origin'],
            4,
            $plan[3]['target'],
            $plan[3]['niche'],
            null,
            $plan[3]['research_group'],
            4,
        ));
        $child = $generation->agents()->with('modelVersion')->firstOrFail();
        $this->assertSame(['state_machine_variant'], array_keys((array) $child->parameter_diff));
        $this->assertSame('neutral_transition_cooldown_reentry_v1', data_get($child->modelVersion->parameters, 'state_machine_variant'));
        $this->assertFalse((bool) data_get($child->modelVersion->metadata, 'promotion_evidence', false));
    }

    public function test_mixed_temporal_profile_reserves_one_train_forward_robustness_seat(): void
    {
        [$source] = $this->sourceAgent();
        $anchors = app(FailureRepairAnchorService::class);
        $anchor = $anchors->recordForAgent(
            $source,
            'FAILED_TEMPORAL_CHUNK_SURVIVAL',
            null,
            ['screening_result' => ['profit_factor' => .8, 'total_trades' => 40]],
            true,
        );
        $this->assertNotNull($anchor);

        $method = new \ReflectionMethod(app(LabPopulationService::class), 'anchorSiblingPlan');
        $method->setAccessible(true);
        $plan = $method->invoke(app(LabPopulationService::class), $source->generation->laboratory, [
            'source_generation_id' => $source->lab_generation_id,
            'cohort_mode' => 'four_siblings_plus_control_v1',
            'target_counts' => ['train_forward_robustness' => 1],
            'failure_specific_lanes_observed' => ['temporal_stability', 'train_forward_robustness'],
            'repair_anchors' => [$anchors->descriptor($anchor)],
        ], 5);

        $this->assertSame('transition_firewall_enabled', data_get($plan[0], 'niche.declared_gene'));
        $this->assertSame('max_loss_streak_before_wait', data_get($plan[1], 'niche.declared_gene'));
        $this->assertSame('loss_cooldown_candles', data_get($plan[2], 'niche.declared_gene'));
        $this->assertSame('confidence_calibration_min_samples', data_get($plan[3], 'niche.declared_gene'));
        $this->assertSame('robustness_split_specialist', data_get($plan[3], 'niche.specialist_role'));
        $this->assertSame('immutable_same_train_forward_split', data_get($plan[3], 'niche.robustness_split_contract'));
        $this->assertSame('train_forward_robustness', data_get($plan[3], 'niche.failure_specific_lane'));
    }

    public function test_screen_pass_creates_seed_stage_and_response_map_without_parent_credit(): void
    {
        [$source] = $this->sourceAgent();
        $anchor = app(FailureRepairAnchorService::class)->recordForAgent(
            $source,
            'FAILED_PROFIT_FACTOR',
            null,
            ['screening_result' => [
                'profit_factor' => .8,
                'total_trades' => 40,
                'data_manifest' => ['sha256' => str_repeat('a', 64)],
                'execution_contract' => ['execution_hash' => str_repeat('b', 64)],
            ]],
            true,
        );
        $this->assertNotNull($anchor);
        $service = app(LabPopulationService::class);
        $planMethod = new \ReflectionMethod($service, 'anchorSiblingPlan');
        $planMethod->setAccessible(true);
        $plan = $planMethod->invoke($service, $source->generation->laboratory, [
            'source_generation_id' => $source->lab_generation_id,
            'repair_anchors' => [app(FailureRepairAnchorService::class)->descriptor($anchor)],
        ], 4);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $source->generation->ai_laboratory_id,
            'generation' => 3,
            'trigger_type' => 'candidate_handoff',
            'population_size' => 1,
            'status' => 'draft',
            'trigger_context' => [],
        ]);
        $create = new \ReflectionMethod($service, 'createAgent');
        $create->setAccessible(true);
        $this->assertTrue($create->invoke($service, $generation, $plan[0]['family'], $plan[0]['origin'], 1, $plan[0]['target'], $plan[0]['niche'], null, $plan[0]['research_group'], 1));
        $child = $generation->agents()->with('modelVersion')->firstOrFail();
        app(SkillMentorService::class)->markScreenValidatedSeed($child, true, ['evidence_run_id' => 'screen-seed-1']);
        $child->refresh()->load('modelVersion');
        $this->assertSame('screen_validated_seed', data_get($child->modelVersion->metadata, 'evolution_stage.stage'));
        $this->assertFalse((bool) data_get($child->modelVersion->metadata, 'evolution_stage.parent_eligible'));

        $map = app(MutationResponseMapService::class)->recordScreening($child, [
            'evidence_run_id' => 'screen-seed-1',
            'data_manifest' => ['sha256' => str_repeat('a', 64)],
            'execution_contract' => ['execution_hash' => str_repeat('b', 64)],
            'profit_factor' => 1.1,
            'total_trades' => 40,
        ]);
        $this->assertNotNull($map);
        $this->assertDatabaseHas('lab_mutation_response_maps', [
            'id' => $map['id'],
            'stage' => 'screening',
            'status' => 'screen_observed',
            'repair_anchor_id' => $anchor->id,
        ]);
    }

    public function test_failed_repair_sibling_stays_on_original_anchor_and_does_not_fork_a_cold_restart(): void
    {
        [$source] = $this->sourceAgent();
        $anchors = app(FailureRepairAnchorService::class);
        $anchor = $anchors->recordForAgent(
            $source,
            'FAILED_PROFIT_FACTOR',
            null,
            ['screening_result' => ['profit_factor' => .8, 'total_trades' => 40]],
            true,
        );
        $this->assertNotNull($anchor);

        $generation = LabGeneration::create([
            'ai_laboratory_id' => $source->generation->ai_laboratory_id,
            'generation' => 4,
            'trigger_type' => 'candidate_handoff',
            'population_size' => 1,
            'status' => 'draft',
            'trigger_context' => [],
        ]);
        $create = new \ReflectionMethod(LabPopulationService::class, 'createAgent');
        $create->setAccessible(true);
        $this->assertTrue($create->invoke(
            app(LabPopulationService::class),
            $generation,
            'differential_router',
            'targeted_failure_profile',
            1,
            'profit_factor',
            [
                'protocol' => LabPopulationService::ANCHOR_SIBLING_PROTOCOL,
                'specialist_role' => 'repair_profit_factor_specialist',
                'failure_target' => 'profit_factor',
                'declared_gene' => 'minimum_signal_confidence',
                'repair_anchor_id' => $anchor->id,
                'sibling_kind' => 'primary_direction',
                'repair_direction' => 'increase',
                'repair_anchor_sibling_cohort_id' => 'cohort-no-fork',
                'repair_anchor_sibling_index' => 1,
            ],
            null,
            'volatility_session_stability',
            1,
        ));
        $child = $generation->agents()->with('modelVersion')->firstOrFail();

        app(CandidateGateDecisionService::class)->recordScreening($child, [
            'profit_factor' => .7,
            'total_trades' => 40,
            'screening_survival' => ['status' => 'survivor'],
        ]);

        $this->assertDatabaseCount('lab_failure_repair_anchors', 1);
        $this->assertSame($anchor->id, (int) $child->fresh('modelVersion')->modelVersion->metadata['repair_anchor']['id']);
    }

    public function test_anchor_escape_requires_three_complete_four_member_cohorts(): void
    {
        [$source] = $this->sourceAgent();
        $anchor = app(FailureRepairAnchorService::class)->recordForAgent(
            $source,
            'FAILED_PROFIT_FACTOR',
            null,
            ['screening_result' => ['profit_factor' => .8, 'total_trades' => 40]],
            true,
        );
        $this->assertNotNull($anchor);

        $screenings = [];
        foreach (['cohort-1', 'cohort-2', 'cohort-3'] as $cohort) {
            foreach (['primary_direction', 'reverse_direction', 'alternative_gene', 'frozen_control'] as $index => $kind) {
                $screenings[] = [
                    'status' => 'confirmed',
                    'sibling_kind' => $kind,
                    'sibling_cohort_id' => $cohort,
                    'child_model_version_id' => 100 + count($screenings),
                    'target_improved' => false,
                ];
            }
        }
        $anchor->update(['evidence' => ['repair_screenings' => $screenings]]);

        $policy = app(FailureRepairAnchorService::class)->policyFor($anchor->fresh());
        $this->assertSame('escape_to_architecture', $policy['action']);
        $this->assertSame(3, $policy['parameter_attempts']);
        $this->assertSame(0, $policy['incomplete_cohorts']);
        $this->assertSame(0, $policy['target_improvements']);
    }

    public function test_role_compatible_skill_mentor_is_applied_to_one_probe_without_becoming_parent(): void
    {
        [$source] = $this->sourceAgent();
        LabMutationResponseMap::create([
            'response_key' => str_repeat('c', 64),
            'stage' => 'full_replay',
            'status' => 'independently_confirmed',
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'differential_router',
            'target' => 'profit_factor',
            'parameter_key' => 'minimum_confidence',
            'direction' => 'decrease',
            'target_delta' => ['improved' => true, 'delta' => .12],
            'metadata' => ['specialist_role' => 'edge_quality_specialist'],
        ]);

        $service = app(LabPopulationService::class);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $source->generation->ai_laboratory_id,
            'generation' => 5,
            'trigger_type' => 'candidate_handoff',
            'population_size' => 1,
            'status' => 'draft',
            'trigger_context' => [],
        ]);
        $create = new \ReflectionMethod($service, 'createAgent');
        $create->setAccessible(true);
        $this->assertTrue($create->invoke(
            $service,
            $generation,
            'differential_router',
            'g98_council',
            1,
            'profit_factor',
            [
                'specialist_role' => 'edge_quality_specialist',
                'role' => 'edge_quality_specialist',
            ],
            null,
            'volatility_session_stability',
            1,
        ));

        $child = $generation->agents()->with('modelVersion')->firstOrFail();
        $this->assertSame('minimum_confidence', array_key_first((array) $child->parameter_diff));
        $this->assertTrue((bool) data_get($child->modelVersion->metadata, 'skill_mentor_input.applied'));
        $this->assertNull($child->parent_a_model_version_id);

        // A different role cannot borrow this mentor's gene.
        $this->assertNull(app(MutationResponseMapService::class)->bestMentor(
            'XAUUSD', 'H1', 'differential_router', 'profit_factor', 'stress_specialist'
        ));
    }

    public function test_seed_becomes_skill_mentor_then_full_parent_only_after_forward_passport(): void
    {
        [$source] = $this->sourceAgent();
        $model = ModelVersion::create([
            'name' => 'mentor-lifecycle-candidate',
            'strategy' => 'xauusd_differential_router',
            'version' => 'v1',
            'generation' => 2,
            'status' => 'testing',
            'parameters' => $source->modelVersion->parameters,
            'metadata' => [
                'generation_target' => 'profit_factor',
                'council_specialist_contract' => ['role' => 'edge_quality_specialist'],
                'causal_experiment_lane' => ['control_only' => false],
                'evolution_stage' => [
                    'protocol' => SkillMentorService::PROTOCOL,
                    'stage' => 'screen_validated_seed',
                    'parent_eligible' => false,
                ],
            ],
            'evidence_status' => 'valid',
        ]);
        $agent = LabAgent::create([
            'lab_generation_id' => $source->lab_generation_id,
            'model_version_id' => $model->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'differential_router',
            'origin' => 'screening_seed',
            'lifecycle_status' => 'screened',
            'parameter_diff' => ['minimum_confidence' => ['old' => .9, 'new' => 1.0]],
        ]);
        $performance = ModelMarketPerformance::create([
            'model_version_id' => $model->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'differential_router',
            'status' => 'challenger',
            'sample_count' => 20,
            'rolling_windows_count' => 1,
            'rolling_forward_wins' => 1,
            'metrics' => ['profit_factor' => 1.2, 'max_drawdown_percent' => 8, 'monte_carlo' => ['risk_of_ruin_percent' => 3]],
            'evidence_status' => 'valid',
        ]);

        $mentor = app(SkillMentorService::class)->recordFullReplayOutcome(
            $agent->fresh(['modelVersion']),
            $performance,
            [
                'evidence_run_id' => 'mentor-full-replay-1',
                'verified_mutation_skill' => ['status' => 'confirmed'],
                'profit_factor' => 1.2,
            ],
        );
        $this->assertSame('skill_mentor', $mentor['stage']);
        $this->assertFalse($mentor['parent_eligible']);
        $this->assertSame('skill_mentor', data_get($agent->fresh('modelVersion')->modelVersion->metadata, 'evolution_stage.stage'));

        app(MutationResponseMapService::class)->recordFullReplay(
            $agent->fresh(['modelVersion']),
            [
                'evidence_run_id' => 'mentor-full-replay-1',
                'verified_mutation_skill' => ['status' => 'confirmed'],
                'profit_factor' => 1.2,
            ],
            $performance,
            ['status' => 'confirmed'],
        );
        $this->assertNotNull(app(MutationResponseMapService::class)->bestMentor(
            'XAUUSD', 'H1', 'differential_router', 'profit_factor', 'edge_quality_specialist'
        ));

        $performance->update([
            'status' => 'forward_validated',
            'sample_count' => 40,
            'rolling_windows_count' => 3,
            'rolling_forward_wins' => 3,
            'metrics' => [
                'profit_factor' => 1.4,
                'max_drawdown_percent' => 8,
                'is_overfit' => false,
                'monte_carlo' => ['risk_of_ruin_percent' => 3],
            ],
        ]);
        $parent = app(SkillMentorService::class)->recordFullReplayOutcome(
            $agent->fresh(['modelVersion']),
            $performance->fresh(),
            [
                'evidence_run_id' => 'mentor-forward-confirmed-1',
                'verified_mutation_skill' => ['status' => 'confirmed'],
                'elite_agent_passport' => ['status' => 'passed'],
                'profit_factor' => 1.4,
            ],
            (object) ['decision' => 'passed'],
        );

        $this->assertSame('full_parent', $parent['stage']);
        $this->assertTrue($parent['parent_eligible']);
        $this->assertSame('full_parent', data_get($agent->fresh('modelVersion')->modelVersion->metadata, 'evolution_stage.stage'));
    }

    /** @return array{0: LabAgent, 1: array<string, mixed>} */
    private function sourceAgent(): array
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD',
            'name' => 'Repair anchor test lab',
            'timeframe' => 'H1',
            'strategy_families' => ['differential_router'],
            'is_active' => true,
            'lifecycle_mode' => 'lighthouse',
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 1,
            'trigger_type' => 'test',
            'population_size' => 1,
            'status' => 'completed',
        ]);
        $parameters = app(StrategyParameterSchemaService::class)->defaults('differential_router');
        $model = ModelVersion::create([
            'name' => 'repair-anchor-source',
            'strategy' => 'xauusd_differential_router',
            'version' => 'v1',
            'generation' => 1,
            'status' => 'testing',
            'parameters' => $parameters,
            'metadata' => [],
            'evidence_status' => 'valid',
        ]);
        $agent = LabAgent::create([
            'lab_generation_id' => $generation->id,
            'model_version_id' => $model->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'differential_router',
            'origin' => 'test',
            'lifecycle_status' => 'rejected',
            'parameter_diff' => ['minimum_confidence' => ['old' => .9, 'new' => 1.0]],
        ]);

        return [$agent->fresh(['modelVersion', 'generation']), $parameters];
    }
}
