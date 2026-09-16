<?php

namespace Tests\Feature;

use App\Console\Commands\RecoverLabEvaluationErrors;
use App\Console\Commands\RecoverLabFullEvaluationErrors;
use App\Jobs\EvaluateLabAgentJob;
use App\Models\AgentLearningCausalExperiment;
use App\Models\AgentLearningLesson;
use App\Models\AgentLearningMutationIntent;
use App\Models\AgentLearningPolicy;
use App\Models\AgentLearningRetrieval;
use App\Models\AgentLearningSettlement;
use App\Models\AiLaboratory;
use App\Models\CandidateGateDecision;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\LabLearningLanePair;
use App\Models\LabMutationResponseMap;
use App\Models\LabSkillZooEntry;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Models\MutationMemory;
use App\Models\SystemEvent;
use App\Services\CanonicalSkillCartridgeService;
use App\Services\CausalBlindedMutationSelectorService;
use App\Services\CausalLearningCohortPlannerService;
use App\Services\CausalLearningCohortService;
use App\Services\CausalLearningConfirmationService;
use App\Services\CausalLearningMutationIntentService;
use App\Services\CausalLessonAdmissionService;
use App\Services\CausalParameterActivationService;
use App\Services\CausalRepairFrontierService;
use App\Services\CausalScreeningBehaviorPreflightService;
use App\Services\DependencyAwareEdgeGenesisFoundryService;
use App\Services\EvidenceSalvageConveyorService;
use App\Services\FrozenControlScreeningAdmissionService;
use App\Services\LabAgentEvaluationService;
use App\Services\LabAgentPreflightService;
use App\Services\LabImmutableEvidenceService;
use App\Services\LabPopulationService;
use App\Services\LabQueueJobInspector;
use App\Services\LabReplayRecoveryService;
use App\Services\LearningKernelService;
use App\Services\LearningPulseService;
use App\Services\LearningReceiptService;
use App\Services\MarketChampionService;
use App\Services\MultiModalLearningPortfolioService;
use App\Services\StrategyParameterSchemaService;
use App\Services\StrategySemanticGroupService;
use App\Services\TechnicalFailureClassifierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CausalLearningLoopContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_terminal_technical_arm_closes_triplet_without_causal_or_performance_authority(): void
    {
        [$generation] = $this->canonicalSource();
        $generation->update(['trigger_type' => 'learning_confirmation', 'status' => 'technical_quarantine']);
        $guided = $this->agent($generation, $this->model('technical-guided', []), []);
        $blinded = $this->agent($generation, $this->model('technical-blinded', []), []);
        $control = $this->agent($generation, $this->model('technical-control', []), []);
        $guided->update(['lifecycle_status' => 'rejected']);
        $blinded->update(['lifecycle_status' => 'technical_quarantine']);
        $control->update(['lifecycle_status' => 'rejected']);
        $this->terminalRun($guided, 'technical-guided-full', 'full_validation');
        LabEvaluationRun::create([
            'run_id' => 'technical-blinded-full',
            'lab_generation_id' => $generation->id,
            'lab_agent_id' => $blinded->id,
            'model_version_id' => $blinded->model_version_id,
            'phase' => 'full_validation',
            'mode' => 'replay',
            'status' => 'technical_error',
            'started_at' => now()->subSecond(),
            'finished_at' => now(),
            'error_message' => 'Bounded evaluator transport failure; strategy verdict withheld.',
        ]);
        $this->terminalRun($control, 'technical-control-full', 'full_validation');
        $experiment = AgentLearningCausalExperiment::create([
            'experiment_key' => hash('sha512', 'terminal-technical-triplet'),
            'lab_generation_id' => $generation->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'hybrid',
            'target' => 'profit_factor',
            'gene_key' => 'entry_threshold',
            'guided_agent_id' => $guided->id,
            'blinded_agent_id' => $blinded->id,
            'control_agent_id' => $control->id,
            'status' => 'outcomes_pending',
            'evidence' => [
                'construction_validation' => ['status' => 'ready_for_replay'],
                'promotion_evidence' => false,
            ],
        ]);

        $service = app(CausalLearningCohortService::class);
        $preview = $service->technicalTerminalDisposition($experiment);
        $settled = $service->technicalTerminalDisposition($experiment, true);

        $this->assertTrue($preview['eligible']);
        $this->assertSame('would_settle_technical_quarantine', $preview['status']);
        $this->assertSame('technical_quarantine', $settled['status']);
        $this->assertSame('technical_quarantine', $experiment->fresh()->status);
        $this->assertSame(
            'COUNTERFACTUAL_ARM_TECHNICAL_FAILURE',
            data_get($experiment->fresh()->evidence, 'terminal_disposition.reason_code'),
        );
        $this->assertFalse((bool) data_get($experiment->fresh()->evidence, 'terminal_disposition.causal_credit', true));
        $this->assertFalse((bool) data_get($experiment->fresh()->evidence, 'terminal_disposition.performance_credit', true));
        $this->assertFalse((bool) data_get($experiment->fresh()->evidence, 'terminal_disposition.inheritance_credit', true));
        $this->assertNull($experiment->fresh()->confirmed_at);
    }

    public function test_hypothesis_is_a_first_class_guided_replay_role_but_malformed_multiple_guides_fail_closed(): void
    {
        $service = app(CausalLearningCohortService::class);

        $this->assertContains('hypothesis_guided', CausalLearningCohortService::GUIDED_ROLES);
        $this->assertContains('hypothesis_guided', CausalLearningCohortService::COUNTERFACTUAL_ROLES);
        $this->assertSame(
            'hypothesis_guided',
            $service->resolveGuidedRole(['hypothesis_guided', 'blinded', 'frozen_control']),
        );
        $this->assertNull($service->resolveGuidedRole(['memory_guided', 'hypothesis_guided', 'blinded', 'frozen_control']));
        $this->assertNull($service->resolveGuidedRole(['blinded', 'frozen_control']));
    }

    public function test_pre_registered_learning_method_replaces_blind_independent_fallback_and_is_sealed(): void
    {
        [$generation] = $this->canonicalSource();
        $method = [
            'protocol' => MultiModalLearningPortfolioService::PROTOCOL,
            'learning_method' => 'bayesian_active_learning',
            'requires_exact_frozen_control' => true,
            'research_nursery_only' => true,
            'source_reference_required' => false,
            'source_reference' => null,
            'selection_receipt' => [
                'receipt_hash' => hash('sha256', 'bayesian-active-test'),
                'selected_before_mutation' => true,
                'promotion_evidence' => false,
            ],
            'promotion_evidence' => false,
        ];
        $diff = ['entry_threshold' => ['old' => 1, 'new' => 2]];
        $service = app(CausalLearningMutationIntentService::class);
        $plan = $service->plan(
            $generation->fresh('laboratory'),
            ['positive_lessons' => [], 'harmful_lessons' => [], 'uncertainty_lessons' => []],
            'hybrid', 'profit_factor', ['entry_threshold' => 1], ['entry_threshold' => 2],
            $diff, null, null, null, $method,
        );

        $this->assertSame('portfolio_bayesian_active_learning', $plan['influence_type']);
        $this->assertSame('pre_sealed', data_get($plan, 'learning_method_contract.consumption_receipt.status'));
        $this->assertTrue(data_get($plan, 'learning_method_contract.consumption_receipt.selected_before_mutation'));
        $model = $this->model('portfolio-guided-child', ['entry_threshold' => 2]);
        $intent = $service->seal($plan, $generation, $model);
        $this->assertInstanceOf(AgentLearningMutationIntent::class, $intent);
        $this->assertSame('portfolio_bayesian_active_learning', $intent->influence_type);
        $this->assertSame(
            $method['selection_receipt']['receipt_hash'],
            data_get($intent->metadata, 'learning_method_contract.selection_receipt.receipt_hash'),
        );
        $candidate = $this->agent($generation, $model, $diff);
        $service->bind($intent, $candidate);
        app(LearningReceiptService::class)->issue($candidate->fresh('modelVersion'));
        $controlModel = $this->model('portfolio-method-control', ['entry_threshold' => 1]);
        $control = $this->agent($generation, $controlModel, []);
        $pair = $this->pair($generation, $candidate, $control, .2, 'portfolio-method-settlement');
        $settled = app(LearningReceiptService::class)->settle($candidate->fresh('modelVersion'), [
            'evidence_run_id' => 'portfolio-method-run',
            'mutation_observability' => [
                'observable_effect' => true,
                'target' => 'profit_factor',
                'non_target_regression' => ['safe' => true, 'failed' => false],
            ],
        ], $pair);

        $this->assertSame('provisional', $settled['status']);
        $this->assertSame(
            'settled_back_to_source_receipt',
            data_get($settled, 'learning_method_settlement.status'),
        );
        $this->assertSame(
            'settled',
            data_get($intent->fresh()->metadata, 'learning_method_contract.consumption_receipt.status'),
        );
        $this->assertFalse(data_get($settled, 'learning_method_settlement.promotion_evidence'));
    }

    public function test_evidence_dependent_learning_method_fails_closed_without_source_reference(): void
    {
        [$generation] = $this->canonicalSource();
        $method = [
            'protocol' => MultiModalLearningPortfolioService::PROTOCOL,
            'learning_method' => 'positive_skill_replication',
            'requires_exact_frozen_control' => true,
            'research_nursery_only' => true,
            'source_reference_required' => true,
            'source_reference' => null,
            'selection_receipt' => [
                'receipt_hash' => hash('sha256', 'missing-positive-source'),
                'selected_before_mutation' => true,
            ],
            'promotion_evidence' => false,
        ];
        $service = app(CausalLearningMutationIntentService::class);
        $plan = $service->plan(
            $generation->fresh('laboratory'), [], 'hybrid', 'profit_factor',
            ['entry_threshold' => 1], ['entry_threshold' => 2],
            ['entry_threshold' => ['old' => 1, 'new' => 2]], null, null, null, $method,
        );
        $blocked = $service->seal($plan, $generation, $this->model('missing-source-child', ['entry_threshold' => 2]));

        $this->assertIsArray($blocked);
        $this->assertSame('blocked', $blocked['status']);
        $this->assertSame('LEARNING_METHOD_SOURCE_REFERENCE_MISSING', $blocked['reason']);
    }

    public function test_causal_triplet_is_reserved_outside_the_seventeen_seat_discovery_diversity_budget(): void
    {
        $lab = AiLaboratory::create([
            'name' => 'Causal diversity laboratory',
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_families' => ['hybrid'],
            'is_active' => true,
            'lifecycle_mode' => 'lighthouse',
        ]);
        $plan = collect(range(1, 20))->map(fn (int $slot): array => [
            'family' => 'hybrid',
            'origin' => $slot <= 3 ? 'causal_confirm' : 'discovery',
            'target' => 'regime_coverage',
            'niche' => $slot <= 3
                ? ['causal_confirmation_source_lesson_id' => 2387, 'control_only' => false]
                : ['control_only' => false],
        ])->all();
        $population = app(LabPopulationService::class);
        $planner = new \ReflectionMethod($population, 'normalStructuralResearchPlan');
        $planned = $planner->invoke($population, $plan, $lab);

        foreach ([0, 1, 2] as $index) {
            $this->assertFalse((bool) data_get($planned, "plan.{$index}.niche.structural_research", false));
        }

        $validator = new \ReflectionMethod($population, 'normalMutationDiversityContract');
        $contract = $validator->invoke($population, $planned['plan']);

        $this->assertTrue($contract['allowed'], json_encode($contract, JSON_PRETTY_PRINT));
        $this->assertSame(17, $contract['candidate_count']);
        $this->assertSame(5, $contract['structural_candidate_count']);
        $this->assertEmpty($contract['missing_genes']);
        $this->assertEmpty($contract['over_replicated_mutation_signatures']);
    }

    public function test_post_materialization_repair_uses_the_real_thirteen_seat_learning_budget(): void
    {
        $lab = AiLaboratory::create([
            'name' => 'Post-materialization diversity laboratory',
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_families' => ['hybrid'],
            'is_active' => true,
            'lifecycle_mode' => 'lighthouse',
        ]);
        $plan = collect(range(0, 19))->map(fn (int $slot): array => [
            'family' => 'hybrid',
            'origin' => 'discovery',
            'target' => 'regime_coverage',
            'niche' => ['control_only' => in_array($slot, [4, 9, 14, 19], true)],
        ])->all();
        $population = app(LabPopulationService::class);
        $planner = new \ReflectionMethod($population, 'normalStructuralResearchPlan');
        $planned = $planner->invoke($population, $plan, $lab)['plan'];

        foreach ([0, 1, 2] as $index) {
            $planned[$index]['niche'] = [
                'control_only' => false,
                'causal_learning_cohort' => ['role' => ['memory_guided', 'blinded', 'frozen_control'][$index]],
            ];
        }

        $repair = new \ReflectionMethod($population, 'ensureFinalStructuralFloor');
        $repaired = $repair->invoke($population, $planned);
        $validator = new \ReflectionMethod($population, 'normalMutationDiversityContract');
        $contract = $validator->invoke($population, $repaired['plan']);

        $this->assertTrue($repaired['repaired']);
        $this->assertTrue($contract['allowed'], json_encode($contract, JSON_PRETTY_PRINT));
        $this->assertSame(13, $contract['candidate_count']);
        $this->assertSame(4, $contract['structural_candidate_count']);
        $this->assertEmpty($contract['missing_genes']);
        $this->assertEmpty($contract['over_replicated_mutation_signatures']);
    }

    public function test_canonical_memory_is_sealed_before_agent_persistence_and_receipt_reports_only_truthful_use(): void
    {
        [$generation, $sourceLesson] = $this->canonicalSource();
        $packetId = (string) Str::uuid();
        $retrievalId = (string) Str::uuid();
        AgentLearningRetrieval::create([
            'retrieval_id' => $retrievalId, 'packet_id' => $packetId,
            'agent_learning_lesson_id' => $sourceLesson->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'retrieval_state' => 'retrieved', 'context' => [],
            'metadata' => ['parameter_key' => 'entry_threshold', 'provenance' => 'canonical_settled'],
        ]);
        $packet = [
            'packet_id' => $packetId,
            'positive_lessons' => [[
                'lesson_id' => $sourceLesson->id, 'retrieval_id' => $retrievalId,
                'parameter_key' => 'entry_threshold', 'provenance' => 'canonical_settled',
                'match_level' => 'exact_context',
            ]],
            'harmful_lessons' => [], 'uncertainty_lessons' => [], 'retrieval_count' => 1,
        ];
        // A canonical lesson alone is not executable memory guidance.  The
        // companion cartridge supplies the exact settled intervention that
        // the seal must reproduce.
        LabSkillZooEntry::create([
            'skill_key' => hash('sha256', 'causal-loop-test-cartridge'), 'cartridge_key' => hash('sha256', 'causal-loop-test-cartridge-key'),
            'revision' => 1, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'module_key' => 'entry', 'niche_key' => 'test', 'gene_key' => 'entry_threshold', 'quality_score' => 1, 'confidence' => .8,
            'status' => 'provisional', 'component_status' => 'paired_observed', 'organism_viability' => 'not_viable',
            'evidence' => ['intervention' => ['old_value' => 1, 'tested_value' => 2, 'direction' => 'increase'], 'context' => []],
        ]);
        $service = app(CausalLearningMutationIntentService::class);
        $diff = ['entry_threshold' => ['old' => 1, 'new' => 2]];
        $plan = $service->plan(
            $generation->fresh('laboratory'), $packet, 'hybrid', 'profit_factor',
            ['entry_threshold' => 1], ['entry_threshold' => 2], $diff, 'memory_guided',
        );
        $this->assertSame('memory_guided', $plan['influence_type']);
        $this->assertSame([$sourceLesson->id], $plan['causally_applied_lesson_ids']);
        $model = $this->model('guided-child', ['entry_threshold' => 2], [
            'generation_target' => 'profit_factor',
            'hypothesis_contract' => ['changed_gene' => 'entry_threshold'],
            'execution_contract' => ['protocol' => 'test'],
        ]);
        $intent = $service->seal($plan, $generation, $model);
        $this->assertInstanceOf(AgentLearningMutationIntent::class, $intent);
        $agent = $this->agent($generation, $model, $diff);
        $episode = app(LearningKernelService::class)->openEpisode($agent, [
            'decision_key' => 'causal-intent-test', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'hybrid', 'context' => [],
        ]);
        $binding = $service->bind($intent, $agent, $episode->id);
        $this->assertSame('bound', $binding['status']);
        app(LearningKernelService::class)->recordConsumption($packet, [
            'parameter_key' => 'entry_threshold',
            'causally_applied_retrieval_ids' => [$retrievalId],
        ], $agent, $episode);
        $receipt = app(LearningReceiptService::class)->issue($agent->fresh(['modelVersion', 'generation']), $packet);

        $this->assertSame('learning_receipt_v2', $receipt['protocol']);
        $this->assertSame([$sourceLesson->id], $receipt['retrieved_lesson_ids']);
        $this->assertSame([$sourceLesson->id], $receipt['selected_lesson_ids']);
        $this->assertSame([$sourceLesson->id], $receipt['causally_applied_lesson_ids']);
        $this->assertSame([], $receipt['rejected_lesson_ids']);
        $this->assertTrue($receipt['integrity']['valid']);
        $this->assertSame('CAUSAL_MUTATION_APPLIED', AgentLearningRetrieval::firstOrFail()->reason_code);
        $this->assertTrue((bool) data_get(AgentLearningRetrieval::firstOrFail()->metadata, 'causal_application'));
        $pulse = app(LearningPulseService::class)->pulse('XAUUSD', 'H1');
        $this->assertSame(1, $pulse['canonical_settlements']);
        $this->assertSame(1, $pulse['canonical_lessons_created']);
        $this->assertLessThan(
            data_get($binding, 'causal_order.agent_persistence_sequence'),
            data_get($binding, 'causal_order.mutation_seal_sequence'),
        );
    }

    public function test_family_prior_cannot_be_upgraded_post_hoc_into_memory_guided_mutation(): void
    {
        [$generation, $sourceLesson] = $this->canonicalSource();
        $packet = [
            'packet_id' => (string) Str::uuid(),
            'positive_lessons' => [[
                'lesson_id' => $sourceLesson->id,
                'retrieval_id' => (string) Str::uuid(),
                'parameter_key' => 'entry_threshold',
                'provenance' => 'canonical_settled',
                'match_level' => 'family_prior',
                'score' => 100,
            ]],
            'harmful_lessons' => [],
            'uncertainty_lessons' => [],
        ];
        $service = app(CausalLearningMutationIntentService::class);
        $selection = $service->selectIntervention(
            $packet,
            ['entry_threshold' => 1],
            ['entry_threshold' => ['integer', 0, 5]],
            ['entry_threshold'],
        );
        $plan = $service->plan(
            $generation->fresh('laboratory'),
            $packet,
            'hybrid',
            'profit_factor',
            ['entry_threshold' => 1],
            ['entry_threshold' => 2],
            ['entry_threshold' => ['old' => 1, 'new' => 2]],
        );

        $this->assertSame('memory_abstained', $selection['status']);
        $this->assertSame('independent_exploration', $plan['influence_type']);
        $this->assertSame([], $plan['causally_applied_lesson_ids']);
        $this->assertSame([], $plan['causally_applied_retrieval_ids']);
    }

    public function test_receipt_gene_mismatch_is_invalid_and_cannot_settle_provisional(): void
    {
        [$generation] = $this->canonicalSource();
        $model = $this->model('mismatch-child', ['entry_threshold' => 2], [
            'generation_target' => 'profit_factor',
            'hypothesis_contract' => ['changed_gene' => 'wrong_gene'],
            'execution_contract' => ['protocol' => 'test'],
        ]);
        $agent = $this->agent($generation, $model, ['entry_threshold' => ['old' => 1, 'new' => 2]]);
        $receipt = app(LearningReceiptService::class)->issue($agent->fresh(['modelVersion', 'generation']));

        $this->assertSame('invalid_intent', $receipt['status']);
        $this->assertFalse($receipt['integrity']['receipt_gene_matches_parameter_diff']);
        $settled = app(LearningReceiptService::class)->settle($agent->fresh(['modelVersion']), [
            'evidence_run_id' => 'mismatch-run',
            'mutation_observability' => ['observable_effect' => true, 'control_delta' => .2, 'non_target_regression' => ['safe' => true]],
        ]);
        $this->assertSame('invalid_intent', $settled['status']);
    }

    public function test_planner_materializes_guided_blinded_and_frozen_control_triplet(): void
    {
        [$generation, $sourceLesson] = $this->canonicalSource();
        $sourceLesson->update([
            'parameter_key' => 'high_volatility_risk_multiplier',
            'evidence' => [...((array) $sourceLesson->evidence),
                'old_value' => ['value' => .5], 'new_value' => ['value' => .55],
            ],
        ]);
        $sourcePair = LabLearningLanePair::query()->findOrFail((int) data_get($sourceLesson->evidence, 'pair_id'));
        $sourcePair->candidateAgent->update(['parameter_diff' => [
            'high_volatility_risk_multiplier' => ['old' => .5, 'new' => .55],
        ]]);
        $sourcePair->candidateAgent->modelVersion->update(['parameters' => ['high_volatility_risk_multiplier' => .55]]);
        $baselineGroup = app(StrategySemanticGroupService::class)->descriptor(
            'XAUUSD',
            'H1',
            'hybrid',
            [
                'role' => 'range_specialist',
                'regime' => 'range',
                'volatility' => 'low_volatility',
                'direction' => 'BUY',
            ],
        );
        $sourcePair->controlAgent->modelVersion->update([
            'parameters' => ['high_volatility_risk_multiplier' => .5],
            'metadata' => ['semantic_group' => $baselineGroup],
        ]);
        $this->projectCartridgeForLesson($sourceLesson->fresh());
        $seedPlan = app(CausalLearningCohortPlannerService::class)->seedPlan($sourceLesson->fresh());
        $this->assertSame(['causal_confirm'], collect($seedPlan)->pluck('origin')->unique()->values()->all());
        $this->assertTrue(collect($seedPlan)->every(fn (array $slot): bool => strlen($slot['origin']) <= 24));
        $plan = collect(range(1, 4))->map(fn (int $slot): array => [
            'family' => 'hybrid', 'origin' => 'test', 'target' => 'profit_factor',
            'niche' => [
                'data_lane' => 'price', 'slot' => $slot,
                'structural_research' => true,
                'structural_mutation_required' => true,
                'structural_operation' => 'displaced-discovery-contract',
                'declared_gene' => 'entry_topology_variant',
                'declared_value' => 'regime_consensus_v1',
            ],
        ])->all();

        $materialized = app(CausalLearningCohortPlannerService::class)->materialize(
            $plan, 'XAUUSD', 'H1', $generation->id,
        );

        $this->assertSame('materialized', $materialized['contract']['status']);
        $this->assertSame($sourceLesson->id, $materialized['contract']['source_lesson_id']);
        $roles = collect($materialized['plan'])->pluck('niche.causal_learning_cohort.role')->filter()->values()->all();
        $this->assertSame(['memory_guided', 'blinded', 'frozen_control'], $roles);
        $this->assertTrue((bool) data_get($materialized['plan'][2], 'niche.control_only'));
        $this->assertSame('high_volatility_risk_multiplier', data_get($materialized['plan'][0], 'niche.declared_gene'));
        $this->assertSame(
            'compatible_cartridge_found',
            data_get($materialized['plan'][0], 'niche.causal_learning_cohort.skill_cartridge.status'),
        );
        $this->assertNull(data_get($materialized['plan'][1], 'niche.causal_learning_cohort.skill_cartridge'));
        $this->assertNull(data_get($materialized['plan'][2], 'niche.causal_learning_cohort.skill_cartridge'));
        $this->assertNull(data_get($materialized['plan'][1], 'niche.declared_gene'));
        $this->assertSame('cold_start_memory_blinded_selector', data_get($materialized['plan'][1], 'niche.selector_policy'));
        $this->assertSame(
            CausalBlindedMutationSelectorService::PROTOCOL,
            data_get($materialized['plan'][1], 'niche.causal_learning_cohort.blinded_selector.protocol'),
        );
        $this->assertSame(0, data_get($materialized['plan'][1], 'niche.causal_learning_cohort.blinded_selector.memory_inputs'));
        $this->assertNotSame(
            data_get($materialized['plan'][1], 'niche.causal_learning_cohort.blinded_selector.old_value'),
            data_get($materialized['plan'][1], 'niche.causal_learning_cohort.blinded_selector.value'),
        );
        foreach (collect($materialized['plan'])->filter(
            fn (array $slot): bool => filled(data_get($slot, 'niche.causal_learning_cohort.role')),
        ) as $slot) {
            $this->assertFalse((bool) data_get($slot, 'niche.structural_research'));
            $this->assertFalse((bool) data_get($slot, 'niche.structural_mutation_required'));
            $this->assertNull(data_get($slot, 'niche.structural_operation'));
            $this->assertTrue((bool) data_get($slot, 'niche.causal_displaced_slot_contract.structural_research'));
            $this->assertSame('range_specialist', data_get($slot, 'niche.role'));
            $this->assertSame('range_specialist', data_get($slot, 'niche.specialist_role'));
            $this->assertSame('range', data_get($slot, 'niche.regime'));
            $this->assertSame('low_volatility', data_get($slot, 'niche.volatility'));
            $this->assertSame('buy', data_get($slot, 'niche.direction'));
        }
        $this->assertSame(
            $sourcePair->controlAgent->model_version_id,
            data_get($materialized['contract'], 'baseline_model_version_id'),
        );
    }

    public function test_legacy_attempts_cannot_exhaust_target_aligned_confirmation_budget(): void
    {
        [$generation, $lesson] = $this->canonicalSource();
        $lesson->update([
            'parameter_key' => 'high_volatility_risk_multiplier',
            'evidence' => [...((array) $lesson->evidence),
                'old_value' => ['value' => .5], 'new_value' => ['value' => .55],
            ],
        ]);
        $lesson = $lesson->fresh();
        $pairId = (int) data_get($lesson->evidence, 'pair_id');
        $pair = LabLearningLanePair::query()->findOrFail($pairId);
        $pair->candidateAgent->update(['parameter_diff' => [
            'high_volatility_risk_multiplier' => ['old' => .5, 'new' => .55],
        ]]);
        $pair->candidateAgent->modelVersion->update(['parameters' => ['high_volatility_risk_multiplier' => .55]]);
        $pair->controlAgent->modelVersion->update(['parameters' => ['high_volatility_risk_multiplier' => .5]]);
        $this->projectCartridgeForLesson($lesson->fresh());

        foreach (range(1, 3) as $attempt) {
            AgentLearningCausalExperiment::create([
                'experiment_key' => hash('sha512', 'legacy-attempt-'.$attempt),
                'lab_generation_id' => $generation->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
                'target' => 'profit_factor', 'gene_key' => 'high_volatility_risk_multiplier',
                'source_lesson_id' => $lesson->id, 'status' => 'provisional',
                'evidence' => ['source_pair_id' => $pairId, 'promotion_evidence' => false],
            ]);
        }

        $planner = app(CausalLearningCohortPlannerService::class);
        $canonical = new \ReflectionMethod(CausalLearningCohortPlannerService::class, 'canonicalPositive');
        $canonical->setAccessible(true);
        $this->assertTrue($canonical->invoke($planner, $lesson));
        $this->assertSame(0, AgentLearningCausalExperiment::query()
            ->where('source_lesson_id', $lesson->id)
            ->get()->filter(fn ($row): bool => data_get($row->evidence, 'confirmation_evidence_protocol')
                === CausalLearningConfirmationService::EVIDENCE_PROTOCOL)->count());
        $this->assertSame($lesson->id, $planner->eligibleLesson('XAUUSD', 'H1', 'hybrid', $lesson->id)?->id);

        foreach (range(1, 3) as $attempt) {
            AgentLearningCausalExperiment::create([
                'experiment_key' => hash('sha512', 'target-aligned-attempt-'.$attempt),
                'lab_generation_id' => $generation->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
                'target' => 'profit_factor', 'gene_key' => 'high_volatility_risk_multiplier',
                'source_lesson_id' => $lesson->id, 'status' => 'provisional',
                'evidence' => [
                    'source_pair_id' => $pairId,
                    'confirmation_evidence_protocol' => CausalLearningConfirmationService::EVIDENCE_PROTOCOL,
                    'promotion_evidence' => false,
                ],
            ]);
        }

        $this->assertNull($planner->eligibleLesson('XAUUSD', 'H1', 'hybrid', $lesson->id));
    }

    public function test_legacy_positive_signal_opens_a_credit_free_post_v2_hypothesis_triplet(): void
    {
        [$generation, $lesson] = $this->canonicalSource();
        $lesson->update([
            'parameter_key' => 'high_volatility_risk_multiplier',
            'evidence' => [...((array) $lesson->evidence),
                'old_value' => ['value' => .5], 'new_value' => ['value' => .55],
            ],
        ]);
        $pair = LabLearningLanePair::query()->findOrFail((int) data_get($lesson->evidence, 'pair_id'));
        $pair->candidateAgent->update(['parameter_diff' => [
            'high_volatility_risk_multiplier' => ['old' => .5, 'new' => .55],
        ]]);
        $pair->candidateAgent->modelVersion->update(['parameters' => ['high_volatility_risk_multiplier' => .55]]);
        $pair->controlAgent->modelVersion->update(['parameters' => ['high_volatility_risk_multiplier' => .5]]);
        $this->projectCartridgeForLesson($lesson->fresh());
        $pair->update(['pair_integrity_status' => 'legacy_screen_paired']);
        $lesson = $lesson->fresh();
        $plan = collect(range(1, 4))->map(fn (int $slot): array => [
            'family' => 'hybrid', 'origin' => 'test', 'target' => 'profit_factor',
            'niche' => ['data_lane' => 'price', 'slot' => $slot],
        ])->all();

        $materialized = app(CausalLearningCohortPlannerService::class)->materialize(
            $plan, 'XAUUSD', 'H1', $generation->id,
        );

        $this->assertSame('materialized', data_get($materialized, 'contract.status'));
        $this->assertSame('hypothesis_guided', data_get($materialized, 'contract.guided_role'));
        $this->assertSame('legacy_hypothesis_only', data_get($materialized, 'contract.source_authority'));
        $this->assertFalse((bool) data_get($materialized, 'contract.legacy_hypothesis_grants_credit'));
        $guided = collect($materialized['plan'])->first(
            fn (array $slot): bool => data_get($slot, 'niche.causal_learning_cohort.role') === 'hypothesis_guided',
        );
        $this->assertNotNull($guided);
        $this->assertSame(
            'legacy_hypothesis_reproduction',
            data_get($guided, 'niche.causal_learning_cohort.experiment_kind'),
        );
        $this->assertFalse((bool) data_get($guided, 'niche.learning_memory_required'));
        $this->assertTrue((bool) data_get($guided, 'niche.legacy_hypothesis_reproduction'));

        $intent = app(CausalLearningMutationIntentService::class)->plan(
            $generation->fresh('laboratory'),
            [
                'positive_lessons' => [[
                    'lesson_id' => $lesson->id,
                    'parameter_key' => 'high_volatility_risk_multiplier',
                    'provenance' => 'legacy_hypothesis_only',
                    'match_level' => 'fresh_post_v2_reproduction_required',
                ]],
                'harmful_lessons' => [], 'uncertainty_lessons' => [],
            ],
            'hybrid', 'profit_factor',
            ['high_volatility_risk_multiplier' => .5],
            ['high_volatility_risk_multiplier' => .55],
            ['high_volatility_risk_multiplier' => ['old' => .5, 'new' => .55]],
            'hypothesis_guided',
            (array) data_get($guided, 'niche.causal_learning_cohort.skill_cartridge'),
        );
        $this->assertSame('hypothesis_guided', $intent['influence_type']);
        $this->assertSame([$lesson->id], $intent['selected_lesson_ids']);
        $this->assertSame([], $intent['causally_applied_lesson_ids']);
    }

    public function test_salvage_and_planner_share_the_same_fail_closed_source_admission(): void
    {
        [, $lesson] = $this->canonicalSource();
        $lesson->update([
            'parameter_key' => 'high_volatility_risk_multiplier',
            'evidence' => [...((array) $lesson->evidence),
                'old_value' => ['value' => .5], 'new_value' => ['value' => .55],
            ],
        ]);
        $pair = LabLearningLanePair::query()->findOrFail((int) data_get($lesson->evidence, 'pair_id'));
        $pair->candidateAgent->update(['parameter_diff' => [
            'high_volatility_risk_multiplier' => ['old' => .5, 'new' => .55],
        ]]);
        $pair->candidateAgent->modelVersion->update(['parameters' => ['high_volatility_risk_multiplier' => .55]]);
        $pair->controlAgent->modelVersion->update(['parameters' => ['high_volatility_risk_multiplier' => .5]]);
        $this->projectCartridgeForLesson($lesson->fresh());
        $lesson = $lesson->fresh();

        $admission = app(CausalLessonAdmissionService::class)->assess($lesson);
        $ranked = app(EvidenceSalvageConveyorService::class)->rankLessons(collect([$lesson]));
        $this->assertTrue($admission['source_ready']);
        $this->assertTrue((bool) data_get($ranked->first(), 'contract_complete'));
        $this->assertSame($lesson->id, app(CausalLearningCohortPlannerService::class)
            ->eligibleLesson('XAUUSD', 'H1', 'hybrid', $lesson->id)?->id);

        // A legacy-looking positive row must be demoted everywhere when its
        // old control predates frozen_control_v2. It may buy one new proof,
        // but the old row itself remains non-authoritative.
        $pair->update(['pair_integrity_status' => 'legacy_screen_paired']);
        $admission = app(CausalLessonAdmissionService::class)->assess($lesson);
        $ranked = app(EvidenceSalvageConveyorService::class)->rankLessons(collect([$lesson]));
        $this->assertTrue($admission['source_ready']);
        $this->assertFalse($admission['canonical_source_ready']);
        $this->assertSame('legacy_hypothesis_only', $admission['source_authority']);
        $this->assertContains('verified_control_pair', $admission['authority_blockers']);
        $this->assertArrayNotHasKey('verified_control_pair', $admission['reproduction_checks']);
        $this->assertFalse($admission['authority_checks']['verified_control_pair']);
        $this->assertTrue((bool) data_get($ranked->first(), 'contract_complete'));
        $this->assertArrayNotHasKey('verified_control_pair', data_get($ranked->first(), 'contract_checks'));
        $this->assertFalse((bool) data_get($ranked->first(), 'authority_checks.verified_control_pair'));
        $this->assertSame('legacy_hypothesis_only', data_get($ranked->first(), 'source_authority'));
        $this->assertSame($lesson->id, app(CausalLearningCohortPlannerService::class)
            ->eligibleLesson('XAUUSD', 'H1', 'hybrid', $lesson->id)?->id);

        // Reconstruction drift is different: if the historical baseline no
        // longer matches the declared old value, even a research reproduction
        // must fail closed.
        $pair->controlAgent->modelVersion->update(['parameters' => [
            'high_volatility_risk_multiplier' => .6,
        ]]);
        $admission = app(CausalLessonAdmissionService::class)->assess($lesson);
        $ranked = app(EvidenceSalvageConveyorService::class)->rankLessons(collect([$lesson]));
        $this->assertFalse($admission['source_ready']);
        $this->assertContains('control_model_matches_old', $admission['blockers']);
        $this->assertFalse((bool) data_get($ranked->first(), 'contract_complete'));
        $this->assertNull(app(CausalLearningCohortPlannerService::class)
            ->eligibleLesson('XAUUSD', 'H1', 'hybrid', $lesson->id));
    }

    public function test_planner_does_not_admit_a_descriptive_lesson_without_an_executable_cartridge(): void
    {
        [, $lesson] = $this->canonicalSource();

        $this->assertSame('positive', AgentLearningSettlement::query()->latest('id')->value('evidence_state'));
        $this->assertSame(
            'LESSON_EXECUTABLE_CARTRIDGE_MISSING',
            data_get(app(CanonicalSkillCartridgeService::class)->retrieveForLesson($lesson), 'reason'),
        );
        $this->assertNull(
            app(CausalLearningCohortPlannerService::class)->eligibleLesson(
                'XAUUSD',
                'H1',
                'hybrid',
                $lesson->id,
            ),
        );
    }

    public function test_falsified_confirmation_becomes_bounded_one_gene_repair_without_inheriting_failed_gene(): void
    {
        [$generation, $sourceLesson] = $this->canonicalSource();
        $sourcePair = LabLearningLanePair::query()->findOrFail((int) data_get($sourceLesson->evidence, 'pair_id'));
        $sourcePair->controlAgent->modelVersion->update(['parameters' => [
            'entry_threshold' => 1,
            'high_volatility_risk_multiplier' => .5,
            'max_loss_streak_before_wait' => 4,
        ]]);
        $sourcePair->candidateAgent->modelVersion->update(['parameters' => [
            'entry_threshold' => 2,
            'high_volatility_risk_multiplier' => .5,
            'max_loss_streak_before_wait' => 4,
        ]]);
        $blinded = $this->agent($generation, $this->model('repair-source-blind', ['entry_threshold' => 1]), [
            'entry_threshold' => ['old' => 1, 'new' => 1.2],
        ]);
        $experiment = AgentLearningCausalExperiment::create([
            'experiment_key' => str_repeat('r', 128),
            'lab_generation_id' => $generation->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'target' => 'profit_factor', 'gene_key' => 'entry_threshold',
            'source_lesson_id' => $sourceLesson->id,
            'guided_agent_id' => $sourcePair->candidate_agent_id,
            'blinded_agent_id' => $blinded->id,
            'control_agent_id' => $sourcePair->control_agent_id,
            'status' => 'provisional',
            'evidence' => [
                'source_pair_id' => $sourcePair->id,
                'repair_frontier' => [
                    'status' => 'bounded_repair_required',
                    'inherit_gene' => false,
                    'target' => 'drawdown_risk',
                    'next_experiment' => 'one_gene_paired_replay',
                    'promotion_evidence' => false,
                ],
                'promotion_evidence' => false,
            ],
        ]);
        $episode = app(LearningKernelService::class)->openEpisode($sourcePair->candidateAgent, [
            'decision_key' => 'falsified-repair-frontier',
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'context' => [],
        ]);
        AgentLearningSettlement::create([
            'settlement_id' => (string) Str::uuid(), 'episode_id' => $episode->id,
            'source_key' => 'falsified-repair-frontier',
            'source_type' => LabLearningLanePair::class, 'source_id' => $sourcePair->id,
            'outcome_status' => 'settled', 'failure_class' => 'drawdown_risk',
            'evidence_state' => 'negative', 'selection_reward' => -1,
            'hard_failure' => true, 'outcome' => [], 'settled_at' => now()->addSecond(),
        ]);

        // A losing, unattributed baseline must be redirected to Edge Genesis
        // before any drawdown/risk polish receives a cohort. This is a
        // planning-time dependency gate, not a constructor-time surprise.
        $this->assertNull(app(CausalRepairFrontierService::class)->eligible('XAUUSD', 'H1'));
        $redirected = (array) $experiment->fresh()->evidence;
        $this->assertSame('edge_genesis_required', data_get($redirected, 'repair_frontier.status'));
        $this->assertSame(
            'RISK_MUTATION_BEFORE_EDGE_CONFIRMATION',
            data_get($redirected, 'repair_frontier.edge_genesis_redirect.reason_code'),
        );

        // The rest of this test exercises the downstream risk-repair lane, so
        // explicitly give its frozen fixture the phase that production may
        // receive only after Edge attribution.
        $baselineModel = $sourcePair->controlAgent->modelVersion;
        $baselineMetadata = (array) $baselineModel->metadata;
        data_set($baselineMetadata, 'edge_genesis.phase', 'RISK_SHAPING');
        $baselineModel->update(['metadata' => $baselineMetadata]);
        $redirectedFrontier = (array) data_get($redirected, 'repair_frontier', []);
        $redirectedFrontier['status'] = 'bounded_repair_required';
        $redirectedFrontier['next_experiment'] = 'one_gene_paired_replay';
        unset($redirectedFrontier['edge_genesis_redirect'], $redirectedFrontier['previous_status']);
        $redirected['repair_frontier'] = $redirectedFrontier;
        $experiment->refresh();
        $experiment->update(['evidence' => $redirected]);
        $this->assertSame('bounded_repair_required', data_get($experiment->fresh()->evidence, 'repair_frontier.status'));
        $this->assertTrue((bool) data_get(app(DependencyAwareEdgeGenesisFoundryService::class)
            ->mutationAdmission($baselineModel->fresh(), 'high_volatility_risk_multiplier'), 'allowed'));

        $frontier = app(CausalRepairFrontierService::class)->eligible('XAUUSD', 'H1');

        $this->assertNotNull($frontier);
        $this->assertSame($experiment->id, $frontier['source_experiment_id']);
        $this->assertSame('high_volatility_risk_multiplier', $frontier['gene']);
        $this->assertSame(.5, $frontier['old_value']);
        $this->assertSame(.41, $frontier['value']);
        $this->assertSame(
            ['entry_threshold', 'high_volatility_risk_multiplier'],
            $frontier['attempted_genes'],
        );
        $seed = app(CausalRepairFrontierService::class)->seedPlan($frontier);
        $materialized = app(CausalLearningCohortPlannerService::class)->materialize(
            $seed,
            'XAUUSD',
            'H1',
            $generation->id,
        );
        $this->assertSame('materialized', $materialized['contract']['status']);
        $this->assertSame(9, $materialized['contract']['required_independent_windows']);
        $this->assertSame(
            ['repair_guided', 'blinded', 'frozen_control'],
            collect($materialized['plan'])->pluck('niche.causal_learning_cohort.role')->all(),
        );
        $this->assertSame('high_volatility_risk_multiplier', data_get($materialized['plan'][0], 'niche.declared_gene'));
        $this->assertNull(data_get($materialized['plan'][1], 'niche.declared_gene'));
        $this->assertSame(
            CausalBlindedMutationSelectorService::PROTOCOL,
            data_get($materialized['plan'][1], 'niche.causal_learning_cohort.blinded_selector.protocol'),
        );
        $this->assertNotEmpty(data_get($materialized['plan'][1], 'niche.causal_learning_cohort.blinded_selector.gene'));
        $this->assertTrue((bool) data_get($materialized['plan'][2], 'niche.control_only'));

        $experiment->update(['evidence' => [
            ...((array) $experiment->evidence),
            'repair_lineage' => ['depth' => 3, 'attempted_genes' => $frontier['attempted_genes']],
        ]]);
        $this->assertNull(app(CausalRepairFrontierService::class)->eligible('XAUUSD', 'H1', $experiment->id));
    }

    public function test_causal_positive_absolute_negative_step_is_retained_only_as_next_research_baseline(): void
    {
        [$generation, $sourceLesson] = $this->canonicalSource();
        $pair = LabLearningLanePair::query()->findOrFail((int) data_get($sourceLesson->evidence, 'pair_id'));
        $guided = $pair->candidateAgent;
        $parameters = app(StrategyParameterSchemaService::class)->defaults('hybrid');
        $parameters['entry_threshold'] = 2;
        $parameters['high_volatility_risk_multiplier'] = .5;
        $parameters['max_loss_streak_before_wait'] = 4;
        $controlParameters = $parameters;
        $controlParameters['entry_threshold'] = 1;
        $pair->controlAgent->modelVersion->update(['parameters' => $controlParameters]);
        $guided->modelVersion->update([
            'parameters' => $parameters,
            'metadata' => [...((array) $guided->modelVersion->metadata),
                'edge_genesis' => ['phase' => 'RISK_SHAPING'],
            ],
        ]);
        $pair->update(['non_target_regression' => ['status' => 'passed', 'safe' => true]]);
        $blinded = $this->agent($generation, $this->model('ratchet-source-blind', $parameters), [
            'entry_threshold' => ['old' => 1, 'new' => 1.2],
        ]);
        $experiment = AgentLearningCausalExperiment::create([
            'experiment_key' => hash('sha512', 'causal-research-ratchet'),
            'lab_generation_id' => $generation->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'target' => 'profit_factor', 'gene_key' => 'entry_threshold',
            'source_lesson_id' => $sourceLesson->id,
            'guided_agent_id' => $guided->id,
            'blinded_agent_id' => $blinded->id,
            'control_agent_id' => $pair->control_agent_id,
            'status' => 'provisional',
            'guided_beats_control' => true,
            'guided_beats_blinded' => true,
            'evidence' => [
                'source_pair_id' => $pair->id,
                'source_control_agent_id' => $pair->control_agent_id,
                'baseline_model_version_id' => $pair->controlAgent->model_version_id,
                'repair_frontier' => [
                    'status' => 'bounded_repair_required',
                    'inherit_gene' => false,
                    'target' => 'drawdown_risk',
                    'next_experiment' => 'one_gene_paired_replay',
                    'research_ratchet' => [
                        'protocol' => 'causal_research_ratchet_v1',
                        'status' => 'eligible_research_baseline',
                        'allowed' => true,
                        'research_baseline_agent_id' => $guided->id,
                        'research_baseline_model_version_id' => $guided->model_version_id,
                        'root_source_pair_id' => $pair->id,
                        'root_control_agent_id' => $pair->control_agent_id,
                        'root_baseline_model_version_id' => $pair->controlAgent->model_version_id,
                        'retained_steps' => [['gene' => 'entry_threshold', 'promotion_evidence' => false]],
                        'production_parent_allowed' => false,
                        'promotion_evidence' => false,
                    ],
                    'promotion_evidence' => false,
                ],
                'promotion_evidence' => false,
            ],
        ]);
        $episode = app(LearningKernelService::class)->openEpisode($guided, [
            'decision_key' => 'causal-research-ratchet-negative',
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'context' => [],
        ]);
        AgentLearningSettlement::create([
            'settlement_id' => (string) Str::uuid(), 'episode_id' => $episode->id,
            'source_key' => 'causal-research-ratchet-negative',
            'source_type' => LabLearningLanePair::class, 'source_id' => $pair->id,
            'outcome_status' => 'settled', 'failure_class' => 'drawdown_risk',
            'evidence_state' => 'negative', 'selection_reward' => -1,
            'hard_failure' => true, 'outcome' => [], 'settled_at' => now()->addSecond(),
        ]);

        $frontier = app(CausalRepairFrontierService::class)->eligible('XAUUSD', 'H1', $experiment->id);

        $this->assertNotNull($frontier);
        $this->assertSame('causal_positive_research_ratchet', $frontier['baseline_policy']);
        $this->assertSame($guided->id, $frontier['source_control_agent_id']);
        $this->assertSame($guided->model_version_id, $frontier['baseline_model_version_id']);
        $this->assertFalse(data_get($frontier, 'research_ratchet.production_parent_allowed'));
        $this->assertFalse(data_get($frontier, 'research_ratchet.promotion_evidence'));
        $this->assertSame(.5, $frontier['old_value']);
        $this->assertNotSame(.5, $frontier['value']);

        $materialized = app(CausalLearningCohortPlannerService::class)->materialize(
            app(CausalRepairFrontierService::class)->seedPlan($frontier),
            'XAUUSD',
            'H1',
            $generation->id,
        );
        $this->assertSame('materialized', $materialized['contract']['status']);
        $this->assertSame('causal_positive_research_ratchet', $materialized['contract']['baseline_policy']);
        $this->assertSame($guided->model_version_id, $materialized['contract']['baseline_model_version_id']);
        $this->assertTrue(data_get($materialized, 'contract.research_ratchet.allowed'));
        $this->assertFalse(data_get($materialized, 'contract.research_ratchet.production_parent_allowed'));
    }

    public function test_research_ratchet_requires_explicit_non_target_evidence(): void
    {
        [$generation, $sourceLesson] = $this->canonicalSource();
        $pair = LabLearningLanePair::query()->findOrFail((int) data_get($sourceLesson->evidence, 'pair_id'));
        $guided = $pair->candidateAgent->fresh('modelVersion');
        $experiment = AgentLearningCausalExperiment::create([
            'experiment_key' => hash('sha512', 'ratchet-explicit-non-target'),
            'lab_generation_id' => $generation->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'target' => 'profit_factor', 'gene_key' => 'entry_threshold',
            'guided_agent_id' => $guided->id, 'control_agent_id' => $pair->control_agent_id,
            'status' => 'provisional',
            'evidence' => [
                'source_pair_id' => $pair->id,
                'source_control_agent_id' => $pair->control_agent_id,
                'baseline_model_version_id' => $pair->controlAgent->model_version_id,
                'promotion_evidence' => false,
            ],
        ]);
        $episode = app(LearningKernelService::class)->openEpisode($guided, [
            'decision_key' => 'ratchet-explicit-non-target-negative',
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'context' => [],
        ]);
        $negative = AgentLearningSettlement::create([
            'settlement_id' => (string) Str::uuid(), 'episode_id' => $episode->id,
            'source_key' => 'ratchet-explicit-non-target-negative',
            'source_type' => LabLearningLanePair::class, 'source_id' => $pair->id,
            'outcome_status' => 'settled', 'failure_class' => 'drawdown_risk',
            'evidence_state' => 'negative', 'selection_reward' => -1,
            'hard_failure' => true, 'outcome' => [], 'settled_at' => now()->addSecond(),
        ]);
        $effect = ['passed' => true, 'mean_delta' => .25, 'positive_delta_windows' => 7];
        $method = new \ReflectionMethod(CausalLearningConfirmationService::class, 'researchRatchetDecision');
        $method->setAccessible(true);
        $pair->update(['non_target_regression' => ['status' => 'not_recorded', 'safe' => true]]);

        $blocked = $method->invoke(
            app(CausalLearningConfirmationService::class),
            $experiment,
            $pair->fresh('controlResponseMap'),
            $guided,
            $negative,
            $effect,
            $effect,
            ['GUIDED_ABSOLUTE_VIABILITY_FAILED'],
        );
        $this->assertFalse($blocked['allowed']);
        $this->assertContains('NON_TARGET_EVIDENCE_NOT_EXPLICITLY_PASSED', $blocked['reason_codes']);

        $pair->update(['non_target_regression' => ['status' => 'passed', 'safe' => true]]);
        $eligible = $method->invoke(
            app(CausalLearningConfirmationService::class),
            $experiment,
            $pair->fresh('controlResponseMap'),
            $guided,
            $negative,
            $effect,
            $effect,
            ['GUIDED_ABSOLUTE_VIABILITY_FAILED'],
        );
        $this->assertTrue($eligible['allowed']);
        $this->assertSame('eligible_research_baseline', $eligible['status']);
        $this->assertSame($guided->model_version_id, $eligible['research_baseline_model_version_id']);
        $this->assertFalse($eligible['production_parent_allowed']);
        $this->assertFalse($eligible['promotion_evidence']);
    }

    public function test_repair_frontier_skips_a_gene_whose_required_context_never_activated(): void
    {
        [$generation, $sourceLesson] = $this->canonicalSource();
        $sourcePair = LabLearningLanePair::query()->findOrFail((int) data_get($sourceLesson->evidence, 'pair_id'));
        $sourcePair->controlAgent->modelVersion->update(['parameters' => [
            'entry_threshold' => 1,
            'high_volatility_risk_multiplier' => .5,
            'max_loss_streak_before_wait' => 4,
        ], 'metadata' => ['edge_genesis' => ['phase' => 'RISK_SHAPING']]]);
        $sourcePair->candidateAgent->modelVersion->update(['parameters' => [
            'entry_threshold' => 2,
            'high_volatility_risk_multiplier' => .5,
            'max_loss_streak_before_wait' => 4,
        ]]);
        $blinded = $this->agent($generation, $this->model('activation-screen-blind', ['entry_threshold' => 1]), [
            'entry_threshold' => ['old' => 1, 'new' => 1.2],
        ]);
        $experiment = AgentLearningCausalExperiment::create([
            'experiment_key' => str_repeat('v', 128),
            'lab_generation_id' => $generation->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'target' => 'drawdown_risk', 'gene_key' => 'entry_threshold',
            'source_lesson_id' => $sourceLesson->id,
            'guided_agent_id' => $sourcePair->candidate_agent_id,
            'blinded_agent_id' => $blinded->id,
            'control_agent_id' => $sourcePair->control_agent_id,
            'status' => 'provisional',
            'evidence' => [
                'source_pair_id' => $sourcePair->id,
                'repair_frontier' => [
                    'status' => 'bounded_repair_required',
                    'inherit_gene' => false,
                    'target' => 'drawdown_risk',
                    'next_experiment' => 'one_gene_paired_replay',
                    'promotion_evidence' => false,
                ],
                'promotion_evidence' => false,
            ],
        ]);
        $episode = app(LearningKernelService::class)->openEpisode($sourcePair->candidateAgent, [
            'decision_key' => 'activation-screen-frontier',
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'context' => [],
        ]);
        AgentLearningSettlement::create([
            'settlement_id' => (string) Str::uuid(), 'episode_id' => $episode->id,
            'source_key' => 'activation-screen-frontier',
            'source_type' => LabLearningLanePair::class, 'source_id' => $sourcePair->id,
            'outcome_status' => 'settled', 'failure_class' => 'drawdown_risk',
            'evidence_state' => 'negative', 'selection_reward' => -1,
            'hard_failure' => true, 'outcome' => [], 'settled_at' => now()->addSecond(),
        ]);
        $run = LabEvaluationRun::create([
            'run_id' => (string) Str::uuid(),
            'lab_generation_id' => $generation->id,
            'lab_agent_id' => $sourcePair->control_agent_id,
            'model_version_id' => $sourcePair->controlAgent->model_version_id,
            'phase' => 'full_validation', 'status' => 'completed',
            'started_at' => now(), 'finished_at' => now(),
            'metrics' => ['agent_result' => ['parameter_activation_manifest' => [
                'protocol' => 'causal_parameter_activation_manifest_v1',
                'status' => 'complete', 'observed_folds' => 9,
                'facts' => [
                    'total_trades' => 900, 'total_losses' => 500,
                    'maximum_consecutive_losses' => 6,
                    'high_volatility_trades' => 0,
                ],
                'performance_credit' => false, 'promotion_evidence' => false,
            ]]],
        ]);
        $sourcePair->update(['control_evidence_run_id' => $run->run_id]);

        $frontier = app(CausalRepairFrontierService::class)->eligible('XAUUSD', 'H1', $experiment->id);

        $this->assertSame('max_loss_streak_before_wait', data_get($frontier, 'gene'));
        $this->assertSame('supported', data_get($frontier, 'activation_screen.status'));
        $this->assertSame(
            'high_volatility_risk_multiplier',
            data_get($frontier, 'activation_screen.skipped_genes.0.gene'),
        );
        $this->assertSame(
            'unsupported',
            data_get($frontier, 'activation_screen.skipped_genes.0.status'),
        );
        $this->assertFalse((bool) data_get($frontier, 'activation_screen.performance_credit'));
    }

    public function test_memory_blinded_selector_uses_the_same_non_credit_activation_mask(): void
    {
        $selection = app(CausalBlindedMutationSelectorService::class)->select(
            'hybrid',
            'drawdown_risk',
            ['avoid_high_volatility' => false, 'loss_cooldown_candles' => 4],
            'feasibility-mask-regression',
            null,
            null,
            [
                'protocol' => CausalParameterActivationService::MANIFEST_PROTOCOL,
                'status' => 'complete',
                'observed_folds' => 9,
                'facts' => [
                    'total_trades' => 900,
                    'total_losses' => 500,
                    'maximum_consecutive_losses' => 13,
                    'high_volatility_trades' => 0,
                ],
                'performance_credit' => false,
                'promotion_evidence' => false,
            ],
        );

        $this->assertNotNull($selection);
        $this->assertSame('loss_cooldown_candles', $selection['gene']);
        $this->assertSame(CausalParameterActivationService::MASK_PROTOCOL, data_get($selection, 'feasibility_screen.protocol'));
        $this->assertSame('supported', data_get($selection, 'feasibility_screen.status'));
        $this->assertSame(0, $selection['memory_inputs']);
        $this->assertSame(1, $selection['feasibility_inputs']);
        $this->assertFalse((bool) data_get($selection, 'feasibility_screen.performance_credit'));
    }

    public function test_memory_blinded_selector_exhausts_target_genes_before_schema_fallback(): void
    {
        $selection = app(CausalBlindedMutationSelectorService::class)->select(
            'differential_router',
            'regime_coverage',
            [
                'trend_down_strength_min' => 20.0,
                'trend_up_strength_min' => 20.0,
                'minimum_signal_confidence' => 0.6,
                'lookback' => 20,
                'partial_take_profit_fraction' => 0.0,
                'time_stop_candles' => 12,
            ],
            'target-tier-regression',
        );

        $this->assertNotNull($selection);
        $this->assertContains($selection['gene'], [
            'trend_down_strength_min',
            'trend_up_strength_min',
            'minimum_signal_confidence',
            'lookback',
        ]);
        $this->assertSame('target_preferred', $selection['selection_tier']);
        $this->assertSame(3, $selection['target_preferred_gene_count']);
    }

    public function test_blinded_selector_skips_runtime_semantic_aliases(): void
    {
        $selection = app(CausalBlindedMutationSelectorService::class)->select(
            'hybrid',
            'drawdown_risk',
            ['range_signal_mode' => 'reentry'],
            'semantic-alias-regression',
        );

        $this->assertNotNull($selection);
        $this->assertContains($selection['value'], ['inverse_extreme', 'mid_cross']);
        $this->assertNotSame('mean_reversion', $selection['value']);
        $semantic = new \ReflectionMethod(CausalBlindedMutationSelectorService::class, 'semanticallyDifferent');
        $semantic->setAccessible(true);
        $this->assertFalse($semantic->invoke(
            app(CausalBlindedMutationSelectorService::class),
            'hybrid', 'range_signal_mode', 'reentry', 'mean_reversion',
        ));
        $this->assertSame(
            CausalBlindedMutationSelectorService::SEMANTIC_PROTOCOL,
            data_get($selection, 'feasibility_screen.semantic_distinctness_protocol'),
        );
    }

    public function test_blinded_selector_cannot_spend_replay_on_a_disabled_dependent_gene(): void
    {
        $activation = app(CausalParameterActivationService::class)->status(
            'meta_label_min_pf',
            1.0,
            .925,
            [],
            ['meta_label_enabled' => false, 'meta_label_min_pf' => 1.0],
        );
        $this->assertSame('unsupported', $activation['status']);
        $this->assertSame('executable_configuration_dependency_inactive', $activation['reason']);
        $this->assertSame('meta_label_enabled', data_get($activation, 'inactive_dependencies.0.gene'));

        $selection = app(CausalBlindedMutationSelectorService::class)->select(
            'hybrid',
            'unknown_target',
            ['meta_label_enabled' => false, 'meta_label_min_pf' => 1.0],
            'disabled-dependent-gene',
            'meta_label_enabled',
            true,
        );

        $this->assertNull($selection);
    }

    public function test_interrupted_causal_constructor_with_superseded_selector_is_closed_before_more_seats_exist(): void
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD',
            'name' => 'Superseded selector constructor',
            'timeframe' => 'H1',
            'strategy_families' => ['hybrid'],
            'is_active' => true,
            'lifecycle_mode' => 'lighthouse',
        ]);
        $plan = collect([1, 2, 3])->map(fn (int $slot): array => [
            'family' => 'hybrid',
            'origin' => 'causal_repair',
            'target' => 'drawdown_risk',
            'niche' => ['slot' => $slot],
        ])->all();
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 128,
            'trigger_type' => 'learning_confirmation',
            'population_size' => 3,
            'status' => 'draft',
            'data_fingerprint' => str_repeat('f', 40),
            'trigger_context' => [
                'generation_plan' => $plan,
                'adaptive_evolution_policy' => [
                    'causal_learning_counterfactual_cohort' => [
                        'blinded_selector' => ['protocol' => 'causal_blinded_single_gene_selector_v4'],
                    ],
                ],
            ],
        ]);

        $result = app(LabPopulationService::class)->continueInterruptedConstruction($generation->id, 3);

        $this->assertSame('superseded_causal_protocol', $result['status']);
        $this->assertSame('technical_quarantine', $generation->fresh()->status);
        $this->assertSame(0, $generation->agents()->count());
        $this->assertSame(
            CausalBlindedMutationSelectorService::PROTOCOL,
            data_get($generation->fresh()->trigger_context, 'constructor_contract_abort.required_selector_protocol'),
        );
    }

    public function test_causal_screening_behavior_preflight_rejects_parameter_only_arm(): void
    {
        [$generation] = $this->canonicalSource();
        $guided = $this->agent($generation, $this->model('behavior-guided', []), ['x' => ['old' => 1, 'new' => 2]]);
        $blinded = $this->agent($generation, $this->model('behavior-blind', []), ['y' => ['old' => 1, 'new' => 2]]);
        $control = $this->agent($generation, $this->model('behavior-control', []), []);
        $experiment = AgentLearningCausalExperiment::create([
            'experiment_key' => str_repeat('h', 128),
            'lab_generation_id' => $generation->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'target' => 'drawdown_risk', 'gene_key' => 'x',
            'guided_agent_id' => $guided->id, 'blinded_agent_id' => $blinded->id,
            'control_agent_id' => $control->id, 'status' => 'ready_for_replay',
            'evidence' => ['promotion_evidence' => false],
        ]);
        $observation = static fn (string $trade, string $event, string $signal, int $entries): array => [
            'causal_observation' => [
                'trade_ledger_hash' => $trade,
                'event_ledger_hash' => $event,
                'signal_decision_hash' => $signal,
                'entry_funnel' => ['accepted_entries' => $entries],
            ],
        ];
        foreach ([
            [$control, 'control', $observation('trade-a', 'event-a', 'signal-a', 10)],
            [$guided, 'screen_observed', $observation('trade-b', 'event-b', 'signal-b', 11)],
            // The parameter differs, but every executable observation is the
            // exact control. It must never receive nine full folds.
            [$blinded, 'screen_observed', $observation('trade-a', 'event-a', 'signal-a', 10)],
        ] as [$agent, $status, $metrics]) {
            LabMutationResponseMap::create([
                'response_key' => hash('sha256', $status.$agent->id),
                'stage' => 'screening', 'status' => $status,
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
                'lab_agent_id' => $agent->id, 'observed_metrics' => $metrics,
            ]);
        }

        $preflight = app(CausalScreeningBehaviorPreflightService::class)->assess($experiment);

        $this->assertSame('failed', $preflight['status']);
        $this->assertContains('BLINDED_SCREENING_EXECUTABLE_BEHAVIOR_UNCHANGED', $preflight['reason_codes']);
        $this->assertTrue((bool) data_get($preflight, 'roles.guided.executable_behavior_changed'));
        $this->assertFalse((bool) data_get($preflight, 'roles.blinded.executable_behavior_changed'));
        $this->assertFalse((bool) data_get($preflight, 'promotion_evidence'));
    }

    public function test_repair_replay_admission_rejects_a_legacy_blinded_selector_without_feasibility_proof(): void
    {
        $method = new \ReflectionMethod(CausalLearningCohortService::class, 'blindedFeasibilityReasons');
        $method->setAccessible(true);
        $service = app(CausalLearningCohortService::class);

        $legacyReasons = $method->invoke($service, [
            'protocol' => 'causal_blinded_single_gene_selector_v2',
            'gene' => 'avoid_high_volatility',
            'memory_inputs' => 0,
            'promotion_evidence' => false,
        ]);
        $this->assertContains('BLINDED_SELECTOR_FEASIBILITY_MASK_MISSING', $legacyReasons);

        $valid = app(CausalBlindedMutationSelectorService::class)->select(
            'hybrid',
            'drawdown_risk',
            ['loss_cooldown_candles' => 4],
            'valid-feasibility-admission',
        );
        $this->assertNotNull($valid);
        $this->assertSame([], $method->invoke($service, $valid));
    }

    public function test_exhausted_scalar_repairs_become_one_gene_architecture_escape_from_original_control(): void
    {
        [$generation, $sourceLesson] = $this->canonicalSource();
        $sourcePair = LabLearningLanePair::query()->findOrFail((int) data_get($sourceLesson->evidence, 'pair_id'));
        $baseline = app(StrategyParameterSchemaService::class)->defaults('hybrid');
        $baseline['entry_threshold'] = 1;
        $sourcePair->controlAgent->modelVersion->update(['parameters' => $baseline]);
        $candidateBaseline = $baseline;
        $candidateBaseline['entry_threshold'] = 2;
        $sourcePair->candidateAgent->modelVersion->update(['parameters' => $candidateBaseline]);
        $blinded = $this->agent($generation, $this->model('architecture-source-blind', $baseline), [
            'entry_threshold' => ['old' => 1, 'new' => 1.2],
        ]);
        $experiment = AgentLearningCausalExperiment::create([
            'experiment_key' => str_repeat('a', 128),
            'lab_generation_id' => $generation->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'target' => 'drawdown_risk', 'gene_key' => 'loss_cooldown_candles',
            'source_lesson_id' => $sourceLesson->id,
            'guided_agent_id' => $sourcePair->candidate_agent_id,
            'blinded_agent_id' => $blinded->id,
            'control_agent_id' => $sourcePair->control_agent_id,
            'status' => 'provisional',
            'evidence' => [
                'source_pair_id' => $sourcePair->id,
                'repair_lineage' => [
                    'depth' => 3,
                    'attempted_genes' => [
                        'entry_threshold', 'high_volatility_risk_multiplier',
                        'max_loss_streak_before_wait', 'loss_cooldown_candles',
                    ],
                ],
                'repair_frontier' => [
                    'status' => 'architecture_escape_required',
                    'inherit_gene' => false,
                    'target' => 'drawdown_risk',
                    'next_experiment' => 'architecture_hypothesis_paired_replay',
                    'promotion_evidence' => false,
                ],
                'promotion_evidence' => false,
            ],
        ]);
        $episode = app(LearningKernelService::class)->openEpisode($sourcePair->candidateAgent, [
            'decision_key' => 'architecture-escape-frontier',
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'context' => [],
        ]);
        AgentLearningSettlement::create([
            'settlement_id' => (string) Str::uuid(), 'episode_id' => $episode->id,
            'source_key' => 'architecture-escape-frontier',
            'source_type' => LabLearningLanePair::class, 'source_id' => $sourcePair->id,
            'outcome_status' => 'settled', 'failure_class' => 'drawdown_risk',
            'evidence_state' => 'negative', 'selection_reward' => -1,
            'hard_failure' => true, 'outcome' => [], 'settled_at' => now()->addSecond(),
        ]);
        $controlRun = LabEvaluationRun::create([
            'run_id' => (string) Str::uuid(),
            'lab_generation_id' => $generation->id,
            'lab_agent_id' => $sourcePair->control_agent_id,
            'model_version_id' => $sourcePair->controlAgent->model_version_id,
            'phase' => 'full_validation', 'status' => 'completed',
            'started_at' => now(), 'finished_at' => now(),
            'metrics' => ['agent_result' => ['parameter_activation_manifest' => [
                'protocol' => CausalParameterActivationService::MANIFEST_PROTOCOL,
                'status' => 'complete', 'observed_folds' => 9,
                'facts' => [
                    'total_trades' => 900, 'total_losses' => 500,
                    'maximum_consecutive_losses' => 6,
                    'high_volatility_trades' => 0,
                ],
                'performance_credit' => false, 'promotion_evidence' => false,
            ]]],
        ]);
        $sourcePair->update(['control_evidence_run_id' => $controlRun->run_id]);

        $frontier = app(CausalRepairFrontierService::class)->eligible('XAUUSD', 'H1', $experiment->id);

        $this->assertNotNull($frontier);
        $this->assertSame(CausalRepairFrontierService::ARCHITECTURE_PROTOCOL, $frontier['protocol']);
        $this->assertSame('causal_architecture_escape', $frontier['experiment_kind']);
        $this->assertSame('state_machine_variant', $frontier['gene']);
        $this->assertSame('none', $frontier['old_value']);
        $this->assertSame('neutral_transition_cooldown_reentry_v1', $frontier['value']);
        $this->assertSame(1, $frontier['architecture_depth']);
        $this->assertSame(['state_machine_variant'], $frontier['attempted_architecture_genes']);
        $this->assertSame(
            CausalParameterActivationService::MANIFEST_PROTOCOL,
            data_get($frontier, 'activation_manifest.protocol'),
        );
        $this->assertSame('complete', data_get($frontier, 'activation_manifest.status'));
        $this->assertSame('unknown_allowed', data_get($frontier, 'activation_screen.status'));
        $this->assertSame(
            $controlRun->run_id,
            data_get($frontier, 'activation_screen.source_control_run_id'),
        );

        $materialized = app(CausalLearningCohortPlannerService::class)->materialize(
            app(CausalRepairFrontierService::class)->seedPlan($frontier),
            'XAUUSD',
            'H1',
            $generation->id,
        );
        $this->assertSame('materialized', $materialized['contract']['status']);
        $this->assertSame('causal_architecture_escape', $materialized['contract']['experiment_kind']);
        $this->assertTrue((bool) data_get($materialized['plan'][0], 'niche.architecture_escape'));
        $this->assertTrue((bool) data_get($materialized['plan'][0], 'niche.structural_research'));
        $this->assertSame('state_machine_variant', data_get($materialized['plan'][0], 'niche.declared_gene'));
        $this->assertSame(
            'neutral_transition_cooldown_reentry_v1',
            data_get($materialized['plan'][0], 'niche.state_machine_variant'),
        );
        $this->assertSame('state_machine_variant', data_get($materialized['plan'][0], 'niche.shadow_mutation_gene'));
        $this->assertContains(
            data_get($materialized['plan'][1], 'niche.causal_learning_cohort.blinded_selector.gene'),
            CausalRepairFrontierService::ARCHITECTURE_GENES,
        );
        $this->assertNull(data_get($materialized['plan'][1], 'niche.state_machine_variant'));
        $this->assertNull(data_get($materialized['plan'][2], 'niche.state_machine_variant'));
        $this->assertSame(9, $materialized['contract']['required_independent_windows']);
        $this->assertTrue((bool) data_get($materialized['plan'][2], 'niche.control_only'));
    }

    public function test_invalid_architecture_construction_reopens_same_frontier_without_consuming_gene(): void
    {
        [$generation] = $this->canonicalSource();
        $source = AgentLearningCausalExperiment::create([
            'experiment_key' => str_repeat('b', 128),
            'lab_generation_id' => $generation->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'target' => 'drawdown_risk', 'gene_key' => 'loss_cooldown_candles',
            'status' => 'provisional',
            'evidence' => [
                'experiment_kind' => 'causal_repair',
                'repair_frontier' => [
                    'status' => 'architecture_escape_required',
                    'inherit_gene' => false,
                    'next_experiment' => 'architecture_hypothesis_paired_replay',
                    'promotion_evidence' => false,
                ],
                'promotion_evidence' => false,
            ],
        ]);
        $child = AgentLearningCausalExperiment::create([
            'experiment_key' => str_repeat('c', 128),
            'lab_generation_id' => $generation->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'target' => 'drawdown_risk', 'gene_key' => 'state_machine_variant',
            'status' => 'ready_for_replay',
            'evidence' => [
                'experiment_kind' => 'causal_architecture_escape',
                'source_causal_experiment_id' => $source->id,
                'promotion_evidence' => false,
            ],
        ]);
        $service = app(CausalRepairFrontierService::class);
        $service->linkDispatched($child);
        $this->assertSame('dispatched', data_get($source->fresh()->evidence, 'repair_frontier.status'));
        $this->assertSame(
            'architecture_escape_required',
            data_get($source->fresh()->evidence, 'repair_frontier.dispatched_from_status'),
        );

        $child->update(['status' => 'invalid_counterfactual_contract']);
        $this->assertTrue($service->releaseInvalidChild($child->fresh(), ['EXACT_PARENT_PROTOCOL_MISSING']));
        $frontier = (array) data_get($source->fresh()->evidence, 'repair_frontier', []);
        $this->assertSame('architecture_escape_required', $frontier['status']);
        $this->assertNull($frontier['child_experiment_id']);
        $this->assertSame($child->id, data_get($frontier, 'invalid_dispatches.0.child_experiment_id'));
        $this->assertSame(['EXACT_PARENT_PROTOCOL_MISSING'], data_get($frontier, 'invalid_dispatches.0.reason_codes'));
        $this->assertFalse($service->releaseInvalidChild($child->fresh(), ['duplicate']));
    }

    public function test_exhausted_architecture_portfolio_materializes_one_bounded_interaction(): void
    {
        [$generation, $sourceLesson] = $this->canonicalSource();
        $sourcePair = LabLearningLanePair::query()->findOrFail((int) data_get($sourceLesson->evidence, 'pair_id'));
        $baseline = app(StrategyParameterSchemaService::class)->defaults('hybrid');
        unset($baseline['architecture_interaction_variant']);
        $baseline['entry_threshold'] = 1;
        $sourcePair->controlAgent->modelVersion->update(['parameters' => $baseline]);
        $candidateBaseline = $baseline;
        $candidateBaseline['entry_threshold'] = 2;
        $sourcePair->candidateAgent->modelVersion->update(['parameters' => $candidateBaseline]);
        $blinded = $this->agent($generation, $this->model('interaction-source-blind', $baseline), [
            'trend_weight' => ['old' => 1, 'new' => .87],
        ]);
        $experiment = AgentLearningCausalExperiment::create([
            'experiment_key' => str_repeat('i', 128),
            'lab_generation_id' => $generation->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'target' => 'drawdown_risk', 'gene_key' => 'regime_classifier_variant',
            'source_lesson_id' => $sourceLesson->id,
            'guided_agent_id' => $sourcePair->candidate_agent_id,
            'blinded_agent_id' => $blinded->id,
            'control_agent_id' => $sourcePair->control_agent_id,
            'status' => 'provisional',
            'evidence' => [
                'experiment_kind' => 'causal_architecture_escape',
                'source_pair_id' => $sourcePair->id,
                'repair_lineage' => [
                    'depth' => 3,
                    'attempted_genes' => [
                        'entry_threshold', 'high_volatility_risk_multiplier',
                        'max_loss_streak_before_wait', 'loss_cooldown_candles',
                    ],
                ],
                'architecture_lineage' => [
                    'protocol' => CausalRepairFrontierService::ARCHITECTURE_PROTOCOL,
                    'depth' => 3,
                    'attempted_genes' => [
                        'state_machine_variant', 'entry_topology_variant', 'regime_classifier_variant',
                    ],
                ],
                'repair_frontier' => [
                    'status' => 'architecture_portfolio_required',
                    'inherit_gene' => false,
                    'target' => 'drawdown_risk',
                    'next_experiment' => 'bounded_architecture_interaction_paired_replay',
                    'promotion_evidence' => false,
                ],
                'promotion_evidence' => false,
            ],
        ]);
        $episode = app(LearningKernelService::class)->openEpisode($sourcePair->candidateAgent, [
            'decision_key' => 'architecture-interaction-frontier',
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'context' => [],
        ]);
        AgentLearningSettlement::create([
            'settlement_id' => (string) Str::uuid(), 'episode_id' => $episode->id,
            'source_key' => 'architecture-interaction-frontier',
            'source_type' => LabLearningLanePair::class, 'source_id' => $sourcePair->id,
            'outcome_status' => 'settled', 'failure_class' => 'drawdown_risk',
            'evidence_state' => 'negative', 'selection_reward' => -1,
            'hard_failure' => true, 'outcome' => [], 'settled_at' => now()->addSecond(),
        ]);

        $frontier = app(CausalRepairFrontierService::class)->eligible('XAUUSD', 'H1', $experiment->id);

        $this->assertNotNull($frontier);
        $this->assertSame(CausalRepairFrontierService::ARCHITECTURE_INTERACTION_PROTOCOL, $frontier['protocol']);
        $this->assertSame('causal_architecture_interaction', $frontier['experiment_kind']);
        $this->assertSame('architecture_interaction_variant', $frontier['gene']);
        $this->assertSame('frozen', $frontier['old_value']);
        $this->assertSame('state_classifier_coherence_v1', $frontier['value']);
        $this->assertSame(1, $frontier['interaction_depth']);
        $this->assertSame(
            ['state_machine_variant', 'regime_classifier_variant'],
            $frontier['interaction_components'],
        );

        $materialized = app(CausalLearningCohortPlannerService::class)->materialize(
            app(CausalRepairFrontierService::class)->seedPlan($frontier),
            'XAUUSD',
            'H1',
            $generation->id,
        );
        $this->assertSame('materialized', $materialized['contract']['status']);
        $this->assertSame('causal_architecture_interaction', $materialized['contract']['experiment_kind']);
        $this->assertSame(1, $materialized['contract']['interaction_depth']);
        $this->assertTrue((bool) data_get($materialized['plan'][0], 'niche.architecture_interaction'));
        $this->assertSame(
            'state_classifier_coherence_v1',
            data_get($materialized['plan'][0], 'niche.architecture_interaction_variant'),
        );
        $this->assertNull(data_get($materialized['plan'][1], 'niche.architecture_interaction_variant'));
        $this->assertNull(data_get($materialized['plan'][2], 'niche.architecture_interaction_variant'));
    }

    public function test_pre_registered_causal_retrieval_returns_only_the_exact_canonical_source(): void
    {
        [, $sourceLesson] = $this->canonicalSource();

        $packet = app(LearningKernelService::class)->retrieveCanonicalLesson(
            $sourceLesson->id,
            'XAUUSD',
            'H1',
            'hybrid',
            ['transition_state' => 'unknown'],
        );

        $this->assertSame('ok', $packet['status']);
        $this->assertSame('pre_registered_exact_causal_source', $packet['retrieval_mode']);
        $this->assertSame($sourceLesson->id, $packet['required_source_lesson_id']);
        $this->assertSame([$sourceLesson->id], collect($packet['positive_lessons'])->pluck('lesson_id')->all());
        $this->assertSame([], $packet['harmful_lessons']);
        $this->assertSame(1, $packet['retrieval_count']);
        $this->assertDatabaseHas('agent_learning_retrievals', [
            'agent_learning_lesson_id' => $sourceLesson->id,
            'match_level' => 'pre_registered_causal_source',
            'retrieval_state' => 'retrieved',
        ]);
    }

    public function test_memory_confirmation_requires_guided_to_beat_blinded_and_frozen_control(): void
    {
        [$generation, $sourceLesson] = $this->canonicalSource();
        $controlModel = $this->model('cohort-control', ['entry_threshold' => 1]);
        $guidedModel = $this->model('cohort-guided', ['entry_threshold' => 2], $this->validReceiptMetadata());
        $blindedModel = $this->model('cohort-blinded', ['entry_threshold' => 2], $this->validReceiptMetadata());
        $control = $this->agent($generation, $controlModel, []);
        $guided = $this->agent($generation, $guidedModel, ['entry_threshold' => ['old' => 1, 'new' => 2]]);
        $blinded = $this->agent($generation, $blindedModel, ['entry_threshold' => ['old' => 1, 'new' => 2]]);
        $guidedModel->update(['metadata' => [...$guidedModel->metadata, 'learning_receipt' => $this->validReceipt()]]);
        $blindedModel->update(['metadata' => [...$blindedModel->metadata, 'learning_receipt' => $this->validReceipt()]]);
        $guidedIntent = $this->intent($generation, $guided, 'memory_guided', [$sourceLesson->id]);
        $this->intent($generation, $blinded, 'blinded_counterfactual', []);
        $experiment = AgentLearningCausalExperiment::create([
            'experiment_key' => str_repeat('x', 128), 'lab_generation_id' => $generation->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'target' => 'profit_factor', 'gene_key' => 'entry_threshold', 'source_lesson_id' => $sourceLesson->id,
            'guided_agent_id' => $guided->id, 'blinded_agent_id' => $blinded->id, 'control_agent_id' => $control->id,
            'status' => 'ready_for_replay', 'evidence' => [
                'construction_validation' => ['status' => 'ready_for_replay'], 'promotion_evidence' => false,
            ],
        ]);
        [$guidedPair, $guidedLesson] = $this->outcomePair($generation, $guided, $control, .20, 'g');
        [$blindedPair] = $this->outcomePair($generation, $blinded, $control, -.05, 'b');
        $guidedEpisode = app(LearningKernelService::class)->openEpisode($guided, [
            'decision_key' => 'causal-confirmation-guided-settlement',
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'context' => [],
        ]);
        AgentLearningSettlement::create([
            'settlement_id' => (string) Str::uuid(),
            'episode_id' => $guidedEpisode->id,
            'source_key' => 'causal-confirmation-guided-settlement',
            'source_type' => LabLearningLanePair::class,
            'source_id' => $guidedPair->id,
            'outcome_status' => 'settled',
            'failure_class' => 'profit_factor',
            'evidence_state' => 'positive',
            'selection_reward' => 1,
            'hard_failure' => false,
            'outcome' => [],
            'settled_at' => now(),
        ]);
        $guidedRun = $this->completeScreeningEvidence(
            $guided,
            app(LabImmutableEvidenceService::class),
            'causal-confirmation-guided-run',
        );
        $controlRun = $this->completeScreeningEvidence(
            $control,
            app(LabImmutableEvidenceService::class),
            'causal-confirmation-control-run',
        );
        $guidedPair->update([
            'candidate_evidence_run_id' => $guidedRun->run_id,
            'control_evidence_run_id' => $controlRun->run_id,
        ]);
        $result = static fn (array $scores): array => [
            'evidence_run_id' => 'forward-proof',
            'profit_factor' => 1 + (array_sum($scores) / count($scores) / 100),
            'max_drawdown_percent' => 8,
            'monte_carlo' => ['risk_of_ruin_percent' => 4],
            'pf_attribution' => [
                'summary' => ['cost_to_gross_profit_percent' => 5],
                'stress_cost' => ['profit_factor' => 1.10],
                'by_volatility' => ['normal' => ['trades' => 20, 'net_pf' => 1.05]],
                'by_session' => ['london' => ['trades' => 20, 'net_pf' => 1.04]],
            ],
            'statistical_evidence' => ['edge_quality' => [
                'worst_fold_profit_factor' => 1.02,
                'worst_regime_pf' => 1.01,
                'confidence_calibration' => ['calibration_score' => .75],
            ]],
            'opportunity_recall' => ['abstention_precision' => .70],
            'forward_window_protocol' => [
                'independence_verified' => true, 'overlap_detected' => false,
                'observed_windows' => 3, 'positive_windows' => 2,
                'windows' => [
                    ['id' => 'w1', 'start' => '2014-01-01', 'end' => '2015-12-31', 'score' => $scores[0]],
                    ['id' => 'w2', 'start' => '2016-01-01', 'end' => '2017-12-31', 'score' => $scores[1]],
                    ['id' => 'w3', 'start' => '2018-01-01', 'end' => '2019-12-31', 'score' => $scores[2]],
                ],
                'purge_embargo_applied' => true, 'label_holding_period_purged' => true,
                'purge_bars' => 12, 'embargo_bars' => 1,
                'maximum_holding_bars' => 12, 'execution_horizon_overlay_applied' => true,
            ],
        ];
        $service = app(CausalLearningConfirmationService::class);
        $first = $service->recordOutcome($guided->fresh(['modelVersion']), $guidedPair, $result([10, 12, 5]), ['delta' => .20, 'improved' => true], $guidedLesson);
        $this->assertFalse($first['confirmed']);
        $second = $service->recordOutcome($blinded->fresh(['modelVersion']), $blindedPair, $result([5, 4, 6]), ['delta' => -.05, 'improved' => false]);
        $this->assertFalse($second['confirmed']);
        $confirmed = $service->recordOutcome($control->fresh(['modelVersion']), $guidedPair, $result([2, 3, 4]), ['delta' => 0, 'improved' => false]);

        $this->assertTrue($confirmed['confirmed']);
        $this->assertSame('confirmed', $experiment->fresh()->status);
        $this->assertSame('confirmed', $guidedLesson->fresh()->status);
        $this->assertSame('skill_confirmed', $guidedPair->fresh()->status);
        $this->assertSame('settled', $guidedIntent->fresh()->status);
        $this->assertSame(1, AgentLearningSettlement::query()
            ->where('source_type', LabLearningLanePair::class)
            ->where('source_id', $guidedPair->id)
            ->where('evidence_state', 'positive')
            ->where('hard_failure', false)
            ->count());
        $this->assertSame('shadow', AgentLearningPolicy::firstOrFail()->state);
        $this->assertSame('confirmed', data_get(
            $guided->fresh('modelVersion')->modelVersion->metadata,
            'causal_learning_experiment.status',
        ));
        $this->assertSame('confirmed', data_get(
            $guided->fresh('modelVersion')->modelVersion->metadata,
            'learning_receipt.status',
        ));
        $this->assertSame($experiment->id, data_get(
            $guided->fresh('modelVersion')->modelVersion->metadata,
            'learning_receipt.settlement.causal_experiment_id',
        ));

        $duplicate = $service->recordOutcome(
            $guided->fresh(['modelVersion']),
            $guidedPair,
            $result([-100, -100, -100]),
            ['delta' => -1, 'improved' => false],
            $guidedLesson,
        );
        $this->assertTrue($duplicate['confirmed']);
        $this->assertSame('confirmed', $experiment->fresh()->status);
    }

    public function test_declared_target_and_non_target_evidence_are_fail_closed(): void
    {
        $service = app(CausalLearningConfirmationService::class);
        $targetMeasurement = new \ReflectionMethod($service, 'targetMeasurement');
        $compareTargets = new \ReflectionMethod($service, 'compareTargetMeasurements');
        $invariantVector = new \ReflectionMethod($service, 'invariantVector');
        $compareInvariants = new \ReflectionMethod($service, 'compareNonTargetInvariants');
        $candidateResult = [
            'profit_factor' => 1.20,
            'max_drawdown_percent' => 9,
            'monte_carlo' => ['risk_of_ruin_percent' => 4],
            'pf_attribution' => [
                'summary' => ['cost_to_gross_profit_percent' => 5],
                'stress_cost' => ['profit_factor' => 1.10],
                'by_volatility' => ['normal' => ['trades' => 20, 'net_pf' => 1.05]],
                'by_session' => ['london' => ['trades' => 20, 'net_pf' => 1.04]],
            ],
            'statistical_evidence' => ['edge_quality' => [
                'worst_fold_profit_factor' => .70,
                'worst_regime_pf' => .55,
                'confidence_calibration' => ['calibration_score' => .80],
            ]],
            'opportunity_recall' => ['abstention_precision' => .70],
        ];
        $controlResult = [
            'profit_factor' => 1.00,
            'max_drawdown_percent' => 10,
            'monte_carlo' => ['risk_of_ruin_percent' => 5],
            'pf_attribution' => [
                'summary' => ['cost_to_gross_profit_percent' => 5.5],
                'stress_cost' => ['profit_factor' => 1.08],
                'by_volatility' => ['normal' => ['trades' => 20, 'net_pf' => 1.04]],
                'by_session' => ['london' => ['trades' => 20, 'net_pf' => 1.03]],
            ],
            'statistical_evidence' => ['edge_quality' => [
                'worst_fold_profit_factor' => .65,
                'worst_regime_pf' => .60,
                'confidence_calibration' => ['calibration_score' => .78],
            ]],
            'opportunity_recall' => ['abstention_precision' => .69],
        ];

        $candidateProfit = $targetMeasurement->invoke($service, 'profit_factor', $candidateResult);
        $controlProfit = $targetMeasurement->invoke($service, 'profit_factor', $controlResult);
        $this->assertTrue(data_get(
            $compareTargets->invoke($service, ['target_measurement' => $candidateProfit], ['target_measurement' => $controlProfit]),
            'passed',
        ));

        $candidateRegime = $targetMeasurement->invoke($service, 'regime_coverage', $candidateResult);
        $controlRegime = $targetMeasurement->invoke($service, 'regime_coverage', $controlResult);
        $this->assertFalse(data_get(
            $compareTargets->invoke($service, ['target_measurement' => $candidateRegime], ['target_measurement' => $controlRegime]),
            'passed',
        ));

        $candidateOutcome = ['invariant_vector' => $invariantVector->invoke($service, $candidateResult)];
        $controlOutcome = ['invariant_vector' => $invariantVector->invoke($service, $controlResult)];
        $safe = $compareInvariants->invoke($service, $candidateOutcome, $controlOutcome, 'regime_coverage');
        $this->assertSame('passed', $safe['status']);
        $this->assertTrue($safe['safe']);

        unset($candidateResult['monte_carlo']);
        $candidateOutcome = ['invariant_vector' => $invariantVector->invoke($service, $candidateResult)];
        $incomplete = $compareInvariants->invoke($service, $candidateOutcome, $controlOutcome, 'regime_coverage');
        $this->assertSame('incomplete', $incomplete['status']);
        $this->assertFalse($incomplete['safe']);
        $this->assertContains('risk_of_ruin', $incomplete['missing_required_metrics']);

        $candidateDrawdownRisk = $targetMeasurement->invoke($service, 'drawdown_risk', [
            ...$candidateResult,
            'monte_carlo' => ['risk_of_ruin_percent' => 4],
        ]);
        $controlDrawdownRisk = $targetMeasurement->invoke($service, 'drawdown_risk', $controlResult);
        $this->assertTrue(data_get(
            $compareTargets->invoke(
                $service,
                ['target_measurement' => $candidateDrawdownRisk],
                ['target_measurement' => $controlDrawdownRisk],
            ),
            'passed',
        ));
        data_set($candidateDrawdownRisk, 'components.risk_of_ruin.value', 6);
        $this->assertFalse(data_get(
            $compareTargets->invoke(
                $service,
                ['target_measurement' => $candidateDrawdownRisk],
                ['target_measurement' => $controlDrawdownRisk],
            ),
            'passed',
        ));
    }

    public function test_volume_quality_failure_is_capability_isolation_not_global_recovery(): void
    {
        $result = app(TechnicalFailureClassifierService::class)->classify(
            'XAUUSD H1 canonical volume quality gate failed.', 'RuntimeException',
        );

        $this->assertSame(TechnicalFailureClassifierService::CAPABILITY, $result['class']);
        $this->assertSame('volume', $result['capability']);
        $this->assertFalse($result['blocks_global_generation']);
    }

    public function test_immutable_constructor_invariant_failure_is_terminal_diagnostic(): void
    {
        $result = app(TechnicalFailureClassifierService::class)->classify(
            'Technical quarantine: strict lab preflight failed (ONE_GENE_INVARIANT_FAILED).',
        );

        $this->assertSame(TechnicalFailureClassifierService::TERMINAL, $result['class']);
        $this->assertFalse($result['blocks_global_generation']);
        $this->assertSame('TERMINAL_DIAGNOSTIC', $result['action']);
    }

    public function test_agent_preflight_error_code_cannot_hide_terminal_constructor_reason(): void
    {
        [$generation] = $this->canonicalSource();
        $model = $this->model('terminal-preflight-child', ['entry_threshold' => 1], [
            'preflight_quarantine' => [
                'errors' => ['NON_EXACT_SEMANTIC_PARENT', 'EXACT_PARENT_PROTOCOL_MISSING'],
            ],
        ]);
        $agent = $this->agent($generation, $model, []);
        $agent->update([
            'lifecycle_status' => 'technical_quarantine',
            'decision_reason' => 'Technical quarantine: strict lab preflight failed (NON_EXACT_SEMANTIC_PARENT).',
        ]);

        $result = app(TechnicalFailureClassifierService::class)->forAgent($agent->fresh('modelVersion'));

        $this->assertSame(TechnicalFailureClassifierService::TERMINAL, $result['class']);
        $this->assertFalse($result['blocks_global_generation']);
        $this->assertSame('TERMINAL_DIAGNOSTIC', $result['action']);
    }

    public function test_causal_parent_lock_is_authoritative_when_planner_preserves_g98_origin(): void
    {
        [$generation] = $this->canonicalSource();
        $generation->update(['trigger_type' => 'learning_confirmation']);
        $schema = app(StrategyParameterSchemaService::class);
        $parameters = $schema->defaults('hybrid');
        $group = app(StrategySemanticGroupService::class)->descriptor(
            'XAUUSD', 'H1', 'hybrid', ['role' => 'general'],
        );
        $parent = $this->model('g98-causal-parent-lock-control', $parameters, [
            'lab_symbol' => 'XAUUSD', 'lab_timeframe' => 'H1', 'semantic_group' => $group,
        ]);
        $child = $this->model('g98-causal-parent-lock-child', $parameters, [
            'lab_symbol' => 'XAUUSD', 'lab_timeframe' => 'H1', 'semantic_group' => $group,
            'causal_learning_cohort' => [
                'protocol' => CausalLearningCohortPlannerService::PROTOCOL,
                'role' => 'frozen_control',
                'status' => 'awaiting_counterfactuals',
            ],
            'adaptive_parent_ecosystem' => [
                'selected_parent_model_version_ids' => [$parent->id],
                'causal_counterfactual_parent_lock' => [
                    'parent_model_version_id' => $parent->id,
                    'research_baseline_only_until_confirmation' => true,
                ],
            ],
            'parent_inheritance_protocol' => [
                'parent_selection' => 'same_canonical_source_baseline_for_all_counterfactual_arms',
            ],
            'semantic_lineage' => [
                'mode' => 'exact_semantic_parent',
                'genetic_parent_model_version_id' => $parent->id,
            ],
        ]);
        $agent = $this->agent($generation->fresh(), $child, []);
        $agent->update([
            'origin' => 'g98_council',
            'parent_a_model_version_id' => $parent->id,
        ]);

        $inspection = app(LabAgentPreflightService::class)->inspect(
            $agent->fresh(['generation', 'modelVersion', 'parentA']),
            'screening',
        );

        $this->assertNotContains('EXACT_PARENT_PROTOCOL_MISSING', $inspection['errors']);
        $this->assertNotContains('NON_EXACT_SEMANTIC_PARENT', $inspection['errors']);
    }

    public function test_causal_frozen_control_identity_is_shared_with_screening_scheduler(): void
    {
        [$generation] = $this->canonicalSource();
        $model = $this->model('causal-frozen-control', ['entry_threshold' => 1], [
            'control_contract' => [
                'protocol' => 'frozen_control_v2',
                'control_only' => true,
                'role' => 'control',
                'generation_id' => $generation->id,
            ],
        ]);
        $control = $this->agent($generation, $model, []);

        $this->assertTrue(app(FrozenControlScreeningAdmissionService::class)->isControl($control));
    }

    public function test_completed_control_waits_for_learning_projection_instead_of_quarantining_candidate(): void
    {
        [$generation] = $this->canonicalSource();
        $control = $this->agent($generation, $this->model('async-projection-control', ['entry_threshold' => 1], [
            'control_contract' => [
                'protocol' => 'frozen_control_v2',
                'control_only' => true,
                'role' => 'control',
                'generation_id' => $generation->id,
            ],
        ]), []);
        $candidate = $this->agent($generation, $this->model('async-projection-candidate', ['entry_threshold' => 2], [
            'causal_learning_cohort' => ['role' => 'repair_guided'],
        ]), ['entry_threshold' => ['old' => 1, 'new' => 2]]);
        $this->terminalRun($control, 'async-control-screening-run', 'screening');

        $admission = app(FrozenControlScreeningAdmissionService::class)->admission($candidate);

        $this->assertSame('waiting', $admission['status']);
        $this->assertSame('FROZEN_CONTROL_LEARNING_PROJECTION_PENDING', $admission['reason']);
        $this->assertSame($control->id, $admission['control_agent_id']);
    }

    public function test_only_exact_causal_projection_race_is_code_repair_recoverable(): void
    {
        [$generation] = $this->canonicalSource();
        $generation->update(['trigger_type' => 'learning_confirmation']);
        $agent = $this->agent($generation, $this->model('projection-race-candidate', [], [
            'causal_learning_cohort' => ['role' => 'repair_guided'],
        ]), []);
        $agent->update([
            'lifecycle_status' => 'technical_quarantine',
            'decision_reason' => 'Frozen control admission failed before screening; strategy verdict withheld: FROZEN_CONTROL_EVIDENCE_INVALID.',
        ]);
        $method = new \ReflectionMethod(RecoverLabEvaluationErrors::class, 'isFrozenControlProjectionRaceRepairable');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke(
            app(RecoverLabEvaluationErrors::class),
            $agent->fresh(['generation', 'modelVersion']),
        ));

        $agent->update(['decision_reason' => 'Ordinary technical quarantine.']);
        $this->assertFalse($method->invoke(
            app(RecoverLabEvaluationErrors::class),
            $agent->fresh(['generation', 'modelVersion']),
        ));
    }

    public function test_runtime_schema_recovery_reads_specialist_contract_failure_from_immutable_run(): void
    {
        [$generation] = $this->canonicalSource();
        $agent = $this->agent($generation, $this->model('specialist-contract-schema-candidate', []), []);
        $agent->update([
            'lifecycle_status' => 'technical_quarantine',
            'decision_reason' => 'Technical quarantine after bounded learning-lane transport failures; strategy verdict withheld.',
        ]);
        $run = LabEvaluationRun::create([
            'run_id' => 'specialist-contract-schema-run',
            'lab_generation_id' => $generation->id,
            'lab_agent_id' => $agent->id,
            'model_version_id' => $agent->model_version_id,
            'phase' => 'screening',
            'mode' => 'replay',
            'status' => 'technical_error',
            'started_at' => now()->subSecond(),
            'finished_at' => now(),
            'error_message' => '{"detail":[{"type":"dict_type","loc":["body","strategies",0,"specialist_context_contract"]}]}',
        ]);
        $method = new \ReflectionMethod(RecoverLabEvaluationErrors::class, 'isRuntimeSchemaRepairable');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke(
            app(RecoverLabEvaluationErrors::class),
            $agent->fresh(['generation', 'modelVersion']),
            'screen',
        ));

        $run->update(['error_message' => '{"detail":[{"type":"dict_type","loc":["body","strategies",0,"unrelated_contract"]}]}']);
        $this->assertFalse($method->invoke(
            app(RecoverLabEvaluationErrors::class),
            $agent->fresh(['generation', 'modelVersion']),
            'screen',
        ));

        $run->update([
            'error_class' => 'Illuminate\\Database\\QueryException',
            'error_message' => "SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'cooperative_module_species_members.status' at row 1",
        ]);
        $this->assertTrue($method->invoke(
            app(RecoverLabEvaluationErrors::class),
            $agent->fresh(['generation', 'modelVersion']),
            'screen',
        ));
    }

    public function test_explicit_runtime_schema_screen_recovery_reopens_an_unresolved_arm_after_mixed_generation_completion(): void
    {
        [$generation] = $this->canonicalSource();
        $generation->update(['status' => 'completed']);
        $agent = $this->agent($generation, $this->model('mixed-phase-schema-candidate', []), []);
        $agent->update([
            'lifecycle_status' => 'technical_quarantine',
            'decision_reason' => 'Technical quarantine after bounded learning-lane transport failures; strategy verdict withheld.',
        ]);
        LabEvaluationRun::create([
            'run_id' => 'mixed-phase-specialist-contract-schema-run',
            'lab_generation_id' => $generation->id,
            'lab_agent_id' => $agent->id,
            'model_version_id' => $agent->model_version_id,
            'phase' => 'screening',
            'mode' => 'screen',
            'status' => 'technical_error',
            'started_at' => now()->subSecond(),
            'finished_at' => now(),
            'error_message' => '{"detail":[{"type":"dict_type","loc":["body","strategies",0,"specialist_context_contract"]}]}',
        ]);

        Http::fake(['*' => Http::response(['strategies' => []])]);
        $queue = \Mockery::mock(LabQueueJobInspector::class);
        $queue->shouldReceive('labQueueBacklog')->once()->andReturn(['total' => 0, 'queues' => []]);
        $queue->shouldReceive('hasAgentJob')->once()->with($agent->id, \Mockery::type('array'))->andReturnFalse();
        $this->app->instance(LabQueueJobInspector::class, $queue);
        $recovery = \Mockery::mock(LabReplayRecoveryService::class);
        $recovery->shouldReceive('prepare')->once()
            ->with(\Mockery::on(fn (LabAgent $row): bool => $row->is($agent)), 'screen', false)
            ->andReturn(['protocol' => LabReplayRecoveryService::PROTOCOL]);
        $this->app->instance(LabReplayRecoveryService::class, $recovery);

        $exit = Artisan::call('trading:recover-lab-evaluation-errors', [
            'symbol' => 'XAUUSD',
            '--timeframe' => 'H1',
            '--generation' => $generation->generation,
            '--limit' => 1,
            '--mode' => 'screen',
            '--after-runtime-schema-repair' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame('technical_quarantine', $agent->fresh()->lifecycle_status);
    }

    public function test_post_service_repair_preview_includes_quarantined_connection_reset_on_terminal_generation(): void
    {
        [$generation] = $this->canonicalSource();
        $generation->update(['status' => 'screened']);
        $agent = $this->agent($generation, $this->model('service-reset-candidate', []), []);
        $agent->update([
            'lifecycle_status' => 'technical_quarantine',
            'decision_reason' => 'Technical quarantine: evaluator error isolated by lifecycle cycle; strategy verdict withheld.',
        ]);
        LabEvaluationRun::create([
            'run_id' => 'service-reset-immutable-run',
            'lab_generation_id' => $generation->id,
            'lab_agent_id' => $agent->id,
            'model_version_id' => $agent->model_version_id,
            'phase' => 'screening',
            'mode' => 'screen',
            'status' => 'technical_error',
            'error_class' => 'Illuminate\\Http\\Client\\ConnectionException',
            'error_message' => 'cURL error 56: Recv failure: Connection was reset',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        Http::fake(['*' => Http::response(['strategies' => []])]);
        $queue = \Mockery::mock(LabQueueJobInspector::class);
        $queue->shouldReceive('labQueueBacklog')->once()->andReturn(['total' => 0, 'queues' => []]);
        $queue->shouldReceive('hasAgentJob')->once()->with($agent->id, \Mockery::type('array'))->andReturnFalse();
        $this->app->instance(LabQueueJobInspector::class, $queue);
        $recovery = \Mockery::mock(LabReplayRecoveryService::class);
        $recovery->shouldReceive('prepare')->once()
            ->with(\Mockery::on(fn (LabAgent $row): bool => $row->is($agent)), 'screen', false)
            ->andReturn(['protocol' => LabReplayRecoveryService::PROTOCOL]);
        $this->app->instance(LabReplayRecoveryService::class, $recovery);

        $exit = Artisan::call('trading:recover-lab-evaluation-errors', [
            'symbol' => 'XAUUSD',
            '--timeframe' => 'H1',
            '--generation' => $generation->generation,
            '--limit' => 1,
            '--mode' => 'screen',
            '--after-service-repair' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString((string) $agent->id, Artisan::output());
        $this->assertSame('technical_quarantine', $agent->fresh()->lifecycle_status);
    }

    public function test_causal_full_replay_admission_is_atomic_and_does_not_select_only_screen_winners(): void
    {
        [$generation] = $this->canonicalSource();
        $generation->update([
            'trigger_type' => 'learning_confirmation',
            'status' => 'screened',
            'trigger_context' => [
                'adaptive_evolution_policy' => [
                    'causal_learning_counterfactual_cohort' => ['status' => 'materialized'],
                ],
            ],
        ]);
        $guided = $this->agent($generation, $this->model('admission-guided', ['entry_threshold' => 2]), [
            'entry_threshold' => ['old' => 1, 'new' => 2],
        ]);
        $blinded = $this->agent($generation, $this->model('admission-blinded', ['other_gene' => 2]), [
            'other_gene' => ['old' => 1, 'new' => 2],
        ]);
        $control = $this->agent($generation, $this->model('admission-control', ['entry_threshold' => 1]), []);
        $experiment = AgentLearningCausalExperiment::create([
            'experiment_key' => str_repeat('a', 128),
            'lab_generation_id' => $generation->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'hybrid',
            'target' => 'profit_factor',
            'gene_key' => 'entry_threshold',
            'guided_agent_id' => $guided->id,
            'blinded_agent_id' => $blinded->id,
            'control_agent_id' => $control->id,
            'status' => 'ready_for_replay',
            'evidence' => [
                'construction_validation' => ['status' => 'ready_for_replay'],
                'promotion_evidence' => false,
            ],
        ]);
        foreach ([
            [$guided, 'memory_guided'],
            [$blinded, 'blinded'],
            [$control, 'frozen_control'],
        ] as [$agent, $role]) {
            $agent->modelVersion->update(['metadata' => [
                'causal_learning_cohort' => [
                    'protocol' => CausalLearningCohortPlannerService::PROTOCOL,
                    'experiment_id' => $experiment->id,
                    'role' => $role,
                    'status' => 'ready_for_replay',
                    'promotion_evidence' => false,
                ],
            ]]);
            CandidateGateDecision::create([
                'lab_agent_id' => $agent->id,
                'stage' => 'screening',
                'decision' => 'failed',
                'reason_codes' => ['FAILED_PROFIT_FACTOR'],
                'metrics' => [],
                'attribution_status' => 'agent_scoped',
                'evaluated_at' => now(),
            ]);
        }

        $evidence = app(LabImmutableEvidenceService::class);
        $this->completeScreeningEvidence($guided, $evidence, 'guided-screen');
        $this->completeScreeningEvidence($blinded, $evidence, 'blinded-screen');
        $incomplete = app(CausalLearningCohortService::class)
            ->fullReplayAdmission($guided->fresh(['generation', 'modelVersion']), $evidence);
        $this->assertFalse($incomplete['allowed']);
        $this->assertContains('CAUSAL_COHORT_SCREENING_EVIDENCE_INCOMPLETE', $incomplete['reason_codes']);

        $this->completeScreeningEvidence($control, $evidence, 'control-screen');
        $cohortBudget = new \ReflectionMethod(
            app(LabAgentEvaluationService::class),
            'fullReplayMaxCohortSize',
        );
        $cohortBudget->setAccessible(true);
        $this->assertSame(1, $cohortBudget->invoke(
            app(LabAgentEvaluationService::class),
            $guided->fresh(['generation', 'modelVersion']),
        ));
        foreach ([$guided, $blinded, $control] as $agent) {
            $fresh = $agent->fresh(['generation', 'modelVersion']);
            $admission = app(CausalLearningCohortService::class)->fullReplayAdmission($fresh, $evidence);
            $this->assertTrue($admission['allowed']);

            $job = new EvaluateLabAgentJob($fresh->id, $fresh->symbol, 'full');
            $this->assertLessThanOrEqual(1080, $job->timeout);
            $method = new \ReflectionMethod($job, 'fullValidationAdmission');
            $method->setAccessible(true);
            $workerAdmission = $method->invoke($job, $fresh, $evidence);
            $this->assertTrue($workerAdmission['allowed']);
            $this->assertNotContains('SCREENING_NOT_PASSED', $workerAdmission['reason_codes']);
        }
    }

    public function test_non_triplet_seat_in_learning_confirmation_generation_uses_its_own_replay_gate(): void
    {
        [$generation] = $this->canonicalSource();
        $generation->update([
            'trigger_type' => 'learning_confirmation',
            'trigger_context' => [
                'adaptive_evolution_policy' => [
                    'causal_learning_counterfactual_cohort' => ['status' => 'materialized'],
                ],
            ],
        ]);
        $guided = $this->agent($generation, $this->model('scope-guided', ['entry_threshold' => 2]), [
            'entry_threshold' => ['old' => 1, 'new' => 2],
        ]);
        $blinded = $this->agent($generation, $this->model('scope-blinded', ['other_gene' => 2]), [
            'other_gene' => ['old' => 1, 'new' => 2],
        ]);
        $control = $this->agent($generation, $this->model('scope-control', ['entry_threshold' => 1]), []);
        $independentSeat = $this->agent(
            $generation,
            $this->model('scope-independent-seat', ['lookback' => 24]),
            ['lookback' => ['old' => 20, 'new' => 24]],
        );
        $experiment = AgentLearningCausalExperiment::create([
            'experiment_key' => str_repeat('b', 128),
            'lab_generation_id' => $generation->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'hybrid',
            'target' => 'profit_factor',
            'gene_key' => 'entry_threshold',
            'guided_agent_id' => $guided->id,
            'blinded_agent_id' => $blinded->id,
            'control_agent_id' => $control->id,
            'status' => 'ready_for_replay',
            'evidence' => [
                'construction_validation' => ['status' => 'ready_for_replay'],
                'promotion_evidence' => false,
            ],
        ]);
        $guided->modelVersion->update(['metadata' => [
            'causal_learning_cohort' => [
                'protocol' => CausalLearningCohortPlannerService::PROTOCOL,
                'experiment_id' => $experiment->id,
                'role' => 'memory_guided',
                'status' => 'ready_for_replay',
                'promotion_evidence' => false,
            ],
        ]]);

        $service = app(CausalLearningCohortService::class);
        $evidence = app(LabImmutableEvidenceService::class);
        $independentAdmission = $service->fullReplayAdmission(
            $independentSeat->fresh(['generation', 'modelVersion']),
            $evidence,
        );
        $this->assertFalse($independentAdmission['applicable']);
        $this->assertSame([], $independentAdmission['reason_codes']);

        // Losing the contract on a durably referenced triplet arm is still a
        // fail-closed integrity error; it must not be mistaken for a normal
        // independent seat.
        $blindedAdmission = $service->fullReplayAdmission(
            $blinded->fresh(['generation', 'modelVersion']),
            $evidence,
        );
        $this->assertTrue($blindedAdmission['applicable']);
        $this->assertFalse($blindedAdmission['allowed']);
        $this->assertContains('CAUSAL_COHORT_PROTOCOL_INVALID', $blindedAdmission['reason_codes']);
    }

    public function test_bounded_batch_retry_exhaustion_is_a_retry_budget_failure_not_a_strategy_verdict(): void
    {
        [$generation] = $this->canonicalSource();
        $generation->update(['trigger_type' => 'learning_confirmation']);
        $agent = $this->agent($generation, $this->model('retry-budget-candidate', []), []);
        $agent->update([
            'lifecycle_status' => 'evaluation_error',
            'decision_reason' => 'Bounded screening batch exhausted operational retries; strategy verdict withheld.',
        ]);
        $method = new \ReflectionMethod(RecoverLabEvaluationErrors::class, 'isRetryBudgetFailure');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke(
            app(RecoverLabEvaluationErrors::class),
            $agent->fresh(['generation', 'modelVersion']),
        ));

        LabEvaluationRun::create([
            'run_id' => 'retry-budget-immutable-run',
            'lab_generation_id' => $generation->id,
            'lab_agent_id' => $agent->id,
            'model_version_id' => $agent->model_version_id,
            'phase' => 'screening',
            'mode' => 'screen',
            'status' => 'technical_error',
            'error_class' => 'Illuminate\\Queue\\MaxAttemptsExceededException',
            'error_message' => 'App\\Jobs\\EvaluateLabAgentJob has been attempted too many times.',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);
        $agent->update([
            'lifecycle_status' => 'technical_quarantine',
            'decision_reason' => 'Technical quarantine after bounded learning-lane transport failures; strategy verdict withheld.',
        ]);
        $this->assertTrue($method->invoke(
            app(RecoverLabEvaluationErrors::class),
            $agent->fresh(['generation', 'modelVersion']),
        ));

        $agent->update(['decision_reason' => 'Failed profit-factor quality gate.']);
        $this->assertFalse($method->invoke(
            app(RecoverLabEvaluationErrors::class),
            $agent->fresh(['generation', 'modelVersion']),
        ));
    }

    public function test_retry_budget_recovery_keeps_remaining_agents_visible_after_generation_quarantine(): void
    {
        [$generation] = $this->canonicalSource();
        $generation->update([
            'trigger_type' => 'learning_confirmation',
            'status' => 'technical_quarantine',
        ]);
        $agent = $this->agent($generation, $this->model('retry-budget-quarantine-candidate', []), []);
        $agent->update([
            'lifecycle_status' => 'technical_quarantine',
            'decision_reason' => 'Technical quarantine after bounded learning-lane transport failures; strategy verdict withheld.',
        ]);
        LabEvaluationRun::create([
            'run_id' => 'retry-budget-quarantine-immutable-run',
            'lab_generation_id' => $generation->id,
            'lab_agent_id' => $agent->id,
            'model_version_id' => $agent->model_version_id,
            'phase' => 'screening',
            'mode' => 'screen',
            'status' => 'technical_error',
            'error_class' => 'Illuminate\\Queue\\MaxAttemptsExceededException',
            'error_message' => 'App\\Jobs\\EvaluateLabAgentJob has been attempted too many times.',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        Http::fake(['*' => Http::response(['strategies' => []])]);
        $queue = \Mockery::mock(LabQueueJobInspector::class);
        $queue->shouldReceive('labQueueBacklog')->once()->andReturn(['total' => 0, 'queues' => []]);
        $queue->shouldReceive('hasAgentJob')->once()->with($agent->id, \Mockery::type('array'))->andReturnFalse();
        $this->app->instance(LabQueueJobInspector::class, $queue);
        $recovery = \Mockery::mock(LabReplayRecoveryService::class);
        $recovery->shouldReceive('prepare')->once()
            ->with(\Mockery::on(fn (LabAgent $row): bool => $row->is($agent)), 'screen', false)
            ->andReturn(['protocol' => LabReplayRecoveryService::PROTOCOL]);
        $this->app->instance(LabReplayRecoveryService::class, $recovery);

        $exit = Artisan::call('trading:recover-lab-evaluation-errors', [
            'symbol' => 'XAUUSD',
            '--timeframe' => 'H1',
            '--generation' => $generation->generation,
            '--limit' => 1,
            '--mode' => 'screen',
            '--after-retry-budget-repair' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString((string) $agent->id, Artisan::output());
        $this->assertSame('technical_quarantine', $agent->fresh()->lifecycle_status);
    }

    public function test_autonomous_recovery_seals_missing_frozen_snapshot_as_terminal_technical_history(): void
    {
        config()->set('services.lifecycle_orchestrator.autonomous_technical_recovery_enabled', true);
        [$generation] = $this->canonicalSource();
        $generation->update([
            'trigger_type' => 'learning_confirmation',
            'status' => 'technical_quarantine',
        ]);
        $agent = $this->agent($generation, $this->model('retry-budget-missing-snapshot', []), []);
        $agent->update([
            'lifecycle_status' => 'technical_quarantine',
            'decision_reason' => 'Technical quarantine after bounded learning-lane transport failures; strategy verdict withheld.',
        ]);
        LabEvaluationRun::create([
            'run_id' => 'retry-budget-missing-snapshot-run',
            'lab_generation_id' => $generation->id,
            'lab_agent_id' => $agent->id,
            'model_version_id' => $agent->model_version_id,
            'phase' => 'screening',
            'mode' => 'screen',
            'status' => 'technical_error',
            'error_class' => 'Illuminate\\Queue\\MaxAttemptsExceededException',
            'error_message' => 'App\\Jobs\\EvaluateLabAgentJob has been attempted too many times.',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        Http::fake(['*' => Http::response(['strategies' => []])]);
        $queue = \Mockery::mock(LabQueueJobInspector::class);
        $queue->shouldReceive('labQueueBacklog')->once()->andReturn(['total' => 0, 'queues' => []]);
        $queue->shouldReceive('hasAgentJob')->once()->with($agent->id, \Mockery::type('array'))->andReturnFalse();
        $this->app->instance(LabQueueJobInspector::class, $queue);
        $recovery = \Mockery::mock(LabReplayRecoveryService::class);
        $recovery->shouldReceive('prepare')->once()
            ->with(\Mockery::on(fn (LabAgent $row): bool => $row->is($agent)), 'screen', false)
            ->andThrow(new \RuntimeException('RECOVERY_DATASET_SNAPSHOT_MISSING_OR_HASH_MISMATCH:volume'));
        $this->app->instance(LabReplayRecoveryService::class, $recovery);

        $exit = Artisan::call('trading:recover-lab-evaluation-errors', [
            'symbol' => 'XAUUSD',
            '--timeframe' => 'H1',
            '--generation' => $generation->generation,
            '--limit' => 1,
            '--mode' => 'screen',
            '--after-retry-budget-repair' => true,
            '--apply' => true,
            '--autonomous' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $output = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([$agent->id], $output['terminally_reconciled_agent_ids']);
        $this->assertSame(0, $output['dispatched']);
        $this->assertSame(1, (int) data_get(
            $agent->modelVersion->fresh()->metadata,
            'retry_budget_repair_recovery_attempts',
        ));
        $this->assertSame(
            'FROZEN_RECOVERY_CONTRACT_UNAVAILABLE',
            data_get($agent->modelVersion->fresh()->metadata, 'technical_recovery_terminal_disposition.reason_code'),
        );
        $this->assertDatabaseHas('system_events', [
            'event_type' => 'lab_autonomous_recovery_terminal_disposition',
        ]);
    }

    public function test_causal_arm_cannot_write_ordinary_mutation_credit_before_triplet_settlement(): void
    {
        [$generation] = $this->canonicalSource();
        $generation->update(['trigger_type' => 'learning_confirmation']);
        $model = $this->model('causal-credit-boundary', ['entry_threshold' => 2], [
            'causal_learning_cohort' => [
                'protocol' => CausalLearningCohortPlannerService::PROTOCOL,
                'role' => 'memory_guided',
                'promotion_evidence' => false,
            ],
        ]);
        $agent = $this->agent($generation, $model, [
            'entry_threshold' => ['old' => 1, 'new' => 2],
        ]);
        $performance = ModelMarketPerformance::create([
            'model_version_id' => $model->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'hybrid',
            'status' => 'challenger',
            'forward_score' => 10,
            'rolling_windows_count' => 3,
            'rolling_forward_wins' => 3,
            'sample_count' => 30,
            'metrics' => [],
        ]);
        $result = [
            'train_score' => 0,
            'validation_score' => 0,
            'forward_score' => 10,
            'profit_factor' => 1.2,
            'total_trades' => 30,
            'max_drawdown_percent' => 5,
            'monte_carlo' => ['risk_of_ruin_percent' => 1],
            'is_overfit' => false,
        ];
        $method = new \ReflectionMethod(app(MarketChampionService::class), 'updateLabAgentAndMemory');
        $method->setAccessible(true);
        $arguments = [$performance, null, &$result];
        $method->invokeArgs(app(MarketChampionService::class), $arguments);

        $this->assertSame('rejected', $agent->fresh()->lifecycle_status);
        $this->assertSame(
            'withheld_until_causal_triplet_settlement',
            data_get($result, 'ordinary_learning_projection.status'),
        );
        $this->assertSame(0, MutationMemory::query()->where('lab_agent_id', $agent->id)->count());
        $this->assertNull(data_get($model->fresh()->metadata, 'skill_tree'));
    }

    public function test_canonical_pair_reconciliation_requires_and_binds_terminal_full_runs(): void
    {
        [$generation, $sourceLesson] = $this->canonicalSource();
        $guided = $this->agent($generation, $this->model('full-bind-guided', ['entry_threshold' => 2]), [
            'entry_threshold' => ['old' => 1, 'new' => 2],
        ]);
        $blinded = $this->agent($generation, $this->model('full-bind-blinded', ['entry_threshold' => 1.5]), [
            'entry_threshold' => ['old' => 1, 'new' => 1.5],
        ]);
        $control = $this->agent($generation, $this->model('full-bind-control', ['entry_threshold' => 1]), []);
        $guidedPair = $this->pair($generation, $guided, $control, .2, 'full-guided');
        $blindedPair = $this->pair($generation, $blinded, $control, -.1, 'full-blinded');
        $guidedPair->candidateResponseMap->update(['parameter_key' => 'entry_threshold', 'old_value' => 1, 'new_value' => 2]);
        $blindedPair->candidateResponseMap->update(['parameter_key' => 'entry_threshold', 'old_value' => 1, 'new_value' => 1.5]);
        $screenGuided = $this->completeScreeningEvidence($guided, app(LabImmutableEvidenceService::class), 'bind-screen-guided');
        $screenBlinded = $this->completeScreeningEvidence($blinded, app(LabImmutableEvidenceService::class), 'bind-screen-blinded');
        $screenControl = $this->completeScreeningEvidence($control, app(LabImmutableEvidenceService::class), 'bind-screen-control');
        $guidedPair->update(['candidate_evidence_run_id' => $screenGuided->run_id, 'control_evidence_run_id' => $screenControl->run_id]);
        $blindedPair->update(['candidate_evidence_run_id' => $screenBlinded->run_id, 'control_evidence_run_id' => $screenControl->run_id]);
        $guidedPerformance = $this->performance($guided, ['total_trades' => 30]);
        $blindedPerformance = $this->performance($blinded, ['total_trades' => 30]);
        $guidedRun = $this->terminalRun($guided, 'full-bind-guided-run', 'screening');
        $blindedRun = $this->terminalRun($blinded, 'full-bind-blinded-run', 'full_validation');
        $controlRun = $this->terminalRun($control, 'full-bind-control-run', 'full_validation');
        $windows = static fn (array $scores): array => collect($scores)->map(
            fn (float $score, int $index): array => [
                'key' => 'w'.($index + 1), 'start' => (2010 + $index * 2).'-01-01',
                'end' => (2011 + $index * 2).'-12-31', 'score' => $score,
            ],
        )->all();
        $experiment = AgentLearningCausalExperiment::create([
            'experiment_key' => str_repeat('f', 128), 'lab_generation_id' => $generation->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'target' => 'profit_factor', 'gene_key' => 'entry_threshold', 'source_lesson_id' => $sourceLesson->id,
            'guided_agent_id' => $guided->id, 'blinded_agent_id' => $blinded->id, 'control_agent_id' => $control->id,
            'status' => 'outcomes_pending', 'evidence' => [
                'construction_validation' => ['status' => 'ready_for_replay'],
                'outcomes' => [
                    'memory_guided' => ['agent_id' => $guided->id, 'pair_id' => $guidedPair->id, 'performance_id' => $guidedPerformance->id, 'evidence_run_id' => $guidedRun->run_id, 'windows' => $windows([3, 4, 5])],
                    'blinded' => ['agent_id' => $blinded->id, 'pair_id' => $blindedPair->id, 'performance_id' => $blindedPerformance->id, 'evidence_run_id' => $blindedRun->run_id, 'windows' => $windows([1, 2, 1])],
                    'frozen_control' => ['agent_id' => $control->id, 'pair_id' => $guidedPair->id, 'evidence_run_id' => $controlRun->run_id, 'windows' => $windows([2, 2, 2])],
                ],
                'promotion_evidence' => false,
            ],
        ]);
        $method = new \ReflectionMethod(CausalLearningConfirmationService::class, 'reconcileCanonicalPairs');
        $method->setAccessible(true);
        $service = app(CausalLearningConfirmationService::class);

        $pending = $method->invoke($service, $experiment->fresh());
        $this->assertSame('partially_settled', $pending['status']);
        $this->assertContains('memory_guided', $pending['pending_roles']);
        $this->assertSame($screenGuided->run_id, $guidedPair->fresh()->candidate_evidence_run_id);
        $this->assertSame(1, AgentLearningSettlement::query()->where('source_type', LabLearningLanePair::class)
            ->whereIn('source_id', [$guidedPair->id, $blindedPair->id])->count());

        $guidedRun->update(['phase' => 'full_validation']);
        $settled = $method->invoke($service, $experiment->fresh());
        $this->assertSame('settled', $settled['status']);
        $this->assertEqualsCanonicalizing([$guidedPair->id, $blindedPair->id], $settled['settled_pair_ids']);
        $this->assertSame($guidedRun->run_id, $guidedPair->fresh()->candidate_evidence_run_id);
        $this->assertSame($controlRun->run_id, $guidedPair->fresh()->control_evidence_run_id);
        $this->assertSame($screenGuided->run_id, data_get(
            $guidedPair->fresh()->metadata,
            'causal_full_replay_bindings.'.array_key_first((array) data_get($guidedPair->fresh()->metadata, 'causal_full_replay_bindings')).'.previous_candidate_run_id',
        ));
        $this->assertSame(2, AgentLearningSettlement::query()->where('source_type', LabLearningLanePair::class)
            ->whereIn('source_id', [$guidedPair->id, $blindedPair->id])->count());

        $duplicate = $method->invoke($service, $experiment->fresh());
        $this->assertSame('settled', $duplicate['status']);
        $this->assertSame(2, AgentLearningSettlement::query()->where('source_type', LabLearningLanePair::class)
            ->whereIn('source_id', [$guidedPair->id, $blindedPair->id])->count());
    }

    public function test_bounded_causal_timeout_is_recognized_as_repairable_full_queue_evidence(): void
    {
        $method = new \ReflectionMethod(RecoverLabFullEvaluationErrors::class, 'isFullQueueError');
        $method->setAccessible(true);
        $command = app(RecoverLabFullEvaluationErrors::class);

        $this->assertTrue($method->invoke(
            $command,
            'Full queue technical error [EVALUATION_ERROR]; strategy verdict withheld: Bounded AI replay exceeded 720s.',
        ));
        $this->assertTrue($method->invoke(
            $command,
            'Causal confirmation fold 2 exceeded its 230s budget; no learning credit was emitted.',
        ));

        [$generation] = $this->canonicalSource();
        $generation->update(['trigger_type' => 'learning_confirmation']);
        $model = $this->model('causal-transport-quarantine', [], [
            'causal_learning_cohort' => [
                'protocol' => CausalLearningCohortPlannerService::PROTOCOL,
                'role' => 'memory_guided',
                'promotion_evidence' => false,
            ],
        ]);
        $agent = $this->agent($generation, $model, []);
        $agent->update([
            'lifecycle_status' => 'technical_quarantine',
            'decision_reason' => 'Technical quarantine after bounded learning-lane transport failures; strategy verdict withheld.',
        ]);
        $quarantineMethod = new \ReflectionMethod(RecoverLabFullEvaluationErrors::class, 'isRepairableTechnicalQuarantine');
        $quarantineMethod->setAccessible(true);
        $this->assertTrue($quarantineMethod->invoke($command, $agent->fresh(['generation', 'modelVersion'])));
    }

    public function test_causal_replay_budgets_leave_fold_and_transport_headroom(): void
    {
        $service = app(LabAgentEvaluationService::class);
        $method = new \ReflectionMethod($service, 'causalPerFoldBudgetSeconds');
        $method->setAccessible(true);

        $this->assertSame(180, $method->invoke($service));
        $this->assertSame(900, config('services.lab_selection.causal_replay_hard_timeout_seconds'));
        $this->assertSame(960, config('services.lab_selection.causal_replay_timeout_seconds'));
        $this->assertGreaterThan(
            config('services.lab_selection.causal_replay_hard_timeout_seconds'),
            config('services.lab_selection.causal_replay_timeout_seconds'),
        );
    }

    public function test_only_legacy_ninety_second_cartridge_fold_timeout_is_code_repairable(): void
    {
        [$generation] = $this->canonicalSource();
        $generation->update(['trigger_type' => 'skill_cartridge_transplant']);
        $model = $this->model('legacy-cartridge-timeout', [], [
            'skill_cartridge_transplant' => [
                'protocol' => CanonicalSkillCartridgeService::PROTOCOL,
                'confirmation_contract' => ['protocol' => 'bounded_skill_cartridge_confirmation_v1'],
            ],
        ]);
        $agent = $this->agent($generation, $model, []);
        $agent->update([
            'lifecycle_status' => 'technical_quarantine',
            'decision_reason' => 'Technical quarantine after bounded learning-lane transport failures; strategy verdict withheld.',
        ]);
        $run = LabEvaluationRun::create([
            'run_id' => 'legacy-cartridge-fold-timeout',
            'lab_generation_id' => $generation->id,
            'lab_agent_id' => $agent->id,
            'model_version_id' => $model->id,
            'phase' => 'full_validation',
            'mode' => 'full',
            'status' => 'technical_error',
            'error_message' => 'TimeoutError: Causal confirmation fold 1 exceeded its 90s budget; remaining folds were not executed and no learning credit was emitted.',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);
        $method = new \ReflectionMethod(RecoverLabFullEvaluationErrors::class, 'isRepairableTechnicalQuarantine');
        $method->setAccessible(true);
        $command = app(RecoverLabFullEvaluationErrors::class);

        $this->assertTrue($method->invoke($command, $agent->fresh(['generation', 'modelVersion'])));

        $run->update(['error_message' => 'TimeoutError: Causal confirmation fold 1 exceeded its 240s budget; no learning credit was emitted.']);
        $this->assertFalse($method->invoke($command, $agent->fresh(['generation', 'modelVersion'])));
    }

    public function test_legacy_skill_cartridge_contract_receives_bounded_fold_budget_at_request_boundary(): void
    {
        [$generation] = $this->canonicalSource();
        $model = $this->model('legacy-cartridge-budget', [], [
            'skill_cartridge_transplant' => [
                'protocol' => CanonicalSkillCartridgeService::PROTOCOL,
                'confirmation_contract' => [
                    'protocol' => 'bounded_skill_cartridge_confirmation_v1',
                    'fold_count' => 3,
                    'max_rows_per_fold' => 4096,
                ],
            ],
        ]);
        $agent = $this->agent($generation, $model, []);
        $method = new \ReflectionMethod(LabAgentEvaluationService::class, 'skillCartridgeConfirmationContract');
        $method->setAccessible(true);

        $contract = $method->invoke(
            app(LabAgentEvaluationService::class),
            $agent->fresh('modelVersion'),
        );

        $this->assertSame(CanonicalSkillCartridgeService::PER_FOLD_BUDGET_SECONDS, $contract['per_fold_budget_seconds']);
        $this->assertSame(4096, $contract['max_rows_per_fold']);
    }

    public function test_expired_full_replay_job_is_recoverable_after_service_repair_even_when_breaker_hid_the_reason(): void
    {
        [$generation] = $this->canonicalSource();
        $generation->update(['trigger_type' => 'edge_genesis', 'status' => 'queued']);
        $model = $this->model('expired-service-job', ['entry_threshold' => 2]);
        $agent = $this->agent($generation, $model, ['entry_threshold' => ['old' => 1, 'new' => 2]]);
        $agent->update([
            'lifecycle_status' => 'technical_quarantine',
            'decision_reason' => 'Technical quarantine after bounded learning-lane transport failures; strategy verdict withheld.',
        ]);
        LabEvaluationRun::create([
            'run_id' => 'expired-service-job-run', 'lab_generation_id' => $generation->id,
            'lab_agent_id' => $agent->id, 'model_version_id' => $model->id,
            'phase' => 'full_validation', 'mode' => 'full', 'status' => 'technical_error',
            'error_class' => 'Illuminate\\Queue\\MaxAttemptsExceededException',
            'error_message' => 'EvaluateLabAgentJob has been attempted too many times.',
            'started_at' => now()->subMinute(), 'finished_at' => now(),
        ]);

        $method = new \ReflectionMethod(RecoverLabFullEvaluationErrors::class, 'isRepairableServiceFailure');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke(
            app(RecoverLabFullEvaluationErrors::class),
            $agent->fresh(['generation', 'modelVersion']),
        ));
    }

    public function test_memory_first_selector_builds_the_exact_retrieved_cartridge_intervention(): void
    {
        [, $lesson] = $this->canonicalSource();
        $this->projectCartridgeForLesson($lesson);
        $packet = app(LearningKernelService::class)->retrieveCanonicalLesson(
            $lesson->id, 'XAUUSD', 'H1', 'hybrid', [],
        );

        $selection = app(CausalLearningMutationIntentService::class)->selectIntervention(
            $packet,
            ['entry_threshold' => 1],
            ['entry_threshold' => ['integer', 0, 5]],
            ['entry_threshold'],
        );

        $this->assertSame('selected', $selection['status']);
        $this->assertSame('retrieve_then_build_exact_mutation', $selection['selection_order']);
        $this->assertSame($lesson->id, $selection['source_lesson_id']);
        $this->assertSame(['entry_threshold' => 2], $selection['parameters']);
        $this->assertSame('compatible_cartridge_found', data_get($selection, 'skill_cartridge.status'));
    }

    public function test_learning_pulse_counts_decision_packets_instead_of_prompt_rows(): void
    {
        [, $lesson] = $this->canonicalSource();
        $settlement = AgentLearningSettlement::query()->firstOrFail();
        $packetId = (string) Str::uuid();
        foreach (['consumed', 'rejected', 'rejected'] as $index => $state) {
            AgentLearningRetrieval::create([
                'retrieval_id' => (string) Str::uuid(), 'packet_id' => $packetId,
                'episode_id' => $settlement->episode_id, 'agent_learning_lesson_id' => $lesson->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
                'retrieval_state' => $state, 'match_level' => 'exact_context', 'context' => [],
                'metadata' => ['causal_application' => $index === 0],
                'consumed_at' => $state === 'consumed' ? now() : null,
                'outcome_linked_at' => $state === 'consumed' ? now() : null,
            ]);
        }

        $pulse = app(LearningPulseService::class)->pulse('XAUUSD', 'H1', 'hybrid');

        $this->assertSame(3, $pulse['retrieval_rows']);
        $this->assertSame(1, $pulse['retrieval_packets']);
        $this->assertSame(1.0, $pulse['retrieval_hit_rate']);
        $this->assertSame(1.0, $pulse['memory_utilization']);
        $this->assertSame(1.0, $pulse['causal_memory_utilization']);
        $this->assertSame(0.0, $pulse['negative_settlement_rate']);
        $this->assertSame(0.0, $pulse['repeated_failure_rate']);
    }

    /** @return array{0: LabGeneration, 1: AgentLearningLesson} */
    private function canonicalSource(): array
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD', 'name' => 'Causal lab', 'timeframe' => 'H1',
            'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse',
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'test',
            'population_size' => 2, 'status' => 'completed', 'data_fingerprint' => str_repeat('d', 64), 'trigger_context' => [],
        ]);
        $sourceModel = $this->model('source-candidate', ['entry_threshold' => 2]);
        $controlModel = $this->model('source-control', ['entry_threshold' => 1]);
        $candidate = $this->agent($generation, $sourceModel, ['entry_threshold' => ['old' => 1, 'new' => 2]]);
        $control = $this->agent($generation, $controlModel, []);
        $pair = $this->pair($generation, $candidate, $control, .10, 's');
        $episode = app(LearningKernelService::class)->openEpisode($candidate, [
            'decision_key' => 'source-episode-'.Str::uuid(), 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'hybrid', 'context' => [],
        ]);
        AgentLearningSettlement::create([
            'settlement_id' => (string) Str::uuid(), 'episode_id' => $episode->id,
            'source_key' => 'source-settlement-'.$pair->id, 'source_type' => LabLearningLanePair::class,
            'source_id' => $pair->id, 'outcome_status' => 'settled', 'failure_class' => 'profit_factor',
            'evidence_state' => 'positive', 'selection_reward' => 1, 'hard_failure' => false,
            'outcome' => [], 'settled_at' => now(),
        ]);
        $lesson = AgentLearningLesson::create([
            'lesson_id' => (string) Str::uuid(), 'lesson_hash' => hash('sha512', 'source-lesson-'.Str::uuid()),
            'lab_agent_id' => $candidate->id, 'model_version_id' => $sourceModel->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'lesson_type' => 'skill_lesson', 'status' => 'provisional', 'failure_class' => 'profit_factor',
            'parameter_key' => 'entry_threshold', 'outcome' => 'beneficial',
            'evidence' => ['pair_id' => $pair->id, 'old_value' => ['value' => 1], 'new_value' => ['value' => 2]],
            'observed_at' => now(),
        ]);

        return [$generation->fresh('laboratory'), $lesson];
    }

    /** @return array<string,mixed> */
    private function projectCartridgeForLesson(AgentLearningLesson $lesson): array
    {
        $pair = LabLearningLanePair::query()
            ->with(['candidateAgent.modelVersion', 'controlAgent.modelVersion', 'candidateResponseMap', 'controlResponseMap'])
            ->findOrFail((int) data_get($lesson->evidence, 'pair_id'));
        $gene = (string) $lesson->parameter_key;
        $change = (array) data_get($pair->candidateAgent->parameter_diff, $gene, []);
        $map = $pair->candidateResponseMap;
        $map->update([
            'parameter_key' => $gene,
            'old_value' => ['value' => data_get($change, 'old')],
            'new_value' => ['value' => data_get($change, 'new')],
        ]);
        $settlement = AgentLearningSettlement::query()
            ->where('source_type', LabLearningLanePair::class)
            ->where('source_id', $pair->id)
            ->latest('id')
            ->firstOrFail();
        $projection = app(CanonicalSkillCartridgeService::class)->project(
            $pair->fresh(['candidateAgent.modelVersion', 'controlAgent.modelVersion', 'candidateResponseMap', 'controlResponseMap']),
            [],
            $map->fresh(),
            $settlement,
        );
        $this->assertNotNull($projection);

        return $projection;
    }

    /** @return array{0: LabLearningLanePair, 1: AgentLearningLesson} */
    private function outcomePair(LabGeneration $generation, LabAgent $candidate, LabAgent $control, float $delta, string $suffix): array
    {
        $pair = $this->pair($generation, $candidate, $control, $delta, $suffix);
        $lesson = AgentLearningLesson::create([
            'lesson_id' => (string) Str::uuid(), 'lesson_hash' => hash('sha512', 'outcome-'.$suffix.Str::uuid()),
            'lab_agent_id' => $candidate->id, 'model_version_id' => $candidate->model_version_id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'lesson_type' => 'skill_lesson', 'status' => 'provisional', 'failure_class' => 'profit_factor',
            'parameter_key' => 'entry_threshold', 'outcome' => 'beneficial',
            'evidence' => ['pair_id' => $pair->id], 'observed_at' => now(),
        ]);

        return [$pair, $lesson];
    }

    private function pair(LabGeneration $generation, LabAgent $candidate, LabAgent $control, float $delta, string $suffix): LabLearningLanePair
    {
        $dataHash = str_repeat('d', 64);
        $executionHash = str_repeat('e', 64);
        $controlMap = LabMutationResponseMap::create([
            'response_key' => hash('sha256', 'control-'.$suffix.Str::uuid()), 'stage' => 'screening', 'status' => 'control',
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'target' => 'profit_factor',
            'lab_agent_id' => $control->id, 'metadata' => ['control_contract' => [
                'protocol' => 'frozen_control_v2', 'control_only' => true, 'role' => 'control',
                'generation_id' => $generation->id, 'data_hash' => $dataHash, 'execution_hash' => $executionHash,
            ]],
        ]);
        $candidateMap = LabMutationResponseMap::create([
            'response_key' => hash('sha256', 'candidate-'.$suffix.Str::uuid()), 'stage' => 'screening', 'status' => 'screen_observed',
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'target' => 'profit_factor',
            'lab_agent_id' => $candidate->id,
        ]);

        return LabLearningLanePair::create([
            'pair_key' => hash('sha256', 'pair-'.$suffix.Str::uuid()), 'lab_generation_id' => $generation->id,
            'candidate_agent_id' => $candidate->id, 'control_agent_id' => $control->id,
            'candidate_response_map_id' => $candidateMap->id, 'control_response_map_id' => $controlMap->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'target' => 'profit_factor', 'baseline_source' => 'control', 'status' => 'lesson_compiled',
            'candidate_data_hash' => $dataHash, 'control_data_hash' => $dataHash,
            'candidate_execution_hash' => $executionHash, 'control_execution_hash' => $executionHash,
            'pair_integrity_status' => 'verified', 'same_generation' => true,
            'candidate_metrics' => ['profit_factor' => 1 + $delta], 'control_metrics' => ['profit_factor' => 1],
            'target_delta' => ['delta' => $delta, 'improved' => $delta > 0],
            'non_target_regression' => [
                'protocol' => 'test_explicit_non_target_v1', 'status' => 'passed', 'safe' => true,
            ],
        ]);
    }

    private function intent(LabGeneration $generation, LabAgent $agent, string $type, array $lessons): AgentLearningMutationIntent
    {
        return AgentLearningMutationIntent::create([
            'intent_id' => (string) Str::uuid(), 'intent_key' => hash('sha512', $type.$agent->id),
            'packet_id' => (string) Str::uuid(), 'lab_generation_id' => $generation->id,
            'model_version_id' => $agent->model_version_id, 'lab_agent_id' => $agent->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'target' => 'profit_factor', 'selected_gene' => 'entry_threshold', 'influence_type' => $type,
            'status' => 'bound', 'retrieved_lesson_ids' => $lessons, 'selected_lesson_ids' => $lessons,
            'causally_applied_lesson_ids' => $lessons, 'rejected_lesson_ids' => [],
            'causally_applied_retrieval_ids' => [], 'old_value' => ['value' => 1], 'new_value' => ['value' => 2],
            'baseline_hash' => str_repeat('a', 128), 'parameter_hash' => str_repeat('b', 128),
            'mutation_hash' => hash('sha512', json_encode($agent->parameter_diff)),
            'sealed_at' => now(), 'bound_at' => now(),
            'metadata' => ['causal_order' => ['retrieval_sequence' => 1, 'mutation_seal_sequence' => 2, 'agent_persistence_sequence' => 3]],
        ]);
    }

    private function model(string $name, array $parameters, array $metadata = []): ModelVersion
    {
        return ModelVersion::create([
            'name' => $name, 'strategy' => $name, 'version' => 'v1', 'generation' => 1,
            'status' => 'testing', 'parameters' => $parameters, 'metadata' => $metadata, 'evidence_status' => 'valid',
        ]);
    }

    private function agent(LabGeneration $generation, ModelVersion $model, array $diff): LabAgent
    {
        return LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'origin' => 'test', 'lifecycle_status' => 'screened', 'parameter_diff' => $diff,
        ]);
    }

    private function validReceiptMetadata(): array
    {
        return ['generation_target' => 'profit_factor', 'hypothesis_contract' => ['changed_gene' => 'entry_threshold']];
    }

    private function validReceipt(): array
    {
        return [
            'protocol' => LearningReceiptService::PROTOCOL, 'status' => 'provisional',
            'changed_gene' => 'entry_threshold',
            'causal_influence' => 'memory_guided',
            'causally_applied_lesson_ids' => [1],
            'integrity' => ['valid' => true], 'promotion_evidence' => false,
        ];
    }

    private function completeScreeningEvidence(LabAgent $agent, LabImmutableEvidenceService $evidence, string $requestId): LabEvaluationRun
    {
        $run = $evidence->beginRun($agent, 'screening', 'screen', ['source' => 'causal_admission_test']);
        $evidence->attachRequest($run, [
            'symbol' => $agent->symbol,
            'timeframe' => $agent->timeframe,
            'candles' => [['time' => '2026-01-01T00:00:00Z', 'close' => 2000]],
        ], ['request_id' => $requestId]);
        $evidence->finishRun($run, 'completed', [
            'total_trades' => 0,
            'trade_ledger_hash' => hash('sha256', $requestId.'-ledger'),
            'trade_ledger' => [],
            'trades' => [],
            'displayed_trade_count' => 0,
            'decision_trace' => [[
                'candle_time' => '2026-01-01T00:00:00Z',
                'event_type' => 'signal_evaluation',
                'action' => 'WAIT',
                'accepted' => false,
            ]],
            'data_quality' => ['decision_trace' => [
                'requested' => true,
                'complete' => true,
                'evaluated_candle_count' => 1,
            ]],
        ]);

        return $run->fresh();
    }

    private function terminalRun(LabAgent $agent, string $runId, string $phase): LabEvaluationRun
    {
        return LabEvaluationRun::create([
            'run_id' => $runId,
            'lab_generation_id' => $agent->lab_generation_id,
            'lab_agent_id' => $agent->id,
            'model_version_id' => $agent->model_version_id,
            'phase' => $phase,
            'mode' => 'replay',
            'status' => 'completed',
            'started_at' => now()->subSecond(),
            'finished_at' => now(),
            'metrics' => ['total_trades' => 30],
        ]);
    }

    private function performance(LabAgent $agent, array $metrics): ModelMarketPerformance
    {
        return ModelMarketPerformance::create([
            'model_version_id' => $agent->model_version_id,
            'symbol' => $agent->symbol,
            'timeframe' => $agent->timeframe,
            'strategy_family' => $agent->strategy_family,
            'status' => 'challenger',
            'evidence_status' => 'valid',
            'sample_count' => 30,
            'rolling_windows_count' => 3,
            'rolling_forward_wins' => 2,
            'metrics' => $metrics,
        ]);
    }
}
