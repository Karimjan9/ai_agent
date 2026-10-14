<?php

namespace Tests\Feature;

use App\Jobs\EvaluateLabAgentJob;
use App\Jobs\ExecuteScopedResearchArmJob;
use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Models\ResearchExperimentWorkItem;
use App\Services\AgentMethodLearningService;
use App\Services\ExecutionContractService;
use App\Services\InstrumentMethodLibraryService;
use App\Services\InstrumentMethodResearchBindingService;
use App\Services\InstrumentMethodStudyExecutionService;
use App\Services\InstrumentMethodStudyService;
use App\Services\LabAgentEvaluationService;
use App\Services\LabImmutableEvidenceService;
use App\Services\LabPopulationService;
use App\Services\ResearchExperimentConversionKernelService;
use App\Services\ResearchExperimentWorkConsumerService;
use App\Services\ResearchLoopArbiterService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Mockery;
use Tests\TestCase;

/** Real registry/mutex/lease/constructor tests; synthetic HTTP products do not establish market proof. */
class InstrumentMethodStudyExecutionTest extends TestCase
{
    use RefreshDatabase;
    private array $design;
    private array $source;
    private string $studyHash;
    private string $fixtureRoot;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake(); Storage::fake('method_matrix_execution_test');
        config()->set('services.lab_evidence.disk', 'method_matrix_execution_test');
        config()->set('queue.default', 'database'); config()->set('services.autonomous_mode.default_enabled', true);
        config()->set('services.ai_service.url', 'http://synthetic-method-executor.test');
        AiLaboratory::create(['symbol' => 'XAUUSD', 'timeframe' => 'H1', 'name' => 'method-study-software-fixture',
            'strategy_families' => ['breakout'], 'is_active' => true]);
        $this->fixtureRoot = storage_path('app/lab-datasets/method-execution-test-'.Str::uuid());
        File::ensureDirectoryExists($this->fixtureRoot);
        $csv = "time,open,high,low,close\n"; $times = [];
        $start = \Carbon\CarbonImmutable::parse('2025-11-03T07:00:00Z');
        for ($index = 0; $index < 512; $index++) {
            $times[] = $start->addMinutes($index * 5)->format('Y-m-d\TH:i:s\Z');
            $csv .= $times[$index].",2000,2002,1998,2001\n";
        }
        File::put($this->fixtureRoot.'/m5.csv', $csv);
        $this->source = ['dataset_path' => $this->fixtureRoot.'/m5.csv', 'dataset_sha256' => hash('sha256', $csv)];
        $execution = app(ExecutionContractService::class)->for('XAUUSD', 'M5');
        $library = app(InstrumentMethodLibraryService::class);
        $external = $library->registerSource(['kind' => 'imported_text', 'reference' => 'synthetic software rules only',
            'content' => 'Synthetic risk-only method fixture. Not a market skill.', 'metadata' => ['license' => 'test fixture',
                'access' => 'user_supplied', 'exposure' => ['status' => 'unknown']]]);
        $recipes = [];
        foreach ([1 => .5, 2 => .75] as $revision => $multiplier) {
            $method = $library->registerMethod(['instrument_key' => 'atr_risk_envelope', 'method_key' => 'matrix-risk',
                'revision' => $revision, 'context_predicate' => $this->context(), 'horizon' => 'hour',
                'known_limitations' => ['Software-only fixture, no independent observations.'], 'source_keys' => [$external['source_key']],
                'implementation' => ['kind' => 'typed_ast', 'target' => 'risk_multiplier',
                    'ast' => ['op' => 'CONST', 'type' => 'number', 'value' => $multiplier], 'input_bindings' => [],
                    'budget' => ['cpu_seconds' => .5, 'max_calls' => 4096, 'max_node_evaluations' => 480000]]]);
            $this->assertSame('registered_research_only', $method['status'], json_encode($method));
            $recipe = $library->registerRecipe(['recipe_key' => 'matrix-risk-recipe', 'revision' => $revision,
                'method_hashes' => [$method['method_hash']], 'context_predicate' => $this->context()]);
            $this->assertSame('registered_research_only', $recipe['status']); $recipes[$revision] = $recipe['recipe_hash'];
        }
        $arms = [];
        foreach (InstrumentMethodStudyExecutionService::ARMS as $arm) {
            $model = ModelVersion::create(['name' => 'matrix-'.$arm, 'strategy' => 'breakout', 'version' => 1,
                'status' => 'draft', 'parameters' => app(StrategyParameterSchemaService::class)->defaults('breakout'),
                'metadata' => ['base_strategy' => 'breakout', 'execution_contract' => $execution]]);
            $recipe = $recipes[str_starts_with($arm, 'L1') ? 1 : 2];
            $policy = app(AgentMethodLearningService::class)->registerPolicy(['agent_key' => 'model:'.$model->id,
                'revision' => 1, 'recipe_hashes' => [$recipe], 'rules' => [['context_predicate' => $this->context(), 'preferred_recipes' => [$recipe]]],
                'default_action' => str_ends_with($arm, 'A1') ? 'BASELINE' : 'WAIT']);
            $this->assertSame('registered_research_only', $policy['status']);
            app(InstrumentMethodResearchBindingService::class)->attachToUnusedModel($model->id, $policy['policy_hash']);
            $arms[$arm] = ['model_id' => (int) $model->id, 'policy_hash' => $policy['policy_hash']];
        }
        $this->design = ['study_key' => 'original-method-matrix-software', 'arms' => $arms,
            'data_hash' => $this->source['dataset_sha256'], 'execution_hash' => $execution['execution_hash'],
            'scope' => ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'evaluated_start' => $times[200],
                'evaluated_end' => $times[511], 'context' => $this->context()], 'evaluated_rows' => 312,
            'initial_balance' => 1000.0, 'risk_per_trade' => 1.0, 'execution_costs' => $execution['parameters'],
            'hard_limits' => ['risk_per_trade_percent' => 1.0],
            'resource_caps' => ['max_rows' => 512, 'max_decision_events' => 4096, 'max_runtime_seconds' => 120], 'minimum_trades' => 3];
        $registered = app(InstrumentMethodStudyService::class)->preregister($this->design);
        $this->assertSame('preregistered_diagnostic', $registered['status'], json_encode($registered));
        $this->studyHash = $registered['study_hash'];
    }

    protected function tearDown(): void
    {
        if (isset($this->fixtureRoot) && is_dir($this->fixtureRoot)) File::deleteDirectory($this->fixtureRoot);
        parent::tearDown();
    }

    public function test_original_registration_is_sealed_then_claimed_by_existing_owner_not_by_flags(): void
    {
        $owner = app(InstrumentMethodStudyExecutionService::class);
        $registered = $owner->registerWork($this->studyHash, $this->source);
        $this->assertSame('original_work_registered', $registered['status'], json_encode($registered));
        $repeat = $owner->registerWork($this->studyHash, $this->source);
        $this->assertSame('original_work_exists', $repeat['status'], json_encode($repeat));
        $work = ResearchExperimentWorkItem::findOrFail($registered['work_id']);
        $this->assertSame('blocked', $work->status); $this->assertFalse($work->payload['executable']);
        $readiness = $owner->inspectWork($work);
        $this->assertTrue($readiness['executable'], json_encode($readiness));
        $claimed = app(ResearchExperimentConversionKernelService::class)->claimForOwner(ResearchLoopArbiterService::class, 1);
        $this->assertCount(1, $claimed); $this->assertSame($work->id, $claimed[0]->id);
        $this->assertGreaterThan(2600, now()->diffInSeconds($claimed[0]->lease_expires_at));
        $this->assertDatabaseCount('lab_generations', 0); Bus::assertNothingDispatched();
    }

    public function test_canonical_constructor_owns_exact_four_models_and_all_requests_before_first_queue(): void
    {
        $work = $this->claim();
        $basis = fn ($model) => app(\App\Services\LabImmutableEvidenceService::class)->modelRuntimeBasis($model);
        $hashes = ModelVersion::orderBy('id')->get()->map($basis)->all();
        $result = app(ResearchExperimentWorkConsumerService::class)->execute($work->id, $work->lease_token, $work->fence_version);
        $this->assertSame('queued', $result['status'], json_encode($result)); $this->assertSame('L1A1', $result['arm']);
        $generation = LabGeneration::sole(); $this->assertSame(4, $generation->population_size); $this->assertSame('research_reserved', $generation->status);
        $this->assertSame(4, $generation->agents()->count());
        $this->assertSame($hashes, ModelVersion::orderBy('id')->get()->map($basis)->all());
        $this->assertTrue(ModelVersion::get()->every(fn ($model) => $model->status === 'testing'));
        $this->assertSame('ready', app(InstrumentMethodStudyExecutionService::class)->inspectWork($work->fresh())['status']);
        $barrier = data_get($work->fresh()->result, 'original_barrier');
        $this->assertSame(InstrumentMethodStudyExecutionService::ARMS, array_keys($barrier['request_hashes']));
        $this->assertTrue($barrier['all_four_originals_ready']);
        $this->assertNotNull(data_get($generation->trigger_context, 'research_release.release_hash'));
        $this->assertTrue(app(\App\Services\ImmutableGenerationContractService::class)->validate($generation)['valid']);
        Bus::assertDispatched(ExecuteScopedResearchArmJob::class, 1); Bus::assertNotDispatched(EvaluateLabAgentJob::class);
        $this->assertDatabaseCount('lab_evaluation_runs', 0); $this->assertDatabaseCount('candidate_gate_decisions', 0);
        $again = app(ResearchExperimentWorkConsumerService::class)->execute($work->id, $work->lease_token, $work->fence_version);
        $this->assertSame('queued', $again['status']); Bus::assertDispatched(ExecuteScopedResearchArmJob::class, 1);
        $this->assertDatabaseCount('lab_generations', 1); $this->assertDatabaseCount('lab_agents', 4);
    }

    public function test_policy_compiler_receives_exact_physical_request_before_original_publication(): void
    {
        $work = $this->claim();
        $actualBinding = app(InstrumentMethodResearchBindingService::class);
        $seen = [];
        $spy = \Mockery::mock(InstrumentMethodResearchBindingService::class)->makePartial();
        $spy->shouldReceive('compilePolicy')->atLeast()->times(4)->andReturnUsing(function ($policy, $baseline, $scope, $agentKey, $request) use ($actualBinding, &$seen) {
            $this->assertIsArray($request);
            $this->assertSame($this->design['initial_balance'], $request['initial_balance']);
            $this->assertSame($this->design['risk_per_trade'], $request['risk_per_trade']);
            $this->assertSame($this->design['hard_limits'], $request['policy_context']['hard_limits']);
            $this->assertSame($this->design['execution_hash'], $request['execution_contract']['execution_hash']);
            $this->assertSame($this->design['data_hash'], $request['replay_dataset_hash']);
            $this->assertSame($baseline['parameters'], $request['parameters']);
            $this->assertSame($scope['timeframe'], $request['timeframe']);
            $this->assertArrayNotHasKey('instrument_method_learning', $request['policy_context']);
            $seen[$request['policy_context']['instrument_method_study']['arm']] = $agentKey;
            return $actualBinding->compilePolicy($policy, $baseline, $scope, $agentKey, $request);
        });
        $this->app->instance(InstrumentMethodResearchBindingService::class, $spy);
        $result = app(InstrumentMethodStudyExecutionService::class)->execute($work);
        $this->assertSame('queued', $result['status'], json_encode($result));
        $this->assertSame(InstrumentMethodStudyExecutionService::ARMS, array_keys($seen));
        Bus::assertDispatched(ExecuteScopedResearchArmJob::class, 1);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_existing_stream_and_model_or_source_drift_block_without_constructor_writes(): void
    {
        $work = $this->claim(); $owner = app(InstrumentMethodStudyExecutionService::class);
        $lab = AiLaboratory::sole(); $other = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1,
            'trigger_type' => 'ordinary-existing', 'population_size' => 20, 'status' => 'screening']);
        $this->assertSame('METHOD_STUDY_ANOTHER_GENERATION_OWNS_STREAM', $owner->inspectWork($work)['reason_code']);
        $other->update(['status' => 'screened']);
        $model = ModelVersion::find($this->design['arms']['L2A2']['model_id']); $params = $model->parameters;
        $model->update(['parameters' => [...$params, 'atr_stop_multiplier' => $params['atr_stop_multiplier'] + .5]]);
        $this->assertSame('METHOD_STUDY_ORIGINAL_MODEL_DRIFT', $owner->inspectWork($work)['reason_code']);
        $model->update(['parameters' => $params]); File::append($this->source['dataset_path'], "\n");
        $this->assertSame('METHOD_STUDY_ORIGINAL_SOURCE_ROOT_OR_HASH_INVALID', $owner->inspectWork($work)['reason_code']);
        $this->assertDatabaseCount('lab_agents', 0); $this->assertDatabaseCount('lab_evaluation_runs', 0);
    }

    public function test_stale_worker_and_ordinary_evaluation_cannot_enter_prospective_study(): void
    {
        $work = $this->claim(); $owner = app(InstrumentMethodStudyExecutionService::class); $owner->execute($work);
        $this->assertSame('stale_lease', $owner->executeQueuedArm($work->id, 'wrong-token', $work->fence_version,
            'L1A1', str_repeat('a', 64), 120)['status']);
        $agent = LabAgent::with('generation', 'modelVersion')->first();
        foreach ([fn () => new EvaluateLabAgentJob($agent->id, 'screen'),
            fn () => app(LabAgentEvaluationService::class)->screen($agent),
            fn () => app(LabAgentEvaluationService::class)->evaluate($agent),
            fn () => app(LabAgentEvaluationService::class)->screenBatch([$agent->id], 'XAUUSD')] as $invoke) {
            try { $invoke(); $this->fail('Ordinary evaluation must refuse the reserved original matrix.'); }
            catch (LogicException $error) { $this->assertSame('METHOD_STUDY_ORIGINAL_EXECUTOR_REQUIRED', $error->getMessage()); }
        }
        $this->assertDatabaseCount('lab_evaluation_runs', 0); Http::assertNothingSent();
    }

    public function test_four_completed_originals_with_unassessable_comparison_close_technical_without_replay_or_credit(): void
    {
        $work = $this->claim(); $owner = app(InstrumentMethodStudyExecutionService::class);
        $proof = $owner->inspectWork($work);
        $generation = app(\App\Services\LabPopulationService::class)->buildInstrumentMethodStudy($work, $proof);
        $originals = [];
        foreach (InstrumentMethodStudyExecutionService::ARMS as $arm) {
            $agent = $generation->agents()->where('model_version_id', $this->design['arms'][$arm]['model_id'])->sole();
            // Conditional transition fixture, not a fabricated market product.
            $run = LabEvaluationRun::create(['run_id' => (string) Str::uuid(), 'lab_generation_id' => $generation->id,
                'lab_agent_id' => $agent->id, 'model_version_id' => $agent->model_version_id, 'phase' => 'screening',
                'mode' => 'incremental', 'status' => 'completed', 'started_at' => now(), 'finished_at' => now(),
                'data_hash' => $this->design['data_hash'], 'metadata' => ['conditional_transition_fixture' => true]]);
            $originals[$arm] = ['run_id' => $run->run_id, 'model_id' => (int) $agent->model_version_id];
        }
        $work->update(['result' => ['original_arms' => $originals]]);
        $study = Mockery::mock(InstrumentMethodStudyService::class)->makePartial();
        $study->shouldReceive('settle')->once()->andReturn(['status' => 'technical_unassessable', 'reason' => 'STUDY_OBSERVED_ROWS_DIFFER']);
        $this->app->instance(InstrumentMethodStudyService::class, $study);
        $this->app->forgetInstance(InstrumentMethodStudyExecutionService::class);
        $result = app(InstrumentMethodStudyExecutionService::class)->execute($work->fresh());
        $this->assertSame('completed_technical', $result['status'], json_encode($result));
        $this->assertSame('technical_quarantine', $generation->fresh()->status);
        $this->assertSame('settled', $work->fresh()->status);
        $this->assertSame('STUDY_OBSERVED_ROWS_DIFFER', data_get($work->fresh()->result, 'reason_code'));
        $this->assertDatabaseCount('lab_evaluation_runs', 4);
        $this->assertDatabaseCount('agent_method_experiences', 0);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        Bus::assertNothingDispatched(); Http::assertNothingSent();
    }

    public function test_stop_denies_unprepared_work_but_drains_only_an_original_four_request_barrier(): void
    {
        $work = $this->claim(); $owner = app(InstrumentMethodStudyExecutionService::class);
        app(\App\Services\AutonomousModeService::class)->stop('XAUUSD', 'H1', 'test', 'deny unprepared work');
        $this->assertFalse($owner->admittedContinuationAllowed($work));
        app(\App\Services\AutonomousModeService::class)->start('XAUUSD', 'H1', 'test', 'prepare originals');
        $prepared = $owner->execute($work);
        $this->assertSame('queued', $prepared['status'], json_encode($prepared));
        app(\App\Services\AutonomousModeService::class)->stop('XAUUSD', 'H1', 'test', 'drain prepared originals');
        $this->assertTrue($owner->admittedContinuationAllowed($work->fresh()));
        $owner->assertLease($work->fresh());
        app(\App\Services\AutonomousModeService::class)->start('XAUUSD', 'H1', 'test', 'operator continues');
        app(\App\Services\AutonomousModeService::class)->pause('XAUUSD', 'H1', 'test', 'computer needed');
        $this->assertFalse($owner->admittedContinuationAllowed($work->fresh()));
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_paper_source_and_bad_clock_do_not_create_canonical_work(): void
    {
        $path = $this->fixtureRoot.'/paper.csv';
        $csv = str_replace('2025-', '2026-', File::get($this->source['dataset_path'])); File::put($path, $csv);
        $owner = app(InstrumentMethodStudyExecutionService::class);
        $result = $owner->registerWork($this->studyHash, ['dataset_path' => $path, 'dataset_sha256' => hash('sha256', $csv)]);
        $this->assertSame('METHOD_STUDY_ORIGINAL_SOURCE_ROOT_OR_HASH_INVALID', $result['reason_code']);
        $this->assertDatabaseCount('research_experiment_work_items', 0);
        $design = $this->design; $design['study_key'] = 'wrong-clock'; $design['scope']['evaluated_start'] = '2025-11-03T07:00:00Z';
        $other = app(InstrumentMethodStudyService::class)->preregister($design);
        $this->assertSame('METHOD_STUDY_ORIGINAL_EVALUATED_CLOCK_MISMATCH', $owner->registerWork($other['study_hash'], $this->source)['reason_code']);
        $this->assertDatabaseCount('research_experiment_work_items', 0);
    }

    public function test_bounded_view_reopens_exact_origin_cells_and_retains_separate_original_hash(): void
    {
        $origin = $this->fixtureRoot.'/original.csv';
        $original = File::get($this->source['dataset_path'])."2025-11-05T01:40:00Z,2000,2002,1998,2001\n";
        File::put($origin, $original);
        $source = [...$this->source, 'origin' => ['dataset_path' => $origin, 'dataset_sha256' => hash('sha256', $original), 'start_row' => 0]];
        $owner = app(InstrumentMethodStudyExecutionService::class);
        $registered = $owner->registerWork($this->studyHash, $source);
        $this->assertSame('original_work_registered', $registered['status'], json_encode($registered));
        $proof = $owner->inspectWork(ResearchExperimentWorkItem::find($registered['work_id']));
        $this->assertTrue($proof['executable'], json_encode($proof));
        $this->assertSame($source['origin']['dataset_sha256'], $proof['source']['origin']['dataset_sha256']);
        $this->assertNotSame($proof['source']['dataset_sha256'], $proof['source']['origin']['dataset_sha256']);
        $source['origin']['start_row'] = 1;
        $this->assertSame('METHOD_STUDY_ORIGINAL_VIEW_ROWS_CHANGED', $owner->registerWork($this->studyHash, $source)['reason_code']);
        $this->assertDatabaseCount('research_experiment_work_items', 1); $this->assertDatabaseCount('lab_evaluation_runs', 0);
    }

    public function test_original_transport_failure_terminalizes_owned_agents_without_another_attempt(): void
    {
        $work = $this->claim(); $owner = app(InstrumentMethodStudyExecutionService::class);
        $queued = $owner->execute($work);
        Http::fake(['*/api/backtest/run' => Http::response(['synthetic_transition_fixture' => true], 500)]);
        $result = $owner->executeQueuedArm($work->id, $work->lease_token, $work->fence_version,
            $queued['arm'], data_get($work->fresh()->result, 'queued_arm.request_hash'), 120);
        $this->assertSame('completed_technical', $result['status'], json_encode($result));
        $this->assertSame('settled', $work->fresh()->status);
        $this->assertSame(1, $work->fresh()->attempts);
        $this->assertTrue(LabAgent::get()->every(fn ($agent) => $agent->lifecycle_status === 'technical_quarantine'));
        $this->assertSame('technical_quarantine', LabGeneration::sole()->status);
        $this->assertSame('technical_error', LabEvaluationRun::sole()->status);
        $this->assertNotNull(LabEvaluationRun::sole()->finished_at);
        $this->assertDatabaseCount('lab_evaluation_runs', 1);
        $this->assertDatabaseCount('agent_method_experiences', 0);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        Http::assertSentCount(1); Bus::assertDispatched(ExecuteScopedResearchArmJob::class, 1);
    }

    public function test_spent_four_original_projection_reopens_historical_bytes_and_is_idempotent(): void
    {
        [$work, $generation] = $this->spentOriginalProjection();
        $before = [LabEvaluationRun::orderBy('id')->get()->toArray(), $work->fresh()->toArray(), $work->receipt->toArray(),
            data_get($generation->trigger_context, 'instrument_method_study_terminal')];
        $evidence = Mockery::mock(LabImmutableEvidenceService::class)->makePartial();
        $evidence->shouldReceive('codeHash')->never(); // New deployed source cannot reseal old originals.
        $this->app->instance(LabImmutableEvidenceService::class, $evidence);
        $this->app->forgetInstance(InstrumentMethodStudyExecutionService::class);
        $owner = app(InstrumentMethodStudyExecutionService::class);
        $result = app(\App\Services\LabGenerationTerminalBoundaryService::class)->closeIfTerminal($generation);
        $this->assertTrue($result['closed'], json_encode($result)); $this->assertSame(1, $result['changed_agents']);
        $this->assertSame(['screened', 'screened', 'screened', 'technical_quarantine'], LabAgent::orderBy('id')->pluck('lifecycle_status')->all());
        $this->assertSame($before, [LabEvaluationRun::orderBy('id')->get()->toArray(), $work->fresh()->toArray(), $work->receipt->fresh()->toArray(),
            data_get($generation->fresh()->trigger_context, 'instrument_method_study_terminal')]);
        $events = \App\Models\LabLifecycleEvent::where('event_type', 'instrument_method_study_terminal_projection')->count();
        $second = $owner->reconcileGeneration($generation->fresh());
        $this->assertSame('recorded', $second['status']); $this->assertFalse($second['closed']); $this->assertSame(0, $second['changed_agents']);
        $this->assertSame($events, \App\Models\LabLifecycleEvent::where('event_type', 'instrument_method_study_terminal_projection')->count());
        $this->assertDatabaseCount('lab_evaluation_runs', 4); $this->assertDatabaseCount('agent_method_experiences', 0);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0); Bus::assertNothingDispatched(); Http::assertNothingSent();
    }

    public function test_spent_projection_refuses_corrupt_receipt_unfinished_duplicate_or_missing_original(): void
    {
        [$work, $generation] = $this->spentOriginalProjection(); $owner = app(InstrumentMethodStudyExecutionService::class);
        $receipt = $work->receipt; $payload = $receipt->payload;
        $receipt->update(['payload' => [...$payload, 'contract' => [...$payload['contract'], 'tampered' => true]]]);
        $this->assertSame('METHOD_STUDY_TERMINAL_RECEIPT_BINDING_REQUIRED', $owner->reconcileGeneration($generation)['reason_code']);
        $receipt->update(['payload' => $payload]);
        $run = LabEvaluationRun::orderByDesc('id')->first(); $finished = $run->finished_at;
        $run->update(['finished_at' => null]);
        $this->assertSame('METHOD_STUDY_TERMINAL_ORIGINAL_OWNER_REQUIRED', $owner->reconcileGeneration($generation)['reason_code']);
        $run->update(['finished_at' => $finished]);
        $duplicate = $run->replicate(); $duplicate->run_id = (string) Str::uuid(); $duplicate->save();
        $this->assertSame('METHOD_STUDY_TERMINAL_ONE_ORIGINAL_REQUIRED', $owner->reconcileGeneration($generation)['reason_code']);
        $duplicate->delete();
        $result = $work->result; unset($result['original_arms']['L2A2']); $work->update(['result' => $result]);
        $this->assertSame('METHOD_STUDY_TERMINAL_FOUR_ORIGINALS_REQUIRED', $owner->reconcileGeneration($generation)['reason_code']);
        $this->assertSame('screening', LabAgent::orderByDesc('id')->first()->lifecycle_status);
        $this->assertNull(data_get($generation->fresh()->trigger_context, 'instrument_method_study_projection_terminal'));
        Bus::assertNothingDispatched(); Http::assertNothingSent();
    }

    public function test_spent_projection_refuses_changed_original_artifact_bytes(): void
    {
        [$work, $generation] = $this->spentOriginalProjection();
        $artifact = \App\Models\LabEvidenceArtifact::where('artifact_type', 'evaluation_request')->first();
        Storage::disk('method_matrix_execution_test')->put($artifact->storage_path, gzencode('{"corrupted":true}'));
        $result = app(InstrumentMethodStudyExecutionService::class)->reconcileGeneration($generation);
        $this->assertSame('blocked', $result['status']); $this->assertFalse($result['closed']);
        $this->assertSame('screening', LabAgent::orderByDesc('id')->first()->lifecycle_status);
        $this->assertSame('settled', $work->fresh()->status); $this->assertDatabaseCount('lab_evaluation_runs', 4);
    }

    public function test_spent_projection_requires_stop_no_live_lease_and_known_empty_pending_delayed_reserved_queues(): void
    {
        [$work, $generation] = $this->spentOriginalProjection(); $owner = app(InstrumentMethodStudyExecutionService::class);
        app(\App\Services\AutonomousModeService::class)->start('XAUUSD', 'H1', 'test', 'synthetic guard');
        $this->assertSame('METHOD_STUDY_TERMINAL_OPERATOR_STOP_REQUIRED', $owner->reconcileGeneration($generation)['reason_code']);
        app(\App\Services\AutonomousModeService::class)->stop('XAUUSD', 'H1', 'test', 'synthetic guard');
        $work->update(['lease_token' => 'synthetic-live-lease', 'lease_expires_at' => now()->addMinute()]);
        $this->assertSame('METHOD_STUDY_TERMINAL_WORK_BINDING_REQUIRED', $owner->reconcileGeneration($generation)['reason_code']);
        $work->update(['lease_token' => null, 'lease_expires_at' => null]);
        $other = $work->replicate(); $other->work_key = 'synthetic-other-'.Str::uuid(); $other->status = 'leased';
        $other->lease_token = 'synthetic-other-lease'; $other->lease_expires_at = now()->addMinute(); $other->save();
        $this->assertSame('METHOD_STUDY_TERMINAL_ACTIVE_WORK_LEASE', $owner->reconcileGeneration($generation)['reason_code']);
        $other->delete();
        foreach (['pending', 'delayed', 'reserved'] as $state) {
            $id = DB::table('jobs')->insertGetId(['queue' => $state === 'pending' ? 'scheduler-constructor' : 'lab-full-validation',
                'payload' => '{}', 'attempts' => $state === 'reserved' ? 1 : 0,
                'reserved_at' => $state === 'reserved' ? now()->timestamp : null,
                'available_at' => now()->timestamp + ($state === 'delayed' ? 600 : 0), 'created_at' => now()->timestamp]);
            $this->assertSame('METHOD_STUDY_TERMINAL_QUEUE_IDLE_REQUIRED', $owner->reconcileGeneration($generation)['reason_code'], $state);
            DB::table('jobs')->where('id', $id)->delete();
        }
        $queue = Mockery::mock(\App\Services\LabQueueJobInspector::class)->makePartial();
        $queue->shouldReceive('queueSnapshot')->andReturn(['available' => false, 'total' => null]);
        $this->app->instance(\App\Services\LabQueueJobInspector::class, $queue);
        $this->assertSame('METHOD_STUDY_TERMINAL_QUEUE_IDLE_REQUIRED', $owner->reconcileGeneration($generation)['reason_code']);
        $this->assertSame('screening', LabAgent::orderByDesc('id')->first()->lifecycle_status);
        Bus::assertNothingDispatched(); Http::assertNothingSent();
    }

    public function test_spent_projection_requires_authenticated_idle_not_public_health_or_live_replay(): void
    {
        [, $generation] = $this->spentOriginalProjection(); $owner = app(InstrumentMethodStudyExecutionService::class);
        foreach ([['source' => 'health', 'status' => 'ok', 'active_requests' => 0],
            ['source' => 'replay-status', 'status' => 'active', 'active_requests' => 1],
            ['source' => 'replay-status', 'status' => 'unknown', 'active_requests' => null]] as $state) {
            $probe = Mockery::mock(\App\Services\ReplayLivenessProbeService::class);
            $probe->shouldReceive('probe')->andReturn($state); $this->app->instance(\App\Services\ReplayLivenessProbeService::class, $probe);
            $this->assertSame('METHOD_STUDY_TERMINAL_AUTHENTICATED_REPLAY_IDLE_REQUIRED', $owner->reconcileGeneration($generation)['reason_code']);
        }
        $this->assertSame('screening', LabAgent::orderByDesc('id')->first()->lifecycle_status);
        Bus::assertNothingDispatched(); Http::assertNothingSent();
    }

    public function test_existing_frozen_settle_only_command_repairs_spent_projection_without_successor(): void
    {
        [$work, $generation] = $this->spentOriginalProjection();
        \Illuminate\Support\Facades\Cache::put('system:scheduler-heartbeat', now()->toIso8601String(), 120);
        $result = app(\App\Services\LabLifecycleOrchestrator::class)->run('XAUUSD', 'H1', null, false, $generation->id, true);
        $this->assertTrue(data_get($result, 'data.terminal_boundary.closed', false), json_encode($result));
        $this->assertSame('technical_quarantine', LabAgent::orderByDesc('id')->first()->lifecycle_status);
        $this->assertDatabaseCount('lab_generations', 1); $this->assertDatabaseCount('lab_evaluation_runs', 4);
        $this->assertSame(4, $work->fresh()->attempts); Bus::assertNothingDispatched(); Http::assertNothingSent();
    }

    /** Synthetic transition products only; no market outcome or economic claim. */
    private function spentOriginalProjection(): array
    {
        $work = $this->claim(); $owner = app(InstrumentMethodStudyExecutionService::class); $proof = $owner->inspectWork($work);
        $generation = app(LabPopulationService::class)->buildInstrumentMethodStudy($work, $proof);
        $requests = []; foreach (InstrumentMethodStudyExecutionService::ARMS as $arm) {
            $agent = $generation->agents()->where('model_version_id', $proof['design']['arms'][$arm]['model_id'])->sole();
            $requests[$arm] = $owner->compileRequest($generation, $agent, $arm, $proof);
        }
        $originals = []; $evidence = app(LabImmutableEvidenceService::class);
        foreach (InstrumentMethodStudyExecutionService::ARMS as $index => $arm) {
            $agent = $generation->agents()->where('model_version_id', $proof['design']['arms'][$arm]['model_id'])->sole();
            $run = $evidence->beginRun($agent, 'screening', 'incremental', ['source' => InstrumentMethodStudyExecutionService::class, 'data_hash' => $this->design['data_hash']]);
            $run->update(['metadata' => [...(array) $run->metadata, InstrumentMethodStudyExecutionService::MARKER => [
                'work_id' => (int) $work->id, 'study_hash' => $this->studyHash, 'arm' => $arm]]]);
            $evidence->attachRequest($run, $requests[$arm], ['data_hash' => $this->design['data_hash']]);
            $evidence->finishIfOpen($run, $index === 3 ? 'technical_error' : 'completed', ['synthetic_transition_fixture' => true], [], ['quality_verdict' => 'withheld']);
            $agent->update(['lifecycle_status' => $index === 3 ? 'screening' : 'screened']);
            $originals[$arm] = ['run_id' => $run->run_id, 'model_id' => (int) $agent->model_version_id,
                'request_hash' => app(\App\Services\ResearchPaperEpochContractService::class)->parameterHash($requests[$arm])];
        }
        $ceiling = ['research_only' => true, 'independent_validation' => false, 'economic_authority' => false, 'confirmation_proven' => false,
            'mastery_proven' => false, 'skill_credit' => false, 'promotion_evidence' => false, 'paper_authority' => false];
        $reason = 'METHOD_STUDY_ORIGINAL_TRANSPORT_FAILED';
        $generation->update(['status' => 'technical_quarantine', 'completed_at' => now(), 'trigger_context' => [...(array) $generation->trigger_context,
            'instrument_method_study_terminal' => ['protocol' => InstrumentMethodStudyExecutionService::PROTOCOL, 'reason_code' => $reason,
                'original_results_unchanged' => true, ...$ceiling]]]);
        $work->update(['status' => 'settled', 'attempts' => 4, 'lease_token' => null, 'lease_expires_at' => null, 'completed_at' => now(),
            'result' => ['status' => 'original_method_matrix_unassessable', 'reason_code' => $reason, 'generation_id' => (int) $generation->id,
                'original_arms' => $originals, ...$ceiling]]);
        app(\App\Services\AutonomousModeService::class)->stop('XAUUSD', 'H1', 'test', 'synthetic projection fixture');
        $probe = Mockery::mock(\App\Services\ReplayLivenessProbeService::class);
        $probe->shouldReceive('probe')->andReturn(['source' => 'replay-status', 'status' => 'ok', 'active_requests' => 0]);
        $this->app->instance(\App\Services\ReplayLivenessProbeService::class, $probe);
        return [$work->fresh(), $generation->fresh()];
    }

    private function claim(): ResearchExperimentWorkItem
    {
        $result = app(InstrumentMethodStudyExecutionService::class)->registerWork($this->studyHash, $this->source);
        $this->assertSame('original_work_registered', $result['status'], json_encode($result));
        $items = app(ResearchExperimentConversionKernelService::class)->claimForOwner(ResearchLoopArbiterService::class, 1);
        $this->assertCount(1, $items, json_encode(ResearchExperimentWorkItem::find($result['work_id'])->toArray())); return $items[0];
    }
    private function context(): array { return ['regime' => 'trend_up', 'session' => 'london', 'volatility' => 'normal']; }
}
