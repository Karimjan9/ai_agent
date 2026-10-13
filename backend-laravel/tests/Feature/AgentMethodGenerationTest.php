<?php

namespace Tests\Feature;

use App\Jobs\ProcessLabScreeningLearningProjection;
use App\Models\AiLaboratory;
use App\Models\CandidateGateDecision;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Services\AgentMethodGenerationService;
use App\Services\AgentMethodLearningService;
use App\Services\AgentMethodStudyPreparationService;
use App\Services\ExecutionContractService;
use App\Services\InstrumentMethodStudyService;
use App\Models\ResearchExperimentWorkItem;
use App\Services\InstrumentMethodLibraryService;
use App\Services\InstrumentMethodResearchBindingService;
use App\Services\LabImmutableEvidenceService;
use App\Services\ResearchPaperEpochContractService;
use App\Services\StrategyParameterSchemaService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AgentMethodGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', 'method-generation-test-only-server-key');
        $this->travelTo(CarbonImmutable::parse('2026-10-13T06:00:00Z'));
        Storage::fake('method_generation_fixture');
        config()->set('services.lab_evidence.disk', 'method_generation_fixture');
    }

    public function test_curated_defaults_freeze_new_own_policy_and_remain_valid_after_default_config_changes(): void
    {
        $recipe = $this->recipe();
        config()->set('services.instrument_methods.generation_recipe_hashes', [$recipe]);
        $child = $this->model();
        $params = $child->parameters; $risk = $child->metadata['risk_governor'];
        $beforeMethods = DB::table('instrument_method_revisions')->get()->toJson();
        $owner = app(AgentMethodGenerationService::class);
        $result = $owner->prepareFreshChild($child, null, ['generation_id' => 2, 'slot' => 1]);
        $this->assertSame('prospective_child_policy_attached', $result['status'], json_encode($result));
        $policy = app(AgentMethodLearningService::class)->policy($result['policy_hash']);
        $this->assertSame('model:'.$child->id, $policy['agent_key']);
        $this->assertSame('curated_default', $policy['learning_provenance']['mode']);
        $this->assertSame([$recipe], $policy['library_contract']['recipe_hashes']);
        $this->assertSame($params, $child->fresh()->parameters);
        $this->assertSame($risk, $child->fresh()->metadata['risk_governor']);
        $this->assertSame($beforeMethods, DB::table('instrument_method_revisions')->get()->toJson());
        $this->assertFalse($result['mastery_proven']); $this->assertFalse($result['economic_authority']);
        config()->set('services.instrument_methods.generation_recipe_hashes', []);
        $this->assertArrayHasKey('policy_hash', app(AgentMethodLearningService::class)->policy($result['policy_hash']));
        $this->assertSame($result['policy_hash'], $owner->prepareFreshChild($child, null)['policy_hash']);
        $this->assertDatabaseCount('agent_method_policy_revisions', 1);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_real_original_experience_is_proposed_once_and_rebased_to_legal_new_child_before_any_seal(): void
    {
        [$parent, $policy, $run] = $this->originalParent();
        $before = $parent->fresh()->toArray();
        $owner = app(AgentMethodGenerationService::class);
        $one = $owner->proposeFromOriginalRun($run);
        $two = $owner->proposeFromOriginalRun($run);
        $this->assertSame('original_policy_proposals', $one['status']);
        $this->assertSame('frozen_diagnostic_proposal', $one['proposals'][0]['proposal_status'], json_encode($one));
        $this->assertSame($one['proposals'][0]['policy_hash'], $two['proposals'][0]['policy_hash']);
        $this->assertTrue($two['proposals'][0]['already_proposed']);
        $child = $this->model(['lookback' => $parent->parameters['lookback'] + 1]);
        $childBefore = $child->parameters;
        $result = $owner->prepareFreshChild($child, $parent, ['generation_id' => 2, 'slot' => 2]);
        $this->assertSame('prospective_child_policy_attached', $result['status'], json_encode($result));
        $learned = app(AgentMethodLearningService::class)->policy($result['policy_hash']);
        $this->assertSame('model:'.$child->id, $learned['agent_key']);
        $this->assertNotSame($policy['policy_hash'], $learned['policy_hash']);
        $this->assertSame($policy['library_hash'], $learned['library_hash']);
        $this->assertSame('original_parent_observation', $learned['learning_provenance']['mode']);
        $this->assertEquals($one['proposals'][0]['rules'], $learned['rules']);
        $this->assertSame($childBefore, $child->fresh()->parameters);
        $this->assertSame($before, $parent->fresh()->toArray());
        $this->assertDatabaseCount('model_versions', 2);
        $this->assertDatabaseCount('lab_agents', 1);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        // Recompilation is prospective and context/data-bound, not copied old exports.
        $contract = app(InstrumentMethodResearchBindingService::class)->compilePolicy($learned['policy_hash'],
            ['strategy_family' => 'breakout', 'parameters' => $childBefore], $this->scope(str_repeat('e', 64)), 'model:'.$child->id);
        $this->assertSame($childBefore, $contract['runtime_parameters']);
        $this->assertSame(str_repeat('e', 64), $contract['data_hash']);
        DB::table('agent_method_experiences')->update(['facts' => json_encode(['forged' => true])]);
        $this->assertSame('blocked', app(AgentMethodLearningService::class)->policy($learned['policy_hash'])['status']);
    }

    public function test_protected_seats_and_unrequested_generation_do_not_receive_unilateral_methods(): void
    {
        $recipe = $this->recipe();
        config()->set('services.instrument_methods.generation_recipe_hashes', [$recipe]);
        $child = $this->model(); $before = $child->fresh()->toArray();
        $generation = $this->generation();
        $owner = app(AgentMethodGenerationService::class);
        $this->assertSame('not_requested', $owner->prepareConstructorChild($child, null, $generation, 1, [])['status']);
        $generation->update(['trigger_context' => ['instrument_method_generation_learning' => ['enabled' => true, 'recipe_hashes' => [$recipe]]]]);
        config()->set('services.instrument_methods.generation_recipe_hashes', []);
        foreach ([['causal_learning_cohort' => ['role' => 'memory_guided']],
            ['cooperative_experiment_block' => ['arm' => 'control']], ['role' => 'uncertainty_abstain'],
            ['role' => 'adversarial_guard']] as $niche) {
            $this->assertSame('METHOD_GENERATION_PROTECTED_OR_COMPARATIVE_OWNER_UNCHANGED',
                $owner->prepareConstructorChild($child, null, $generation, 1, $niche)['reason']);
        }
        $this->assertSame($before, $child->fresh()->toArray());
        $this->assertDatabaseCount('agent_method_policy_revisions', 0);
        $result = $owner->prepareConstructorChild($child, null, $generation, 1, []);
        $this->assertSame('prospective_child_policy_attached', $result['status'], json_encode($result));
    }

    public function test_method_study_marker_excludes_all_generic_learning_and_credit_projection_before_outbox(): void
    {
        $generation = $this->generation();
        $generation->update(['trigger_context' => ['instrument_method_study_execution' => ['study_hash' => str_repeat('a', 64)]]]);
        $model = $this->model();
        $agent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'breakout', 'origin' => 'method-study', 'lifecycle_status' => 'screened']);
        $decision = CandidateGateDecision::create(['lab_agent_id' => $agent->id, 'stage' => 'screening', 'decision' => 'failed',
            'reason_codes' => [], 'metrics' => [], 'evaluated_at' => now()]);
        $run = LabEvaluationRun::create(['run_id' => (string) Str::uuid(), 'lab_generation_id' => $generation->id,
            'lab_agent_id' => $agent->id, 'model_version_id' => $model->id, 'phase' => 'screening', 'mode' => 'research',
            'status' => 'completed', 'started_at' => now(), 'data_hash' => str_repeat('a', 64)]);
        $job = new ProcessLabScreeningLearningProjection($agent->id, $run->run_id, $decision->id, []);
        $parameters = (new \ReflectionMethod($job, 'handle'))->getParameters(); $dependencies = [];
        foreach ($parameters as $parameter) {
            $type = $parameter->getType()->getName(); $dependency = Mockery::mock($type);
            if ($type === LabImmutableEvidenceService::class) {
                $dependency->shouldReceive('findRun')->once()->with($run->run_id)->andReturn($run);
                $dependency->shouldNotReceive('learningEligibility');
            }
            $dependencies[] = $dependency;
        }
        $job->handle(...$dependencies);
        $this->assertDatabaseCount('agent_method_policy_revisions', 0);
        $this->assertDatabaseCount('agent_method_experiences', 0);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        $this->assertDatabaseCount('screening_learning_outbox', 0);
    }

    public function test_unsupported_or_attempted_child_and_unlearned_parent_remain_explicit_dependencies(): void
    {
        config()->set('services.instrument_methods.generation_recipe_hashes', [$this->recipe()]);
        $parent = $this->model(); $owner = app(AgentMethodGenerationService::class);
        $one = $owner->prepareFreshChild($parent, null);
        $this->assertSame('prospective_child_policy_attached', $one['status'], json_encode($one));
        $child = $this->model();
        $this->assertSame('METHOD_GENERATION_PARENT_ORIGINAL_EXPERIENCES_REQUIRED', $owner->prepareFreshChild($child, $parent)['reason']);
        $this->assertArrayNotHasKey(AgentMethodGenerationService::METADATA, $child->fresh()->metadata);
        $native = $this->model();
        $native->update(['metadata' => [...$native->metadata, 'specialist_council' => ['protocol' => 'actual-native-owner']]]);
        $this->assertSame('METHOD_GENERATION_EXISTING_TYPED_CONSTRUCTOR_REQUIRED', $owner->prepareFreshChild($native, null)['reason']);
        $generation = $this->generation();
        LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $child->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'breakout', 'origin' => 'fixture', 'lifecycle_status' => 'draft']);
        $this->assertSame('METHOD_GENERATION_UNUSED_CONSTRUCTOR_CHILD_REQUIRED', $owner->prepareFreshChild($child, null)['reason']);
    }

    public function test_caller_cannot_copy_or_forge_generation_proof_or_edit_bound_risk(): void
    {
        config()->set('services.instrument_methods.generation_recipe_hashes', [$this->recipe()]);
        $child = $this->model();
        $result = app(AgentMethodGenerationService::class)->prepareFreshChild($child, null);
        $this->assertSame('prospective_child_policy_attached', $result['status'], json_encode($result));
        $policy = app(AgentMethodLearningService::class)->policy($result['policy_hash']);
        $public = array_intersect_key($policy, array_flip(['agent_key', 'revision', 'library_hash', 'library_contract', 'rules', 'default_action', 'learning_provenance']));
        $this->assertSame('POLICY_GENERATION_REQUIRES_SERVER_DERIVATION', app(AgentMethodLearningService::class)->registerPolicy($public)['reason']);
        $copy = $this->model(); $proof = $policy['learning_provenance'];
        $proof['target_model_id'] = (int) $copy->id;
        $proof['physical_hash'] = $policy['learning_provenance']['physical_hash'];
        $proof['handoff_hash'] = app(ResearchPaperEpochContractService::class)->parameterHash(array_diff_key($proof, ['handoff_hash' => true, 'server_seal' => true]));
        $copy->update(['metadata' => [...$copy->metadata, AgentMethodGenerationService::METADATA => $proof]]);
        $this->assertSame('blocked', app(AgentMethodLearningService::class)->registerGenerationPolicy($copy->id)['status']);
        $child->refresh(); $metadata = $child->metadata; $metadata['risk_governor']['risk_per_trade'] = 2.0;
        $child->update(['metadata' => $metadata]);
        $this->assertSame('blocked', app(AgentMethodLearningService::class)->policy($result['policy_hash'])['status']);
        $this->assertDatabaseCount('agent_method_policy_revisions', 1);
    }

    public function test_own_learned_child_registers_one_exact_four_carrier_work_without_mutating_parent_library_or_source(): void
    {
        [$child, $input] = $this->policyStudyInput();
        $childBefore = $child->fresh()->toArray(); $libraryBefore = DB::table('instrument_method_revisions')->get()->toJson();
        $count = ModelVersion::count(); $policies = DB::table('agent_method_policy_revisions')->count();
        $owner = app(AgentMethodStudyPreparationService::class);
        $prepared = $owner->prepare($input);
        $this->assertSame('own_policy_study_registered', $prepared['status'], json_encode($prepared));
        $this->assertSame(4, $prepared['carriers_created']);
        $this->assertFalse($prepared['mastery_proven']); $this->assertFalse($prepared['out_of_sample']);
        $this->assertSame($count + 4, ModelVersion::count());
        $this->assertDatabaseCount('research_experiment_work_items', 1);
        $this->assertDatabaseCount('lab_agents', 1); // Only the original synthetic parent fixture, no dispatch here.
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        $design = app(InstrumentMethodStudyService::class)->originalDesign($prepared['study_hash']);
        foreach ($prepared['arms'] as $arm => $identity) {
            $model = ModelVersion::findOrFail($identity['model_id']);
            $policy = app(AgentMethodLearningService::class)->policy($identity['policy_hash']);
            $this->assertSame('model:'.$model->id, $policy['agent_key']);
            $this->assertSame(AgentMethodStudyPreparationService::PROTOCOL, $policy['learning_provenance']['protocol']);
            $this->assertSame($child->parameters, $model->parameters);
            $this->assertSame($child->metadata['execution_contract'], $model->metadata['execution_contract']);
            $this->assertSame($design['arms']['L1A1']['baseline_hash'], $design['arms'][$arm]['baseline_hash']);
        }
        $again = $owner->prepare($input);
        $this->assertSame('original_policy_study_exists', $again['status'], json_encode($again));
        $this->assertSame($prepared['study_hash'], $again['study_hash']);
        $this->assertSame($count + 4, ModelVersion::count());
        $this->assertSame($policies + 4, DB::table('agent_method_policy_revisions')->count());
        $changed = $input; $changed['study']['resource_caps']['max_runtime_seconds'] = 121;
        $this->assertSame('METHOD_POLICY_STUDY_ORIGINAL_PREPARATION_IS_IMMUTABLE', $owner->prepare($changed)['reason']);
        $this->assertSame($childBefore, $child->fresh()->toArray());
        $this->assertSame($libraryBefore, DB::table('instrument_method_revisions')->get()->toJson());
        $work = ResearchExperimentWorkItem::findOrFail($prepared['work_id']);
        $this->assertSame(\App\Services\InstrumentMethodStudyExecutionService::WORK_TYPE, $work->work_type);
        $this->assertSame(0, (int) $work->attempts);
    }

    public function test_own_policy_study_rejects_copied_provenance_and_keeps_all_real_physical_metadata_in_comparison(): void
    {
        [$child, $input] = $this->policyStudyInput();
        $owner = app(AgentMethodStudyPreparationService::class);
        $result = $owner->prepare($input);
        $this->assertSame('own_policy_study_registered', $result['status'], json_encode($result));
        $identity = $result['arms']['L2A2']; $model = ModelVersion::findOrFail($identity['model_id']);
        $policy = app(AgentMethodLearningService::class)->policy($identity['policy_hash']);
        $public = array_intersect_key($policy, array_flip(['agent_key', 'revision', 'library_hash', 'library_contract', 'rules', 'default_action', 'learning_provenance']));
        $this->assertSame('POLICY_STUDY_REQUIRES_SERVER_DERIVATION', app(AgentMethodLearningService::class)->registerPolicy($public)['reason']);
        $originalMetadata = $model->metadata; $parameters = $model->parameters;
        $metadata = $originalMetadata; $metadata['risk_governor']['risk_per_trade'] = 2;
        $model->update(['metadata' => $metadata]);
        $this->assertSame('blocked', app(AgentMethodLearningService::class)->policy($identity['policy_hash'])['status']);
        $this->assertSame('METHOD_POLICY_STUDY_ORIGINAL_PREPARATION_IS_IMMUTABLE', $owner->prepare($input)['reason']);
        $model->update(['metadata' => $originalMetadata, 'parameters' => [...$parameters, 'lookback' => $parameters['lookback'] + 1]]);
        $this->assertSame('blocked', app(AgentMethodLearningService::class)->policy($identity['policy_hash'])['status']);
        $model->update(['parameters' => $parameters]);
        $this->assertArrayHasKey('policy_hash', app(AgentMethodLearningService::class)->policy($identity['policy_hash']));
        $sourceMetadata = $child->metadata; $sourceMetadata['execution_contract']['parameters']['spread_points'] += 1;
        $child->update(['metadata' => $sourceMetadata]);
        $this->assertSame('blocked', app(AgentMethodLearningService::class)->policy($identity['policy_hash'])['status']);
        $this->assertSame('METHOD_POLICY_STUDY_VERIFIED_ORIGINAL_OWN_CHILD_REQUIRED', $owner->prepare($input)['reason']);
        $this->assertDatabaseCount('research_experiment_work_items', 1);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_invalid_preparation_rolls_back_all_fresh_carriers_and_proposals_are_not_market_authority(): void
    {
        [$child, $input] = $this->policyStudyInput();
        $count = ModelVersion::count(); $policies = DB::table('agent_method_policy_revisions')->count();
        $input['study']['scope']['evaluated_start'] = '2026-01-01T00:00:00Z';
        $input['study']['scope']['evaluated_end'] = '2026-01-02T00:00:00Z';
        $blocked = app(AgentMethodStudyPreparationService::class)->prepare($input);
        $this->assertSame('blocked', $blocked['status'], json_encode($blocked));
        $this->assertSame($count, ModelVersion::count());
        $this->assertSame($policies, DB::table('agent_method_policy_revisions')->count());
        $this->assertDatabaseCount('instrument_method_studies', 0);
        $this->assertDatabaseCount('research_experiment_work_items', 0);
        $this->assertFalse($blocked['economic_authority']);
    }

    public function test_an_unselected_extra_recipe_cannot_silently_change_the_whole_library_treatment(): void
    {
        [$child, $input] = $this->policyStudyInput();
        $library = app(InstrumentMethodLibraryService::class);
        $existing = $library->inspectRecipe($input['libraries']['L1'][0])['contract'];
        $extra = $library->registerRecipe(['recipe_key' => 'different-unselected-recipe', 'revision' => 1,
            'method_hashes' => $existing['method_hashes'], 'context_predicate' => $existing['context_predicate']]);
        $this->assertSame('registered_research_only', $extra['status']);
        $input['libraries']['L2'][] = $extra['recipe_hash'];
        $count = ModelVersion::count();
        $blocked = app(AgentMethodStudyPreparationService::class)->prepare($input);
        $this->assertSame('METHOD_POLICY_STUDY_WHOLE_LIBRARY_CLASS_SET_CHANGED', $blocked['reason'], json_encode($blocked));
        $this->assertSame($count, ModelVersion::count());
        $this->assertDatabaseCount('research_experiment_work_items', 0);
        $this->assertDatabaseCount('instrument_method_studies', 0);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_typed_no_effect_preflight_does_not_create_carriers_work_or_a_generation(): void
    {
        [$child, $input] = $this->policyStudyInput();
        $count = ModelVersion::count(); $policies = DB::table('agent_method_policy_revisions')->count();
        // Conditional guard fixture: hold the classifier at one verified selector
        // class while retaining real owned parent/child original event provenance.
        $study = Mockery::mock(InstrumentMethodStudyService::class)->makePartial();
        $study->shouldReceive('selectorClass')->andReturn(['rules' => [], 'default_action' => 'BASELINE']);
        $this->app->instance(InstrumentMethodStudyService::class, $study);
        $result = app(AgentMethodStudyPreparationService::class)->prepare($input);
        $this->assertSame('no_effect', $result['status'], json_encode($result));
        $this->assertSame('METHOD_POLICY_STUDY_SELECTOR_CLASS_UNCHANGED', $result['reason']);
        $this->assertSame(0, $result['carriers_created']);
        $this->assertSame($count, ModelVersion::count());
        $this->assertSame($policies, DB::table('agent_method_policy_revisions')->count());
        $this->assertDatabaseCount('research_experiment_work_items', 0);
        $this->assertDatabaseCount('instrument_method_studies', 0);
        $this->assertDatabaseCount('lab_generations', 1);
        $this->assertFalse($result['economic_authority']);
    }

    public function test_original_owned_policy_carriers_pass_existing_constructor_and_all_request_barrier_without_parallel_dispatch(): void
    {
        [$child, $input] = $this->policyStudyInput();
        config()->set('services.autonomous_mode.default_enabled', true);
        config()->set('queue.default', 'database');
        Bus::fake();
        $prepared = app(AgentMethodStudyPreparationService::class)->prepare($input);
        $this->assertSame('own_policy_study_registered', $prepared['status'], json_encode($prepared));
        $work = ResearchExperimentWorkItem::findOrFail($prepared['work_id']);
        $owner = app(\App\Services\InstrumentMethodStudyExecutionService::class);
        $proof = $owner->inspectWork($work);
        $this->assertSame('ready', $proof['status'], json_encode($proof));
        $claimed = app(\App\Services\ResearchExperimentConversionKernelService::class)
            ->claimForOwner(\App\Services\ResearchLoopArbiterService::class, 1);
        $currentWork = $work->fresh();
        $registration = data_get($currentWork->payload, 'method_registration', []);
        $safeError = is_string($currentWork->last_error) && preg_match('/^[A-Z][A-Z0-9_]+$/D', $currentWork->last_error)
            ? $currentWork->last_error : null;
        $this->assertCount(1, $claimed, json_encode(['work_status' => $currentWork->status, 'typed_last_error' => $safeError,
            'php_source_equal' => ($registration['php_source_hash'] ?? null) === app(LabImmutableEvidenceService::class)->codeHash(),
            'python_source_equal' => ($registration['python_source_hash'] ?? null) === app(\App\Services\ResearchReleaseSealService::class)->pythonHash()]));
        $leased = $claimed[0];
        $queued = app(\App\Services\ResearchExperimentWorkConsumerService::class)
            ->execute($leased->id, $leased->lease_token, $leased->fence_version);
        $reinspected = $owner->inspectWork($leased->fresh());
        $this->assertSame('queued', $queued['status'], json_encode(['result' => $queued,
            'fresh_status' => $reinspected['status'] ?? null, 'fresh_reason' => $reinspected['reason_code'] ?? null]));
        $this->assertTrue(data_get($leased->fresh()->result, 'original_barrier.all_four_originals_ready'));
        $this->assertCount(4, data_get($leased->fresh()->result, 'original_barrier.request_hashes'));
        $this->assertSame('ready', $owner->inspectWork($leased->fresh())['status']);
        $this->assertDatabaseCount('lab_generations', 2);
        $this->assertDatabaseCount('lab_agents', 5);
        $this->assertDatabaseCount('lab_evaluation_runs', 1); // Original parent fixture only; no job executed here.
        Bus::assertDispatched(\App\Jobs\ExecuteScopedResearchArmJob::class, 1);
        Bus::assertNotDispatched(\App\Jobs\EvaluateLabAgentJob::class);
        $this->assertDatabaseCount('candidate_gate_decisions', 0);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    private function policyStudyInput(): array
    {
        $execution = app(ExecutionContractService::class)->for('XAUUSD', 'M5');
        [$parent, $initial, $run] = $this->originalParent(['execution_contract' => $execution]);
        LabGeneration::query()->update(['status' => 'screened']);
        $child = $this->model([], ['execution_contract' => $execution]);
        $owner = app(AgentMethodGenerationService::class);
        $this->assertSame('learned_diagnostic', app(AgentMethodLearningService::class)->learnFromRun($run)['status']);
        $prepared = $owner->prepareFreshChild($child, $parent);
        $this->assertSame('prospective_child_policy_attached', $prepared['status'], json_encode($prepared));
        $original = $initial['library_contract']['recipe_hashes'][0];
        $recipe = app(InstrumentMethodLibraryService::class)->inspectRecipe($original)['contract'];
        $method = array_values($recipe['method_contracts'])[0];
        $new = app(InstrumentMethodLibraryService::class)->registerMethod(array_intersect_key($method,
            array_flip(['instrument_key', 'method_key', 'context_predicate', 'horizon', 'known_limitations', 'source_keys', 'implementation']))
            + ['revision' => 2]);
        $this->assertSame('registered_research_only', $new['status'], json_encode($new));
        $revision = app(InstrumentMethodLibraryService::class)->registerRecipe(['recipe_key' => $recipe['recipe_key'],
            'revision' => 2, 'method_hashes' => [$new['method_hash']], 'context_predicate' => $recipe['context_predicate']]);
        $this->assertSame('registered_research_only', $revision['status'], json_encode($revision));
        $root = storage_path('app/lab-datasets/own-policy-test-'.Str::uuid()); File::ensureDirectoryExists($root);
        $csv = "time,open,high,low,close\n"; $start = CarbonImmutable::parse('2025-11-03T07:00:00Z');
        for ($index = 0; $index < 512; $index++) $csv .= $start->addMinutes($index * 5)->format('Y-m-d\TH:i:s\Z').",2000,2002,1998,2001\n";
        File::put($root.'/m5.csv', $csv);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($root));
        return [$child->fresh(), ['source_model_id' => (int) $child->id,
            'libraries' => ['L1' => [$original], 'L2' => [$revision['recipe_hash']]],
            'source' => ['dataset_path' => $root.'/m5.csv', 'dataset_sha256' => hash('sha256', $csv)],
            'study' => ['data_hash' => hash('sha256', $csv), 'execution_hash' => $execution['execution_hash'],
                'scope' => ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'context' => $this->context(),
                    'evaluated_start' => $start->addMinutes(200 * 5)->format('Y-m-d\TH:i:s\Z'),
                    'evaluated_end' => $start->addMinutes(511 * 5)->format('Y-m-d\TH:i:s\Z')],
                'evaluated_rows' => 312, 'initial_balance' => 10000.0, 'risk_per_trade' => 1.0,
                'execution_costs' => $execution['parameters'], 'hard_limits' => ['max_total_risk_percent' => 5.0],
                'resource_caps' => ['max_rows' => 512, 'max_decision_events' => 4096, 'max_runtime_seconds' => 120], 'minimum_trades' => 10]]];
    }

    private function model(array $deltas = [], array $metadata = []): ModelVersion
    {
        return ModelVersion::create(['name' => 'method-generation-'.Str::uuid(), 'strategy' => 'breakout_v1',
            'status' => 'draft', 'parameters' => [...app(StrategyParameterSchemaService::class)->defaults('breakout'), ...$deltas],
            'metadata' => ['base_strategy' => 'breakout_v1', 'risk_governor' => ['risk_per_trade' => 1.0],
                'trade_management' => ['time_stop_candles' => 2], 'lab_symbol' => 'XAUUSD', 'lab_timeframe' => 'H1', ...$metadata]]);
    }
    private function recipe(): string
    {
        $library = app(InstrumentMethodLibraryService::class);
        $source = $library->registerSource(['kind' => 'imported_text', 'reference' => 'Conditional software fixture', 'content' => 'Reached entry veto hypothesis.',
            'metadata' => ['license' => 'test fixture owned', 'access' => 'user_supplied', 'exposure' => ['status' => 'synthetic_fixture']]]);
        $method = $library->registerMethod(['instrument_key' => 'trend_pullback', 'method_key' => 'generation-veto', 'revision' => 1,
            'context_predicate' => $this->context(), 'horizon' => 'hour', 'known_limitations' => ['No market qualification.'],
            'source_keys' => [$source['source_key']], 'implementation' => ['kind' => 'typed_ast', 'target' => 'confirmation',
                'ast' => ['op' => 'CONST', 'type' => 'bool', 'value' => false], 'input_bindings' => [],
                'budget' => ['cpu_seconds' => .5, 'max_calls' => 100, 'max_node_evaluations' => 4800]]]);
        $this->assertSame('registered_research_only', $method['status'], json_encode($method));
        $recipe = $library->registerRecipe(['recipe_key' => 'generation-veto', 'revision' => 1,
            'method_hashes' => [$method['method_hash']], 'context_predicate' => $this->context()]);
        return $recipe['recipe_hash'];
    }
    private function generation(): LabGeneration
    {
        $lab = AiLaboratory::create(['name' => 'method generation fixture', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_families' => ['breakout']]);
        return LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1, 'status' => 'draft']);
    }
    private function originalParent(array $metadata = []): array
    {
        config()->set('services.instrument_methods.generation_recipe_hashes', [$this->recipe()]);
        $parent = $this->model([], $metadata);
        $initial = app(AgentMethodGenerationService::class)->prepareFreshChild($parent, null);
        $this->assertSame('prospective_child_policy_attached', $initial['status'], json_encode($initial));
        $policy = app(AgentMethodLearningService::class)->policy($initial['policy_hash']); $parent->refresh();
        $generation = $this->generation();
        $agent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $parent->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'breakout', 'origin' => 'fixture', 'lifecycle_status' => 'draft', 'parameter_diff' => []]);
        $contract = app(InstrumentMethodResearchBindingService::class)->compilePolicy($policy['policy_hash'],
            ['strategy_family' => 'breakout', 'parameters' => $parent->parameters], $this->scope(str_repeat('a', 64)), 'model:'.$parent->id);
        $script = 'import json,sys; from app.services.instrument_method_learning import InstrumentMethodLearning; c=json.load(sys.stdin); e=InstrumentMethodLearning(c,dataset_hash=c["data_hash"],execution_hash=c["execution_hash"],expected_agent_key=c["expected_agent_key"]); v=e.evaluate({"close":100.0,"market_regime":"trend_up","market_session":"london","volatility_regime":"normal_volatility"},decision_id="generation-input",phase="entry",decision_at="2025-01-01T12:05:00Z",observed_at="2025-01-01T12:00:00Z",direction="BUY"); print(json.dumps({"event":v,"receipt":e.receipt()}))';
        $process = new Process(['python', '-c', $script], dirname(base_path()).'/ai-service-python');
        $process->setTimeout(20); $process->setInput(json_encode($contract)); $process->mustRun();
        $actual = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $request = ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'strategy' => 'breakout_v1', 'parameters' => $parent->parameters,
            'replay_dataset_hash' => str_repeat('a', 64), 'evaluation_mode' => 'screening', 'emit_decision_trace' => true, 'emit_trade_ledger' => true,
            'policy_context' => ['instrument_method_learning' => $contract], 'candles' => array_map(
                fn ($i) => ['time' => CarbonImmutable::parse('2024-12-31T19:20:00Z')->addMinutes($i * 5)->format('Y-m-d\TH:i:s\Z')], range(0, 200))];
        $evidence = app(LabImmutableEvidenceService::class);
        $response = ['decision_trace' => [['event_type' => 'signal_evaluation', 'candle_index' => 200, 'candle_time' => '2025-01-01T12:00:00Z', 'decision' => 'WAIT'],
            ['event_type' => 'instrument_method_decision', 'candle_index' => 200, 'method_learning' => $actual['event']]],
            'trades' => [], 'trade_ledger' => [], 'total_trades' => 0, 'trade_ledger_hash' => $evidence->hash([]),
            'data_quality' => ['instrument_method_learning_receipt' => $actual['receipt'],
                'decision_trace' => ['protocol' => 'candle_decision_trace_v1', 'requested' => true, 'complete' => true,
                    'input_candle_count' => 201, 'event_count' => 2, 'evaluated_candle_count' => 1, 'covered_candle_count' => 1]]];
        $this->travel(1)->seconds();
        $run = LabEvaluationRun::create(['run_id' => (string) Str::uuid(), 'lab_generation_id' => $generation->id,
            'lab_agent_id' => $agent->id, 'model_version_id' => $parent->id, 'phase' => 'screening', 'mode' => 'research',
            'status' => 'started', 'started_at' => now(), 'data_hash' => str_repeat('a', 64)]);
        $evidence->attachRequest($run, $request); $this->travel(1)->seconds(); $evidence->finishRun($run, 'completed', $response);
        $this->assertNotNull($evidence->verifiedModelRuntimeIdentity($run->fresh()));
        return [$parent->fresh(), $policy, $run->fresh()];
    }
    private function context(): array { return ['regime' => 'trend_up', 'session' => 'london', 'volatility' => 'normal']; }
    private function scope(string $hash): array { return ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'data_hash' => $hash,
        'execution_hash' => str_repeat('b', 64), 'evaluated_start' => '2025-01-01T12:00:00Z',
        'evaluated_end' => '2025-01-01T12:05:00Z', 'context' => $this->context()]; }
}
