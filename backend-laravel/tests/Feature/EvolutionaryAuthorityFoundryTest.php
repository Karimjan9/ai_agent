<?php

namespace Tests\Feature;

use App\Jobs\EvaluateLabAgentJob;
use App\Models\AiLaboratory;
use App\Models\CandidateGateDecision;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\LabLearningLanePair;
use App\Models\LabMutationResponseMap;
use App\Models\LabSkillZooEntry;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Services\AutonomousLearningProgressDirectorService;
use App\Services\CausalCompoundingKernelService;
use App\Services\ContextualCausalTraitCapsuleService;
use App\Services\DirectResearchReplayAdmissionService;
use App\Services\EvolutionaryAuthorityFoundryService;
use App\Services\LabAgentEvaluationService;
use App\Services\LabInstrumentResearchService;
use App\Services\LearningLaneService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EvolutionaryAuthorityFoundryTest extends TestCase
{
    use RefreshDatabase;

    public function test_parentless_confirmed_mentor_uses_verified_causal_control_for_incubator(): void
    {
        Queue::fake();
        [$mentor, $control] = $this->confirmedMentorWithVerifiedControl();

        $result = app(EvolutionaryAuthorityFoundryService::class)->materializeIncubator($mentor);

        $this->assertSame('queued', $result['status'], json_encode($result, JSON_UNESCAPED_SLASHES));
        $generation = LabGeneration::findOrFail($result['generation_id']);
        $this->assertSame('authority_incubator', $generation->trigger_type);
        $this->assertSame('verified_frozen_control_pair', data_get($generation->trigger_context, 'baseline_source'));
        $this->assertCount(20, $generation->agents);
        $this->assertSame(20, $generation->population_size);
        $primary = $generation->agents->where('origin', 'authority_incubator');
        $this->assertCount(5, $primary);
        $this->assertTrue($generation->agents->every(
            fn (LabAgent $agent): bool => $agent->parent_a_model_version_id === null,
        ));
        $this->assertTrue($primary->every(
            fn (LabAgent $agent): bool => data_get($agent->modelVersion->metadata, 'skill_mentor') === null
                && data_get($agent->modelVersion->metadata, 'authority_source_skill.status') === 'confirmed'
                && (int) data_get($agent->modelVersion->metadata, 'causal_baseline_model_version_id') === (int) $control->model_version_id,
        ));
        $capsuleHash = (string) data_get($generation->trigger_context, 'trait_capsule_hash');
        $this->assertNotSame('', $capsuleHash);
        $this->assertTrue($primary->every(fn (LabAgent $agent): bool => hash_equals(
            $capsuleHash,
            (string) data_get($agent->modelVersion->metadata, 'authority_incubator.trait_capsule_hash'),
        )));
        $assignment = app(LabInstrumentResearchService::class)->assignment($primary->first());
        $this->assertSame('assigned', $assignment['status']);
        $this->assertSame(
            data_get($generation->trigger_context, 'instrument_bundle.instrument_keys'),
            data_get($assignment, 'bundle_identity.instrument_keys'),
        );
        $this->assertSame(5, $primary->pluck('modelVersion.strategy')->unique()->count());
        $this->assertTrue($primary->every(
            fn (LabAgent $agent): bool => data_get($agent->modelVersion->metadata, 'base_strategy') === 'hybrid',
        ));
        $this->assertSame(CausalCompoundingKernelService::PROTOCOL, data_get($generation->trigger_context, 'causal_compounding_kernel.protocol'));
        $this->assertSame(14, data_get($generation->trigger_context, 'causal_compounding_kernel.paired_discovery_seats'));
        $this->assertSame(1, data_get($generation->trigger_context, 'causal_compounding_kernel.uncertainty_abstain_seats'));
        $kernelPairs = $generation->agents
            ->filter(fn (LabAgent $agent): bool => filled(data_get($agent->modelVersion->metadata, 'causal_compounding_kernel.pair_key'))
                && data_get($agent->modelVersion->metadata, 'causal_compounding_kernel.role') !== 'uncertainty_abstain')
            ->groupBy(fn (LabAgent $agent): string => (string) data_get($agent->modelVersion->metadata, 'causal_compounding_kernel.pair_key'));
        $this->assertCount(7, $kernelPairs);
        $this->assertTrue($kernelPairs->every(fn ($pair): bool => $pair->count() === 2
            && $pair->pluck('modelVersion.metadata.causal_compounding_kernel.role')->sort()->values()->all() === ['candidate', 'control']));
        $closedCohort = new \ReflectionMethod(app(LabAgentEvaluationService::class), 'closedResearchCohort');
        $filtered = $closedCohort->invoke(
            app(LabAgentEvaluationService::class),
            $generation->agents,
            $primary->first()->modelVersion,
            $primary->first(),
        );
        $this->assertCount(5, $filtered);
        $this->assertTrue($filtered->every(fn (LabAgent $agent): bool => $agent->origin === 'authority_incubator'));
        $kernelIntents = DB::table('agent_learning_mutation_intents')
            ->where('lab_generation_id', $generation->id)->get();
        $this->assertCount(15, $kernelIntents);
        $this->assertTrue($kernelIntents->every(fn ($intent): bool => $intent->status === 'bound'
            && (int) $intent->lab_agent_id > 0
            && $intent->sealed_at !== null
            && $intent->bound_at !== null
            && $intent->sealed_at <= $intent->bound_at
        ));
        Queue::assertPushed(EvaluateLabAgentJob::class, 20);
    }

    public function test_genetic_parent_without_verified_control_cannot_fake_an_incubator_baseline(): void
    {
        Queue::fake();
        [$mentor, $control] = $this->confirmedMentorWithVerifiedControl();
        LabLearningLanePair::query()->delete();
        $mentor->update(['parent_a_model_version_id' => $control->model_version_id]);

        $result = app(EvolutionaryAuthorityFoundryService::class)->materializeIncubator($mentor->fresh(['modelVersion', 'generation.laboratory']));

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('VERIFIED_CAUSAL_BASELINE_MISSING', $result['reason_code']);
        $this->assertDatabaseCount('skill_incubation_trials', 0);
        Queue::assertNothingPushed();
    }

    public function test_kernel_candidate_can_only_pair_with_its_exact_declared_control(): void
    {
        Queue::fake();
        [$mentor] = $this->confirmedMentorWithVerifiedControl();
        $result = app(EvolutionaryAuthorityFoundryService::class)->materializeIncubator($mentor);
        $generation = LabGeneration::with('agents.modelVersion')->findOrFail($result['generation_id']);
        $candidate = $generation->agents->firstWhere('origin', 'compounding_discovery_candidate');
        $pairKey = (string) data_get($candidate?->modelVersion?->metadata, 'control_pair_contract.pair_key');
        $exactControl = $generation->agents->first(fn (LabAgent $agent): bool => $agent->origin === 'compounding_discovery_control'
            && (string) data_get($agent->modelVersion?->metadata, 'control_pair_contract.pair_key') === $pairKey
        );
        $decoyControl = $generation->agents->first(fn (LabAgent $agent): bool => $agent->origin === 'compounding_discovery_control'
            && (string) data_get($agent->modelVersion?->metadata, 'control_pair_contract.pair_key') !== $pairKey
        );
        $dataHash = str_repeat('d', 64);
        $executionHash = str_repeat('e', 64);
        $candidateMap = LabMutationResponseMap::create([
            'response_key' => hash('sha256', 'kernel-candidate-'.$candidate->id),
            'stage' => 'screening', 'status' => 'observed', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'hybrid', 'target' => 'profit_factor',
            'parameter_key' => array_key_first((array) $candidate->parameter_diff),
            'lab_agent_id' => $candidate->id, 'model_version_id' => $candidate->model_version_id,
            'observed_metrics' => ['profit_factor' => 1.10, 'total_trades' => 30],
            'non_target_regression' => ['safe' => true, 'status' => 'passed'],
            'metadata' => ['data_manifest_hash' => $dataHash, 'execution_hash' => $executionHash],
        ]);
        foreach ([$exactControl, $decoyControl] as $control) {
            LabMutationResponseMap::create([
                'response_key' => hash('sha256', 'kernel-control-'.$control->id),
                'stage' => 'screening', 'status' => 'control', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
                'strategy_family' => 'hybrid', 'lab_agent_id' => $control->id,
                'model_version_id' => $control->model_version_id,
                'observed_metrics' => ['profit_factor' => 1.00, 'total_trades' => 30],
                'metadata' => ['data_manifest_hash' => $dataHash, 'execution_hash' => $executionHash,
                    'control_contract' => ['protocol' => 'frozen_control_v2', 'control_only' => true,
                        'role' => 'control', 'generation_id' => $generation->id,
                        'data_hash' => $dataHash, 'execution_hash' => $executionHash]],
            ]);
        }

        $paired = app(LearningLaneService::class)->pairScreeningObservation($candidate, [
            'data_manifest' => ['sha256' => $dataHash],
            'execution_contract' => ['execution_hash' => $executionHash],
        ], $candidateMap->toArray());

        $this->assertNotNull($paired);
        $this->assertSame($exactControl->id, data_get($paired, 'control_agent_id'));
        $this->assertNotSame($decoyControl->id, data_get($paired, 'control_agent_id'));
        $this->assertSame('verified', data_get($paired, 'pair_integrity_status'));
    }

    public function test_director_skips_newer_unverifiable_mentor_instead_of_starving_valid_one(): void
    {
        [$validMentor] = $this->confirmedMentorWithVerifiedControl();
        $model = ModelVersion::create([
            'name' => 'unverifiable-newer-mentor', 'strategy' => 'hybrid', 'version' => 'v2',
            'generation' => 1, 'status' => 'testing', 'parameters' => $validMentor->modelVersion->parameters,
            'metadata' => ['skill_mentor' => ['status' => 'confirmed', 'parameter_key' => 'minimum_confidence', 'target' => 'profit_factor']],
            'evidence_status' => 'valid',
        ]);
        LabAgent::create([
            'lab_generation_id' => $validMentor->lab_generation_id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'origin' => 'test', 'lifecycle_status' => 'rejected', 'parameter_diff' => [],
        ]);
        $selector = new \ReflectionMethod(app(AutonomousLearningProgressDirectorService::class), 'nextConfirmedMentor');

        $selected = $selector->invoke(app(AutonomousLearningProgressDirectorService::class), 'XAUUSD', 'H1');

        $this->assertNotNull($selected);
        $this->assertSame($validMentor->id, $selected->id);
    }

    public function test_descendant_settlement_preserves_passport_and_unlocks_eligible_parent(): void
    {
        Queue::fake();
        [$mentor] = $this->confirmedMentorWithVerifiedControl();
        $service = app(EvolutionaryAuthorityFoundryService::class);
        $model = $mentor->modelVersion;
        $dataHash = str_repeat('d', 64);
        $executionHash = str_repeat('e', 64);

        $service->refreshAuthority($model, $mentor, [
            'passed' => true, 'elite_passport' => 'passed', 'passport_hash' => 'immutable-passport',
        ]);
        $authority = $this->recordPassedIncubator($service, $mentor);
        $this->assertSame('skill_mentor', $authority['stage']);
        $this->assertSame('immutable-passport', data_get($authority, 'evidence.passport.passport_hash'));

        $cohort = $service->materializeDescendantCohort($model, $mentor);
        $this->assertSame('queued', $cohort['status']);
        $generation = LabGeneration::with('agents.modelVersion')->findOrFail($cohort['generation_id']);
        $this->assertCount(20, $generation->agents);
        $this->assertSame(20, $generation->population_size);
        $this->assertSame(CausalCompoundingKernelService::PROTOCOL, data_get($generation->trigger_context, 'causal_compounding_kernel.protocol'));
        $children = $generation->agents->filter(fn (LabAgent $agent): bool => in_array(data_get($agent->modelVersion->metadata, 'authority_descendant.arm'), EvolutionaryAuthorityFoundryService::DESCENDANT_ARMS, true));
        $this->assertCount(2, $children);
        $ablations = $generation->agents->filter(fn (LabAgent $agent): bool => in_array(data_get($agent->modelVersion->metadata, 'authority_descendant.arm'), EvolutionaryAuthorityFoundryService::DESCENDANT_ABLATION_ARMS, true));
        $this->assertCount(2, $ablations);
        $this->assertSame(5, $generation->agents
            ->filter(fn (LabAgent $agent): bool => filled(data_get($agent->modelVersion->metadata, 'authority_descendant.arm')))
            ->pluck('modelVersion.strategy')->unique()->count());
        $closedCohort = new \ReflectionMethod(app(LabAgentEvaluationService::class), 'closedResearchCohort');
        $filtered = $closedCohort->invoke(
            app(LabAgentEvaluationService::class),
            $generation->agents,
            $children->first()->modelVersion,
            $children->first(),
        );
        $this->assertCount(5, $filtered);
        $this->assertTrue($filtered->every(
            fn (LabAgent $agent): bool => filled(data_get($agent->modelVersion->metadata, 'authority_descendant.arm')),
        ));
        $this->assertTrue($children->every(fn (LabAgent $agent): bool => count((array) $agent->parameter_diff) === 1));
        $this->assertTrue($children->every(fn (LabAgent $agent): bool => data_get($agent->modelVersion->metadata, 'skill_mentor') === null
            && data_get($agent->modelVersion->metadata, 'elite_agent_passport') === null));
        $this->assertSame(2, $children->pluck('parameter_diff')->map(fn (array $diff): string => (string) array_key_first($diff))->unique()->count());
        $replayAdmission = app(DirectResearchReplayAdmissionService::class)->inspect($children->first());
        $this->assertTrue($replayAdmission['applicable']);
        $this->assertTrue($replayAdmission['allowed']);
        $this->assertSame([], $replayAdmission['reason_codes']);

        $control = $generation->agents->first(fn (LabAgent $agent): bool => data_get($agent->modelVersion->metadata, 'authority_descendant.arm') === 'mentor_control');
        $this->assertNotNull($control);
        foreach ($generation->agents as $index => $agent) {
            $isControl = $agent->is($control);
            $isAblation = (bool) data_get($agent->modelVersion->metadata, 'authority_descendant.trait_ablated', false);
            $metrics = $this->passingMetrics(
                $dataHash,
                $executionHash,
                $isControl ? 1.10 : ($isAblation ? 1.20 : 1.40 + ($index / 100)),
                $isControl ? 10.0 : ($isAblation ? 9.5 : 9.0),
                $agent,
            );
            $performance = ModelMarketPerformance::create([
                'model_version_id' => $agent->model_version_id, 'symbol' => $agent->symbol,
                'timeframe' => $agent->timeframe, 'strategy_family' => $agent->strategy_family,
                'sample_count' => 60, 'rolling_windows_count' => 3, 'rolling_forward_wins' => 3,
                'metrics' => $metrics,
            ]);
            CandidateGateDecision::create([
                'model_market_performance_id' => $performance->id, 'lab_agent_id' => $agent->id,
                'stage' => 'statistical_forward_gate', 'decision' => 'failed',
                'reason_codes' => ['AUTHORITY_FOUNDRY_RESEARCH_ONLY'], 'metrics' => $metrics, 'evaluated_at' => now(),
            ]);
        }

        $settlement = $service->settleDescendantOutcome($control, $this->passingMetrics($dataHash, $executionHash, 1.10, 10.0, $control));

        $this->assertSame('settled', $settlement['status']);
        $this->assertSame('eligible_parent', $settlement['stage']);
        $this->assertTrue($settlement['parent_eligible']);
        $this->assertSame(2, DB::table('descendant_value_trials')->where('mentor_model_version_id', $model->id)->count());
        $this->assertTrue(DB::table('descendant_value_trials')->where('mentor_model_version_id', $model->id)->get()->every(
            fn ($trial): bool => data_get(json_decode($trial->evidence, true), 'trait_incremental_over_ablation') === true
                && (int) data_get(json_decode($trial->evidence, true), 'ablated_child_model_version_id') > 0
                && in_array(data_get(json_decode($trial->evidence, true), 'context_trust.status'), ['probation', 'context_confirmed'], true),
        ));
        $trust = DB::table('lab_parent_context_scores')->where('parent_model_version_id', $model->id)->first();
        $this->assertNotNull($trust);
        $this->assertSame('context_confirmed', $trust->status);
        $this->assertSame(2, (int) $trust->success_count);
        $this->assertSame(0, (int) $trust->failure_count);
        $this->assertSame('immutable-passport', data_get($settlement, 'evidence.passport.passport_hash'));
    }

    public function test_foundry_scores_the_declared_gate_and_protects_other_gate_margins(): void
    {
        $service = app(EvolutionaryAuthorityFoundryService::class);
        $targetImproved = new \ReflectionMethod($service, 'targetImproved');
        $nonTargetRegression = new \ReflectionMethod($service, 'nonTargetRegression');
        $control = [
            'profit_factor' => 1.20, 'total_trades' => 60, 'max_drawdown_percent' => 10.0,
            'pf_attribution' => ['stress_cost' => ['profit_factor' => 1.00]],
        ];
        $safeCandidate = [
            'profit_factor' => 1.20, 'total_trades' => 60, 'max_drawdown_percent' => 10.0,
            'pf_attribution' => ['stress_cost' => ['profit_factor' => 1.10]],
        ];
        $harmfulCandidate = [...$safeCandidate, 'max_drawdown_percent' => 12.0];

        $this->assertTrue($targetImproved->invoke($service, 'stress_cost', $safeCandidate, $control));
        $this->assertFalse($nonTargetRegression->invoke($service, $safeCandidate, $control));
        $this->assertTrue($nonTargetRegression->invoke($service, $harmfulCandidate, $control));
        $this->assertFalse($targetImproved->invoke($service, 'calendar_stability', $safeCandidate, $control));
    }

    public function test_descendant_search_is_bounded_and_never_retests_a_gene(): void
    {
        Queue::fake();
        [$mentor] = $this->confirmedMentorWithVerifiedControl();
        $service = app(EvolutionaryAuthorityFoundryService::class);
        $this->recordPassedIncubator($service, $mentor);
        $tested = [];

        for ($attempt = 1; $attempt <= EvolutionaryAuthorityFoundryService::DESCENDANT_COHORT_LIMIT; $attempt++) {
            $cohort = $service->materializeDescendantCohort($mentor->modelVersion, $mentor);
            $this->assertSame('queued', $cohort['status']);
            $generation = LabGeneration::findOrFail($cohort['generation_id']);
            $genes = (array) data_get($generation->trigger_context, 'mutated_genes', []);
            $this->assertSame($attempt, (int) data_get($generation->trigger_context, 'cohort_attempt'));
            $this->assertSame([], array_values(array_intersect($tested, $genes)));
            $tested = [...$tested, ...$genes];
            $generation->update(['status' => 'completed', 'completed_at' => now()]);
            $generation->agents()->update(['lifecycle_status' => 'rejected']);
        }

        $exhausted = $service->materializeDescendantCohort($mentor->modelVersion, $mentor);
        $this->assertSame('search_exhausted', $exhausted['status']);
        $this->assertSame('BOUNDED_DESCENDANT_SEARCH_EXHAUSTED', $exhausted['reason_code']);
        $this->assertCount(6, array_unique($tested));
    }

    /** @return array{0:LabAgent,1:LabAgent} */
    private function confirmedMentorWithVerifiedControl(): array
    {
        $lab = AiLaboratory::create([
            'name' => 'Authority Foundry', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse',
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'test',
            'trigger_context' => [], 'population_size' => 2, 'status' => 'screened',
        ]);
        $base = app(StrategyParameterSchemaService::class)->defaults('hybrid');
        $skill = $base;
        $skill['minimum_confidence'] = 1.10;
        $pairKey = hash('sha512', 'authority-pair-'.$generation->id);
        $controlModel = ModelVersion::create([
            'name' => 'authority-control', 'strategy' => 'hybrid', 'version' => 'v1-control',
            'generation' => 1, 'status' => 'testing', 'parameters' => $base,
            'metadata' => ['control_pair_contract' => ['pair_key' => $pairKey, 'role' => 'control']],
            'evidence_status' => 'valid',
        ]);
        $mentorModel = ModelVersion::create([
            'name' => 'authority-mentor', 'strategy' => 'hybrid', 'version' => 'v1-mentor',
            'generation' => 1, 'status' => 'testing', 'parameters' => $skill,
            'metadata' => [
                'skill_mentor' => ['status' => 'confirmed', 'parameter_key' => 'minimum_confidence', 'target' => 'profit_factor'],
                'control_pair_contract' => ['pair_key' => $pairKey, 'role' => 'candidate'],
            ],
            'evidence_status' => 'valid',
        ]);
        $control = LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $controlModel->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'origin' => 'frozen_control', 'lifecycle_status' => 'screened', 'parameter_diff' => [],
        ]);
        $mentor = LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $mentorModel->id,
            'parent_a_model_version_id' => null, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'hybrid', 'origin' => 'learning_lane', 'lifecycle_status' => 'screened',
            'parameter_diff' => ['minimum_confidence' => ['old' => 1.0, 'new' => 1.1]],
        ]);
        $dataHash = str_repeat('d', 64);
        $executionHash = str_repeat('e', 64);
        $candidateMap = LabMutationResponseMap::create([
            'response_key' => hash('sha256', 'authority-candidate-'.$mentor->id), 'stage' => 'full_replay',
            'status' => 'confirmed', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'target' => 'profit_factor', 'parameter_key' => 'minimum_confidence', 'lab_agent_id' => $mentor->id,
            'model_version_id' => $mentorModel->id, 'metadata' => ['data_manifest_hash' => $dataHash, 'execution_hash' => $executionHash],
        ]);
        $controlMap = LabMutationResponseMap::create([
            'response_key' => hash('sha256', 'authority-control-'.$control->id), 'stage' => 'full_replay',
            'status' => 'control', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'lab_agent_id' => $control->id, 'model_version_id' => $controlModel->id,
            'metadata' => ['control_contract' => ['protocol' => 'frozen_control_v2', 'control_only' => true,
                'role' => 'control', 'generation_id' => $generation->id, 'data_hash' => $dataHash, 'execution_hash' => $executionHash]],
        ]);
        $pair = LabLearningLanePair::create([
            'pair_key' => $pairKey, 'lab_generation_id' => $generation->id,
            'candidate_agent_id' => $mentor->id, 'control_agent_id' => $control->id,
            'candidate_response_map_id' => $candidateMap->id, 'control_response_map_id' => $controlMap->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'target' => 'profit_factor',
            'baseline_source' => 'control', 'status' => 'learning_observed', 'pair_integrity_status' => 'verified',
            'same_generation' => true, 'candidate_data_hash' => $dataHash, 'control_data_hash' => $dataHash,
            'candidate_execution_hash' => $executionHash, 'control_execution_hash' => $executionHash,
            'failure_signature' => ['state' => [
                'regime' => 'trend_up', 'volatility' => 'normal', 'session' => 'london', 'direction' => 'BUY',
            ]],
        ]);

        $mentor = $mentor->fresh(['modelVersion', 'generation.agents.modelVersion', 'generation.laboratory']);
        $assignment = app(LabInstrumentResearchService::class)->assignment($mentor);
        $context = ['regime' => 'trend_up', 'volatility' => 'normal', 'session' => 'london', 'direction' => 'BUY'];
        $result = $this->attestedInstrumentResult($assignment, $context);
        $capsule = app(ContextualCausalTraitCapsuleService::class)->compile(
            $pair->fresh(['controlAgent.modelVersion']),
            $mentor->fresh('modelVersion'),
            $candidateMap,
            $result,
            $context,
            ['old_value' => 1.0, 'tested_value' => 1.1, 'direction' => 'increase', 'reversible' => true],
            ['target' => 'profit_factor', 'mean_delta' => .20, 'positive_windows' => 3, 'total_windows' => 3],
            ['non_target_regression' => false],
            ['receipt_ids' => [1, 2, 3], 'response_map_ids' => [$candidateMap->id]],
            'component_confirmed',
            3,
        );
        $this->assertTrue(app(ContextualCausalTraitCapsuleService::class)->assess($capsule, 'minimum_confidence')['valid']);
        LabSkillZooEntry::create([
            'skill_key' => hash('sha256', 'authority-skill-'.$mentor->id),
            'cartridge_key' => hash('sha256', 'authority-cartridge-'.$mentor->id),
            'revision' => 3, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'module_key' => 'risk', 'niche_key' => 'trend_up|normal|london', 'gene_key' => 'minimum_confidence',
            'lab_agent_id' => $mentor->id, 'model_version_id' => $mentor->model_version_id,
            'lab_mutation_response_map_id' => $candidateMap->id, 'causal_baseline_agent_id' => $control->id,
            'quality_score' => .20, 'confidence' => 1.0, 'status' => 'confirmed',
            'component_status' => 'component_confirmed', 'organism_viability' => 'viable',
            'evidence' => ['trait_capsule' => $capsule, 'promotion_evidence' => false],
        ]);

        return [$mentor->fresh(['modelVersion', 'generation.laboratory']), $control->fresh('modelVersion')];
    }

    /** @return array<string,mixed> */
    private function passingMetrics(string $dataHash, string $executionHash, float $profitFactor, float $drawdown, ?LabAgent $agent = null): array
    {
        $metrics = [
            'data_manifest' => ['sha256' => $dataHash], 'execution_contract' => ['execution_hash' => $executionHash],
            'evidence_run_id' => hash('sha256', $profitFactor.'|'.$drawdown), 'total_trades' => 60,
            'profit_factor' => $profitFactor, 'max_drawdown_percent' => $drawdown, 'is_overfit' => false,
            'forward_window_protocol' => ['window_keys' => ['w1', 'w2', 'w3'], 'observed_windows' => 3,
                'positive_windows' => 3, 'independence_verified' => true, 'overlap_detected' => false],
            'statistical_evidence' => ['edge_quality' => ['worst_regime_pf' => 1.05]],
        ];
        if ($agent) {
            $agent = $agent->fresh(['modelVersion', 'generation']);
            $assignment = app(LabInstrumentResearchService::class)->assignment($agent);
            $capsule = (array) data_get($agent->modelVersion->metadata, 'authority_descendant.trait_capsule',
                data_get($agent->modelVersion->metadata, 'authority_incubator.trait_capsule', []));
            $metrics['instrument_research_trace'] = $this->attestedInstrumentResult(
                $assignment,
                (array) data_get($capsule, 'activation_context.predicate', []),
                $profitFactor,
                $drawdown,
            )['instrument_research_trace'];
        }

        return $metrics;
    }

    /** @return array<string,mixed> */
    private function recordPassedIncubator(EvolutionaryAuthorityFoundryService $service, LabAgent $mentor): array
    {
        $resolution = app(\App\Services\CanonicalSkillCartridgeService::class)->traitCapsuleForMentor(
            $mentor->modelVersion,
            $mentor,
            'minimum_confidence',
        );
        $capsule = (array) data_get($resolution, 'capsule', []);
        $authority = [];
        foreach (EvolutionaryAuthorityFoundryService::INCUBATOR_ARMS as $arm) {
            $authority = $service->recordIncubationArm($mentor, [
                'arm' => $arm, 'fold_stage' => 'final', 'status' => 'passed',
                'data_hash' => str_repeat('d', 64), 'execution_hash' => str_repeat('e', 64),
                'trait_capsule_hash' => data_get($capsule, 'capsule_hash'),
                'activation_context_hash' => data_get($capsule, 'activation_context.context_hash'),
                'instrument_bundle_hash' => data_get($capsule, 'instrument_bundle.bundle_hash'),
                'single_component_change' => ! in_array($arm, ['frozen_control', 'skill_ablation'], true),
                'target_gate_improved' => ! in_array($arm, ['frozen_control', 'skill_ablation'], true),
                'non_target_regression' => false, 'window_keys' => ['w1', 'w2', 'w3'],
            ]);
        }

        return $authority;
    }

    /** @return array<string,mixed> */
    private function attestedInstrumentResult(array $assignment, array $context, float $profitFactor = 1.2, float $drawdown = 5.0): array
    {
        return [
            'instrument_research_trace' => [
                'protocol' => 'lab_instrument_runtime_trace_v1', 'status' => 'consumed',
                'assignment_hash' => data_get($assignment, 'assignment_hash'),
                'assignment_hash_valid' => true, 'parameter_hash_valid' => true, 'runtime_bindings_valid' => true,
                'instruments' => collect((array) data_get($assignment, 'selected', []))->map(fn (array $selected): array => [
                    'instrument_key' => $selected['instrument_key'], 'status' => 'consumed',
                    'parameter_bindings' => $selected['parameter_bindings'], 'promotion_evidence' => false,
                ])->values()->all(),
                'context_slices' => [[
                    'context_key' => implode('|', array_filter([
                        data_get($context, 'regime'), data_get($context, 'volatility'), data_get($context, 'session'), data_get($context, 'direction'),
                    ])),
                    'context' => $context,
                    'metrics' => ['trades' => 30, 'net_pf' => $profitFactor, 'net_profit_percent' => 3.0,
                        'max_drawdown_percent' => $drawdown, 'execution_cost_percent' => .1],
                    'powered' => true, 'promotion_evidence' => false,
                ]],
                'promotion_evidence' => false,
            ],
        ];
    }
}
