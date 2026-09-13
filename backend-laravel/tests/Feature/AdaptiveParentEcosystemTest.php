<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabEvolutionArchiveEntry;
use App\Models\LabGeneration;
use App\Models\LabParentSelectionDecision;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Services\AdaptiveParentFrontierService;
use App\Services\ContextContractV2Service;
use App\Services\ContextualCausalTraitCapsuleService;
use App\Services\EvolutionArchiveService;
use App\Services\EvolutionaryAuthorityFoundryService;
use App\Services\EvolutionGovernorService;
use App\Services\LabPopulationService;
use App\Services\ParentContextTrustService;
use App\Services\StrategyParameterSchemaService;
use App\Services\StrategySemanticGroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdaptiveParentEcosystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_robust_lane_can_use_multiple_diverse_parents_while_causal_lane_stays_single_parent(): void
    {
        $parents = collect();
        for ($index = 1; $index <= 5; $index++) {
            $model = $this->makeModel('robust-parent-'.$index, $index);
            $this->makePerformance($model, 70 + $index);
            $parents->push($model);
        }

        $service = app(AdaptiveParentFrontierService::class);
        $robust = $service->select(
            $parents,
            'XAUUSD', 'H1', 'trend', 'robust_crossover', 'robustness',
            ['role' => 'general'], 1,
        );
        $causal = $service->select(
            $parents,
            'XAUUSD', 'H1', 'trend', 'gate_targeted', 'exit_topology',
            ['role' => 'general'], 1,
        );

        $this->assertGreaterThanOrEqual(2, count($robust['selected_parent_ids']));
        $this->assertLessThanOrEqual(5, count($robust['selected_parent_ids']));
        $this->assertCount(1, $causal['selected_parent_ids']);
        $this->assertFalse($robust['contract']['promotion_evidence']);
        $this->assertSame('robust_capability_crossover', $robust['contract']['mode']);
        $this->assertArrayHasKey('parameter_sources', $robust['capability_genome']);
    }

    public function test_unproven_research_seed_cannot_become_a_genetic_parent(): void
    {
        $validated = $this->makeModel('validated-parent', 1);
        $this->makePerformance($validated, 80);
        $researchSeed = $this->makeModel('unproven-research-seed', 2);

        $selection = app(AdaptiveParentFrontierService::class)->select(
            collect([$validated, $researchSeed]),
            'XAUUSD', 'H1', 'trend', 'robust_crossover', 'robustness',
            ['role' => 'general'], 1,
        );

        $this->assertSame([$validated->id], $selection['selected_parent_ids']);
        $this->assertSame(2, $selection['contract']['candidate_count']);
        $this->assertSame(1, $selection['contract']['eligible_candidate_count']);
        $this->assertSame(1, $selection['contract']['research_seed_candidate_count']);
        $this->assertFalse($selection['contract']['research_seed_only']);
    }

    public function test_exact_cell_frontier_is_not_silently_truncated_before_dynamic_selection(): void
    {
        $parents = collect();
        for ($index = 1; $index <= 30; $index++) {
            $model = $this->makeModel('wide-frontier-parent-'.$index, $index);
            $this->makePerformance($model, 70 + $index);
            $parents->push($model);
        }

        $selection = app(AdaptiveParentFrontierService::class)->select(
            $parents,
            'XAUUSD', 'H1', 'trend', 'robust_crossover', 'robustness',
            ['role' => 'general'], 1,
        );

        $this->assertSame(30, $selection['contract']['candidate_count']);
        $this->assertSame(30, $selection['contract']['eligible_candidate_count']);
        $this->assertGreaterThanOrEqual(3, $selection['contract']['selected_count']);
        $this->assertLessThanOrEqual(5, $selection['contract']['selected_count']);
        $this->assertSame(30, count($selection['contract']['candidate_parent_model_version_ids']));
    }

    public function test_new_execution_gene_receives_extension_module_provenance(): void
    {
        $parents = collect();
        for ($index = 1; $index <= 3; $index++) {
            $model = $this->makeModel('extension-parent-'.$index, $index);
            if ($index === 3) {
                $model->update(['parameters' => [...$model->parameters, 'volume_lane' => 'none']]);
            }
            $this->makePerformance($model, 70 + $index);
            $parents->push($model->fresh());
        }

        $selection = app(AdaptiveParentFrontierService::class)->select(
            $parents,
            'XAUUSD', 'H1', 'trend', 'robust_crossover', 'robustness',
            ['role' => 'general'], 1,
        );

        $this->assertContains('extension:volume_lane', $selection['capability_genome']['dynamic_extension_modules']);
        $this->assertArrayHasKey('volume_lane', $selection['capability_genome']['parameter_sources']);
        $this->assertNotEmpty($selection['capability_genome']['modules']['extension:volume_lane']['contributors']);
    }

    public function test_regime_ensemble_targeted_repair_keeps_causal_policy_identity(): void
    {
        $policy = app(EvolutionGovernorService::class)->selectionPolicy(
            'regime_ensemble', 'gate_targeted', 'monthly_survival', [
                'exploration_ratio' => .8,
                'diversity_score' => .1,
                'progress_score' => .2,
            ],
        );

        $this->assertSame('causal_single_parent', $policy['mode']);
        $this->assertTrue($policy['causal_lane']);
        $this->assertSame(1, $policy['max_parents']);
    }

    public function test_disabling_adaptive_scoring_does_not_break_causal_single_parent_attribution(): void
    {
        $parents = collect();
        for ($index = 1; $index <= 3; $index++) {
            $model = $this->makeModel('legacy-parent-'.$index, $index);
            $this->makePerformance($model, 70 + $index);
            $parents->push($model);
        }

        $previous = config('services.lab_selection.adaptive_parent_enabled');
        config(['services.lab_selection.adaptive_parent_enabled' => false]);
        try {
            $selection = app(AdaptiveParentFrontierService::class)->select(
                $parents,
                'XAUUSD', 'H1', 'trend', 'gate_targeted', 'exit_topology',
                ['role' => 'general'], 1,
            );
        } finally {
            config(['services.lab_selection.adaptive_parent_enabled' => $previous]);
        }

        $this->assertCount(1, $selection['selected_parent_ids']);
        $this->assertSame(3, $selection['contract']['candidate_count']);
        $this->assertTrue((bool) $selection['contract']['causal_lane']);
    }

    public function test_configured_population_budget_adds_real_exploration_seats(): void
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD', 'name' => 'XAUUSD Budget Lab', 'timeframe' => 'H1',
            'strategy_families' => ['trend', 'breakout'], 'is_active' => true,
        ]);
        $previous = config('services.lab_selection.population_size');
        config(['services.lab_selection.population_size' => 25]);
        try {
            $method = new \ReflectionMethod(LabPopulationService::class, 'generationPlan');
            $method->setAccessible(true);
            $plan = $method->invoke(app(LabPopulationService::class), $lab);
        } finally {
            config(['services.lab_selection.population_size' => $previous]);
        }

        $this->assertCount(25, $plan);
        $this->assertContains('robust_crossover', array_column($plan, 'origin'));
        $this->assertContains('architecture', array_column($plan, 'origin'));
        $this->assertContains('curiosity_probe', array_column($plan, 'origin'));
    }

    public function test_confirmed_mentor_becomes_selectable_after_foundry_grants_parent_authority(): void
    {
        $mentor = $this->makeModel('authority-transitioned-mentor', 31);
        $niche = ['role' => 'general', 'regime' => 'trend_up', 'volatility' => 'normal', 'session' => 'london'];
        $mentor->update(['metadata' => [
            ...((array) $mentor->metadata),
            'skill_mentor' => ['status' => 'confirmed', 'parameter_key' => 'ema_fast', 'target' => 'profit_factor'],
            'evolution_stage' => ['stage' => 'skill_mentor'],
            'semantic_group' => app(StrategySemanticGroupService::class)->descriptor('XAUUSD', 'H1', 'trend', $niche),
        ]]);
        $this->makePerformance($mentor->fresh(), 91);

        // A confirmed label plus an eligible_parent string is not authority.
        // The selector must fail closed until the contextual capsule and its
        // exact-context descendant trust exist.
        $unsealed = app(AdaptiveParentFrontierService::class)->select(
            collect([$mentor->fresh()]),
            'XAUUSD', 'H1', 'trend', 'gate_targeted', 'profit_factor',
            $niche, 1,
        );
        $this->assertSame([], $unsealed['selected_parent_ids']);
        $this->assertContains(
            'rejected_trait_capsule_context',
            (array) data_get($unsealed, 'contract.candidate_scores.'.$mentor->id.'.parent_selection_reasons'),
        );

        $this->attachContextualMentorAuthority($mentor->fresh(), $niche);
        $selection = app(AdaptiveParentFrontierService::class)->select(
            collect([$mentor->fresh()]),
            'XAUUSD', 'H1', 'trend', 'gate_targeted', 'profit_factor',
            $niche, 1,
        );

        $this->assertSame([$mentor->id], $selection['selected_parent_ids']);
        $this->assertSame(1, $selection['contract']['eligible_candidate_count']);
        $this->assertTrue(data_get($selection, 'contract.candidate_scores.'.$mentor->id.'.mentor_authority_transitioned', false));
        $this->assertTrue(data_get($selection, 'contract.candidate_scores.'.$mentor->id.'.trait_capsule_assessment.valid', false));
        $this->assertSame(
            data_get($selection, 'contract.selected_contextual_trait_capsules.'.$mentor->id.'.capsule_hash'),
            data_get($selection, 'capability_genome.parameter_sources.ema_fast.contextual_trait_capsule.capsule_hash'),
        );

        $broadCell = [...$niche];
        unset($broadCell['session']);
        $guarded = app(AdaptiveParentFrontierService::class)->select(
            collect([$mentor->fresh()]), 'XAUUSD', 'H1', 'trend', 'gate_targeted', 'profit_factor', $broadCell, 1,
        );
        $this->assertSame([$mentor->id], $guarded['selected_parent_ids']);
        $this->assertSame(
            'routing_match_runtime_guard_required',
            data_get($guarded, 'contract.candidate_scores.'.$mentor->id.'.trait_capsule_assessment.context_compatibility.status'),
        );

        $wrongSession = [...$niche, 'session' => 'asia'];
        $abstained = app(AdaptiveParentFrontierService::class)->select(
            collect([$mentor->fresh()]), 'XAUUSD', 'H1', 'trend', 'gate_targeted', 'profit_factor', $wrongSession, 1,
        );
        $this->assertSame([], $abstained['selected_parent_ids']);
        $this->assertContains(
            'rejected_trait_capsule_context',
            (array) data_get($abstained, 'contract.candidate_scores.'.$mentor->id.'.parent_selection_reasons'),
        );
    }

    public function test_architecture_lane_uses_dynamic_capability_parents_with_gene_hashes(): void
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD', 'name' => 'XAUUSD Architecture Lab', 'timeframe' => 'H1',
            'strategy_families' => ['trend'], 'is_active' => true,
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 2, 'trigger_type' => 'test',
            'population_size' => 5, 'status' => 'draft', 'trigger_context' => [
                'adaptive_evolution_policy' => [
                    'observed_generations' => [1],
                    'exploration_ratio' => .80,
                    'diversity_collapse' => true,
                    'parent_concentration' => .75,
                    'lineage_cap' => .50,
                ],
            ],
        ]);
        $parents = collect();
        for ($index = 1; $index <= 5; $index++) {
            $parent = $this->makeModel('architecture-parent-'.$index, $index);
            $this->makePerformance($parent, 80 + $index);
            $parents->push($parent);
        }

        $selection = app(AdaptiveParentFrontierService::class)->select(
            $parents,
            'XAUUSD', 'H1', 'trend', 'architecture', 'architecture',
            ['role' => 'general'], 1, $generation,
        );

        $this->assertSame('architecture_discovery', $selection['contract']['mode']);
        $this->assertGreaterThanOrEqual(2, $selection['contract']['dynamic_k']);
        $this->assertTrue(collect($selection['capability_genome']['parameter_sources'])
            ->every(fn (array $source): bool => filled($source['source_parent_id'])
                && filled($source['source_module'])
                && filled($source['parameter_hash'])));
    }

    public function test_archive_preserves_failure_evidence_but_never_reintroduces_failure_as_parent(): void
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD', 'name' => 'XAUUSD Lab', 'timeframe' => 'H1',
            'strategy_families' => ['trend'], 'is_active' => true,
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'test',
            'population_size' => 3, 'status' => 'completed', 'trigger_context' => [],
        ]);
        $good = $this->makeModel('archive-good', 1);
        $young = $this->makeModel('archive-young', 2);
        $failed = $this->makeModel('archive-failed', 3);
        $this->makePerformance($good, 82);
        $failedAgent = LabAgent::create([
            'lab_generation_id' => $generation->id,
            'model_version_id' => $failed->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'trend',
            'origin' => 'gate_targeted', 'lifecycle_status' => 'rejected',
            'decision_reason' => 'failed forward gate',
        ]);

        app(EvolutionArchiveService::class)->sync(
            $generation,
            collect([$good, $young]),
            collect([$good, $young]),
            'XAUUSD', 'H1', 'trend', 'curiosity_probe', 'unknown_state_curiosity',
            ['role' => 'general'],
        );

        $this->assertDatabaseHas('lab_evolution_archive_entries', [
            'model_version_id' => $good->id, 'archive_type' => 'convergence',
        ]);
        $this->assertDatabaseHas('lab_evolution_archive_entries', [
            'model_version_id' => $young->id, 'archive_type' => 'young',
        ]);
        $this->assertDatabaseHas('lab_evolution_archive_entries', [
            'model_version_id' => $failed->id, 'archive_type' => 'failure',
        ]);

        $frontier = app(EvolutionArchiveService::class)->augmentFrontier(
            collect(), 'XAUUSD', 'H1', 'trend', 'curiosity_probe', 'unknown_state_curiosity',
            ['role' => 'general'],
        );

        // Maintenance from a different candidate cell must not copy the same
        // immutable failed model into that caller's island.
        app(EvolutionArchiveService::class)->augmentFrontier(
            collect(), 'XAUUSD', 'H1', 'trend', 'curiosity_probe', 'unknown_state_curiosity',
            ['role' => 'general', 'regime' => 'trend_down', 'volatility' => 'high'],
        );

        $this->assertNotContains($failed->id, $frontier->pluck('id')->all());
        $this->assertSame(1, LabEvolutionArchiveEntry::query()
            ->where('model_version_id', $failed->id)
            ->where('archive_type', 'failure')
            ->count());
        $this->assertNotNull($failedAgent->fresh());
    }

    public function test_runtime_policy_waits_on_unknown_or_disagreeing_regimes(): void
    {
        $policy = app(EvolutionGovernorService::class)
            ->runtimePolicy('regime_ensemble', [11, 12, 13]);

        $this->assertSame('WAIT', $policy['unknown_regime_action']);
        $this->assertSame('WAIT', $policy['specialist_disagreement_action']);
        $this->assertTrue($policy['paper_and_holdout_required']);
        $this->assertFalse($policy['promotion_evidence']);
    }

    public function test_population_constructor_persists_dynamic_parent_decision_and_graph(): void
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD', 'name' => 'XAUUSD Lab', 'timeframe' => 'H1',
            'strategy_families' => ['trend'], 'is_active' => true,
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'test',
            'population_size' => 1, 'status' => 'draft', 'trigger_context' => [
                'adaptive_evolution_policy' => app(EvolutionGovernorService::class)
                    ->scopeSnapshot('XAUUSD', 'H1'),
            ],
        ]);
        $parents = collect();
        for ($index = 1; $index <= 5; $index++) {
            $parent = $this->makeModel('constructor-parent-'.$index, $index);
            $this->makePerformance($parent, 75 + $index);
            $parents->push($parent);
        }

        $method = new \ReflectionMethod(LabPopulationService::class, 'createAgent');
        $method->setAccessible(true);
        $created = $method->invoke(
            app(LabPopulationService::class),
            $generation, 'trend', 'robust_crossover', 1, 'robustness', null, null,
        );
        $this->assertTrue($created);

        $agent = LabAgent::query()->latest('id')->firstOrFail();
        $decision = LabParentSelectionDecision::query()->where('lab_agent_id', $agent->id)->firstOrFail();
        $this->assertSame('robust_capability_crossover', $decision->mode);
        $this->assertGreaterThanOrEqual(2, $decision->selected_count);
        $this->assertFalse($decision->promotion_evidence);
        $this->assertGreaterThanOrEqual(2, $agent->parentLinks()->count());
        $this->assertSame(
            $decision->selected_parent_model_version_ids,
            (array) data_get($agent->modelVersion->metadata, 'adaptive_parent_ecosystem.selected_parent_model_version_ids'),
        );
    }

    public function test_governor_expands_later_generation_budget_without_erasing_causal_floor(): void
    {
        $plan = collect(range(1, 20))->map(fn (int $slot): array => [
            'origin' => 'g98_council',
            'target' => 'monthly_survival',
            'niche' => ['role' => 'general'],
            'slot' => $slot,
        ])->all();

        $snapshot = [
            'lookback_generations' => 2,
            'observed_generations' => [1, 2],
            'exploration_ratio' => .75,
            'diversity_collapse' => true,
            'parent_concentration' => .80,
            'stagnation_generations' => 3,
            'market_drift' => ['status' => 'recheck_required'],
        ];
        $adapted = app(EvolutionGovernorService::class)->adaptPlan($plan, $snapshot);

        $this->assertNotSame($plan, $adapted);
        $this->assertSame(
            array_column(array_slice($plan, 0, 8), 'origin'),
            array_column(array_slice($adapted, 0, 8), 'origin'),
        );
        $this->assertContains('robust_crossover', array_column($adapted, 'origin'));
        $this->assertContains('architecture', array_column($adapted, 'origin'));
        $this->assertTrue((bool) data_get($adapted[15], 'niche.research_only_until_independent_replay'));
        $this->assertFalse((bool) data_get($adapted[15], 'adaptive_governor.promotion_evidence'));
    }

    private function makeModel(string $name, int $variant): ModelVersion
    {
        $schema = app(StrategyParameterSchemaService::class);
        $parameters = $schema->defaults('trend');
        $parameters['ema_fast'] = 20 + $variant;
        $groups = app(StrategySemanticGroupService::class);

        return ModelVersion::create([
            'name' => $name,
            'strategy' => 'xauusd_'.$name,
            'version' => 'v1',
            'generation' => 1,
            'status' => 'testing',
            'best_score' => 60 + $variant,
            'parameters' => $parameters,
            'metadata' => [
                'lab_symbol' => 'XAUUSD', 'lab_timeframe' => 'H1',
                'strategy_architecture' => 'trend_pullback',
                'semantic_group' => $groups->descriptor('XAUUSD', 'H1', 'trend', ['role' => 'general']),
            ],
            'evidence_status' => 'valid',
        ]);
    }

    private function makePerformance(ModelVersion $model, float $forward): void
    {
        ModelMarketPerformance::create([
            'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'trend',
            'status' => 'challenger', 'evidence_status' => 'valid', 'forward_score' => $forward,
            'sample_count' => 40, 'rolling_windows_count' => 4, 'rolling_forward_wins' => 4,
            'metrics' => [
                'profit_factor' => 1.5, 'max_drawdown_percent' => 8, 'is_overfit' => false,
                'monte_carlo' => ['risk_of_ruin_percent' => 0],
                'statistical_evidence' => ['edge_quality' => [
                    'bootstrap_pf' => ['status' => 'assessed', 'pf_5_percentile_lower_bound' => 1.1],
                    'worst_regime_sampled' => true, 'worst_regime_pf' => 1.1,
                ]],
                'behavioral_diversity' => ['status' => 'distinct'],
            ],
        ]);
        // Parent selection now requires prospective Foundry authority in
        // addition to a challenger performance. This fixture explicitly
        // represents a model that already completed that separate ladder;
        // tests without makePerformance remain research-only as intended.
        DB::table('evolutionary_authority_ledgers')->insert([
            'authority_key' => hash('sha256', 'adaptive-parent-test|'.$model->id),
            'model_version_id' => $model->id, 'lab_agent_id' => null,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'trend',
            'authority_stage' => 'eligible_parent', 'status' => 'passed',
            'data_hash' => str_repeat('a', 64), 'execution_hash' => str_repeat('b', 64),
            'evidence' => json_encode([
                'protocol' => 'evolutionary_authority_foundry_v1',
                'fixture' => true,
                'research_mentor_authority' => [
                    'tier' => 'research_mentor', 'eligible' => true, 'parent_eligible' => false,
                ],
                'economic_parent_authority' => [
                    'tier' => 'economic_parent', 'eligible' => true, 'parent_eligible' => true,
                    'checks' => [
                        'screening_passed' => true, 'full_replay_passed' => true,
                        'positive_absolute_settlement' => true, 'forward_or_paper_evidence' => true,
                        'performance_credit_earned' => true, 'two_improving_descendants' => true,
                        'two_inheritance_credits_earned' => true, 'context_trust_confirmed' => true,
                    ],
                ],
                'promotion_evidence' => false,
            ]),
            'evaluated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param array<string,mixed> $niche */
    private function attachContextualMentorAuthority(ModelVersion $mentor, array $niche): void
    {
        $context = app(ContextContractV2Service::class)->project($niche);
        $core = [
            'protocol' => ContextualCausalTraitCapsuleService::PROTOCOL,
            'hash_protocol' => ContextualCausalTraitCapsuleService::HASH_PROTOCOL,
            'trait' => [
                'gene' => 'ema_fast', 'old_value' => 50, 'tested_value' => 51,
                'direction' => 'increase', 'executable' => true,
            ],
            'instrument_bundle' => [
                'status' => 'exact_bundle_attested', 'playbook_key' => 'test-trend-playbook',
                'primary_instrument_key' => 'trend_pullback',
                'instrument_keys' => ['trend_pullback', 'atr_risk_envelope', 'cost_aware_exit'],
                'bundle_hash' => hash('sha256', 'test-trend-bundle'),
                'assignment_hash' => hash('sha256', 'test-assignment'),
                'runtime_trace_hash' => hash('sha256', 'test-trace'),
                'powered_context_slices' => 3,
                'credit_scope' => 'atomic_bundle_only_until_factorial_component_ablation',
                'component_synergy_claimed' => false,
            ],
            'activation_context' => [
                'protocol' => ContextContractV2Service::PROTOCOL, 'status' => 'valid',
                'predicate' => array_filter((array) data_get($context, 'extended_axes', []), fn ($value): bool => $value !== null && $value !== ''),
                'context_hash' => data_get($context, 'identity_hash'),
            ],
            'scope' => ['symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'trend'],
            'frozen_dependencies' => [
                'pair_id' => 1, 'causal_baseline_agent_id' => 1, 'causal_baseline_model_version_id' => 1,
                'source_agent_id' => 1, 'source_model_version_id' => $mentor->id,
                'data_hash' => str_repeat('a', 64), 'execution_hash' => str_repeat('b', 64),
            ],
        ];
        $capsule = [
            ...$core, 'status' => 'sealed', 'capsule_hash' => $this->canonicalHash($core),
            'target_effect_vector' => ['target' => 'profit_factor', 'mean_delta' => .2, 'total_windows' => 3],
            'non_target_effect_vector' => ['non_target_regression' => false],
            'contraindicated_contexts' => [],
            'support' => ['revision' => 3, 'component_status' => 'component_confirmed',
                'receipt_ids' => [1, 2, 3], 'response_map_ids' => [1, 2, 3], 'independent_windows' => 3],
            'authority_level' => 'causally_confirmed_component',
            'expiry' => ['policy' => 'revalidate_on_data_execution_drift_or_context_contraindication'],
            'promotion_evidence' => false,
        ];
        $this->assertTrue(app(ContextualCausalTraitCapsuleService::class)->assess($capsule, 'ema_fast', $niche)['valid']);

        DB::table('evolutionary_authority_ledgers')->where('model_version_id', $mentor->id)->update([
            'evidence' => json_encode([
                'protocol' => EvolutionaryAuthorityFoundryService::PROTOCOL,
                'trait_capsule_valid' => true, 'trait_capsule' => $capsule,
                'incubation_passed' => true, 'descendant_improving_children' => 2,
                'context_trust_confirmed' => true,
                'research_mentor_authority' => [
                    'tier' => 'research_mentor', 'eligible' => true, 'parent_eligible' => false,
                ],
                'economic_parent_authority' => [
                    'tier' => 'economic_parent', 'eligible' => true, 'parent_eligible' => true,
                    'checks' => [
                        'screening_passed' => true, 'full_replay_passed' => true,
                        'positive_absolute_settlement' => true, 'forward_or_paper_evidence' => true,
                        'performance_credit_earned' => true, 'two_improving_descendants' => true,
                        'two_inheritance_credits_earned' => true, 'context_trust_confirmed' => true,
                    ],
                ],
                'promotion_evidence' => false,
            ]),
            'updated_at' => now(),
        ]);
        $trust = app(ParentContextTrustService::class);
        $trust->record($mentor, 'XAUUSD', 'H1', 'trend', 'ema_fast', $niche, 'positive', .1,
            ['evidence_run_id' => 'adaptive-mentor-descendant-a', 'counterfactual_status' => 'matched_trait_ablation_positive']);
        $trust->record($mentor, 'XAUUSD', 'H1', 'trend', 'ema_fast', $niche, 'positive', .2,
            ['evidence_run_id' => 'adaptive-mentor-descendant-b', 'counterfactual_status' => 'matched_trait_ablation_positive']);
    }

    private function canonicalHash(mixed $value): string
    {
        $canonicalize = function (mixed $item) use (&$canonicalize): mixed {
            if (is_int($item) || is_float($item)) {
                $number = rtrim(rtrim(sprintf('%.14F', (float) $item), '0'), '.');

                return 'number:'.($number === '-0' || $number === '' ? '0' : $number);
            }
            if (! is_array($item)) {
                return $item;
            }
            foreach ($item as $key => $child) {
                $item[$key] = $canonicalize($child);
            }
            if (! array_is_list($item)) {
                ksort($item);
            }

            return $item;
        };

        return hash('sha256', json_encode($canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }
}
