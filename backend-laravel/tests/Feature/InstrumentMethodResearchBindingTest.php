<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Services\AgentMethodLearningService;
use App\Services\ExecutionContractService;
use App\Services\InstrumentMethodLibraryService;
use App\Services\InstrumentMethodResearchBindingService;
use App\Services\InstrumentMethodStudyService;
use App\Services\LabImmutableEvidenceService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Real SQLite declarations and Python interpretation, with synthetic closed inputs only. */
class InstrumentMethodResearchBindingTest extends TestCase
{
    use RefreshDatabase;
    private array $recipe;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('instrument_method_binding_test');
        config()->set('services.lab_evidence.disk', 'instrument_method_binding_test');
        $this->recipe = $this->registeredRecipe(1, .5);
    }

    public function test_actual_library_policy_and_model_binding_execute_in_python_with_exact_owner(): void
    {
        [$model, $policy] = $this->boundModel($this->recipe);
        $request = app(InstrumentMethodResearchBindingService::class)->bindRequests($this->request(), [$model]);
        $contract = $request['policy_context']['instrument_method_learning'];
        $this->assertSame('model:'.$model->id, $contract['policy']['agent_key']);
        $this->assertSame($policy['policy_hash'], $contract['policy']['policy_hash']);
        $this->assertSame($this->recipe['recipe_hash'], $contract['recipes'][0]['recipe_hash']);
        $this->assertTrue($request['emit_decision_trace']); $this->assertTrue($request['emit_trade_ledger']);
        $script = 'import json,sys; from app.services.instrument_method_learning import InstrumentMethodLearning; c=json.load(sys.stdin); e=InstrumentMethodLearning(c,dataset_hash=c["data_hash"],execution_hash=c["execution_hash"],expected_agent_key=c["expected_agent_key"]); r=e.evaluate({"close":100.0,"market_regime":"trend_up","market_session":"london","volatility_regime":"normal_volatility"},decision_id="synthetic-closed-input",phase="entry",decision_at="2025-01-01T12:05:00Z",observed_at="2025-01-01T12:00:00Z",direction="BUY"); print(json.dumps({"event":r,"receipt":e.receipt()}))';
        $process = new Process(['python', '-c', $script], dirname(base_path()).'/ai-service-python');
        $process->setTimeout(20); $process->setInput(json_encode($contract)); $process->mustRun();
        $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($result['event']['used']); $this->assertTrue($result['event']['behavior_changed']);
        $this->assertTrue($result['event']['outputs']['confirmation']);
        $this->assertSame(.5, $result['event']['outputs']['risk_multiplier']);
        $this->assertSame($contract['contract_hash'], $result['receipt']['contract_hash']);
        $this->assertSame($policy['policy_hash'], $result['receipt']['policy_hash']);
        $this->assertFalse($result['receipt']['economic_authority']);
        $this->assertDatabaseCount('agent_method_experiences', 0);
    }

    public function test_attach_requires_exact_unused_owner_and_is_immutable_before_construction(): void
    {
        [$model, $policy] = $this->boundModel($this->recipe);
        $owner = app(InstrumentMethodResearchBindingService::class);
        $this->assertSame('attached_prospectively', $owner->attachToUnusedModel($model->id, $policy['policy_hash'])['status']);
        $other = $this->model();
        $this->refuses(fn () => $owner->attachToUnusedModel($other->id, $policy['policy_hash']), 'METHOD_POLICY_UNUSED_OWNED_MODEL_REQUIRED');
        $second = $this->policy($model, $this->recipe, 'WAIT', 2);
        $this->refuses(fn () => $owner->attachToUnusedModel($model->id, $second['policy_hash']), 'METHOD_POLICY_MODEL_BINDING_IMMUTABLE');
        $this->refuses(fn () => $owner->compilePolicy($policy['policy_hash'], ['strategy_family' => 'breakout', 'parameters' => $model->parameters],
            $owner->scopeFromRequest($this->request()), 'model:'.$other->id), 'METHOD_POLICY_OR_AGENT_OWNER_INVALID');
    }

    public function test_attempted_constructed_promoted_invalidated_and_live_models_refuse_attachment(): void
    {
        $owner = app(InstrumentMethodResearchBindingService::class);
        foreach (['attempted', 'constructed', 'promoted', 'invalidated', 'live', 'paper', 'champion'] as $state) {
            $model = $this->model(); $policy = $this->policy($model, $this->recipe);
            if ($state === 'attempted') LabEvaluationRun::create(['run_id' => (string) Str::uuid(), 'model_version_id' => $model->id,
                'phase' => 'screening', 'status' => 'started', 'started_at' => now()]);
            elseif ($state === 'constructed') $this->agent($model);
            elseif ($state === 'promoted') $model->update(['promoted_at' => now()]);
            elseif ($state === 'invalidated') $model->update(['invalidated_at' => now()]);
            else $model->update(['status' => $state]);
            $this->refuses(fn () => $owner->attachToUnusedModel($model->id, $policy['policy_hash']), 'METHOD_POLICY_UNUSED_OWNED_MODEL_REQUIRED');
        }
    }

    public function test_batch_uses_exact_lab_agent_and_rejects_missing_duplicate_or_single_ambiguous_owners(): void
    {
        [$one] = $this->boundModel($this->recipe); [$two] = $this->boundModel($this->recipe);
        $oneAgent = $this->agent($one); $twoAgent = $this->agent($two);
        $request = $this->request();
        $request['strategies'] = [['lab_agent_id' => $twoAgent->id], ['lab_agent_id' => $oneAgent->id]];
        $owner = app(InstrumentMethodResearchBindingService::class);
        $bound = $owner->bindRequests($request, [$one, $two]);
        $this->assertSame('model:'.$two->id, $bound['strategies'][0]['instrument_method_learning']['policy']['agent_key']);
        $this->assertSame('model:'.$one->id, $bound['strategies'][1]['instrument_method_learning']['policy']['agent_key']);
        $this->refuses(fn () => $owner->bindRequests($this->request(), [$one, $two]), 'METHOD_RESEARCH_SINGLE_OWNER_AMBIGUOUS');
        $missing = $request; $missing['strategies'] = [['lab_agent_id' => $oneAgent->id]];
        $this->refuses(fn () => $owner->bindRequests($missing, [$one, $two]), 'METHOD_RESEARCH_BATCH_OWNER_AMBIGUOUS');
        $duplicate = $request; $duplicate['strategies'][] = ['lab_agent_id' => $oneAgent->id];
        $this->refuses(fn () => $owner->bindRequests($duplicate, [$one, $two]), 'METHOD_RESEARCH_BATCH_OWNER_AMBIGUOUS');
    }

    public function test_pre2026_scope_and_source_clock_guards_refuse_before_compilation(): void
    {
        [$model] = $this->boundModel($this->recipe); $owner = app(InstrumentMethodResearchBindingService::class);
        $paper = $this->request(); $paper['candles'][1]['time'] = '2026-01-01T00:00:00Z';
        $this->refuses(fn () => $owner->bindRequests($paper, [$model]), 'METHOD_RESEARCH_PRE2026_SCOPE_REQUIRED');
        $invalidClock = $this->request(); $invalidClock['candles'][0]['time'] = '2025-01-01T12:00:00+05:00';
        $this->refuses(fn () => $owner->bindRequests($invalidClock, [$model]), 'METHOD_RESEARCH_ORIGINAL_UTC_SCOPE_REQUIRED');
        $reversed = $this->request(); $reversed['candles'] = array_reverse($reversed['candles']);
        $this->refuses(fn () => $owner->bindRequests($reversed, [$model]), 'METHOD_RESEARCH_PRE2026_SCOPE_REQUIRED');
    }

    public function test_new_scope_programs_and_unselected_library_revision_do_not_change_frozen_policy_library(): void
    {
        [$model, $policy] = $this->boundModel($this->recipe); $owner = app(InstrumentMethodResearchBindingService::class);
        $one = $owner->bindRequests($this->request(), [$model])['policy_context']['instrument_method_learning'];
        $count = DB::table('research_instrument_programs')->count();
        $nextRequest = $this->request(); $nextRequest['replay_dataset_hash'] = str_repeat('c', 64);
        $nextRequest['candles'][0]['time'] = '2025-01-03T12:00:00Z'; $nextRequest['candles'][1]['time'] = '2025-01-03T12:10:00Z';
        $two = $owner->bindRequests($nextRequest, [$model])['policy_context']['instrument_method_learning'];
        $this->assertSame($one['library_hash'], $two['library_hash']);
        $this->assertSame($one['policy']['policy_hash'], $two['policy']['policy_hash']);
        $this->assertNotSame($one['compiled_pool_hash'], $two['compiled_pool_hash']);
        $this->assertGreaterThan($count, DB::table('research_instrument_programs')->count());
        $this->registeredRecipe(2, .25);
        $three = $owner->bindRequests($nextRequest, [$model])['policy_context']['instrument_method_learning'];
        $this->assertSame($one['library_hash'], $three['library_hash']);
        $this->assertSame([$this->recipe['recipe_hash']], $three['library_contract']['recipe_hashes']);
        $this->assertSame($policy['policy_hash'], $three['policy']['policy_hash']);
        $this->assertDatabaseCount('agent_method_policy_revisions', 1);
    }

    public function test_real_study_binding_emits_exact_arm_caps_and_full_trace_requests(): void
    {
        $secondRecipe = $this->registeredRecipe(2, .25); $models = []; $arms = [];
        foreach (['L1A1', 'L2A1', 'L1A2', 'L2A2'] as $key) {
            [$model, $policy] = $this->boundModel(str_starts_with($key, 'L1') ? $this->recipe : $secondRecipe,
                str_ends_with($key, 'A1') ? 'BASELINE' : 'WAIT');
            $models[] = $model; $arms[$key] = ['model_id' => $model->id, 'policy_hash' => $policy['policy_hash']];
        }
        $request = $this->request(); $caps = ['max_rows' => 10, 'max_decision_events' => 32, 'max_runtime_seconds' => 120];
        $scope = app(InstrumentMethodResearchBindingService::class)->scopeFromRequest($request);
        $design = ['study_key' => 'real-binding-synthetic-study', 'arms' => $arms,
            'data_hash' => $scope['data_hash'], 'execution_hash' => $scope['execution_hash'],
            'scope' => ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'evaluated_start' => $scope['evaluated_start'],
                'evaluated_end' => $scope['evaluated_end'], 'context' => []], 'evaluated_rows' => 10,
            'initial_balance' => 1000, 'risk_per_trade' => 1, 'execution_costs' => $request['execution_contract']['parameters'],
            'hard_limits' => ['max_total_risk_percent' => 5], 'resource_caps' => $caps, 'minimum_trades' => 2];
        $study = app(InstrumentMethodStudyService::class)->preregister($design);
        $this->assertSame('preregistered_diagnostic', $study['status'], $study['reason'] ?? '');
        $single = app(InstrumentMethodResearchBindingService::class)->bindRequests($request, [$models[0]]);
        $this->assertSame('L1A1', $single['policy_context']['instrument_method_study']['arm']);
        $this->assertSame($study['study_hash'], $single['policy_context']['instrument_method_study']['study_hash']);
        $this->assertEquals($caps, $single['policy_context']['instrument_method_study_resource_cap']);
        $request['strategies'] = array_map(fn ($model) => ['lab_agent_id' => $this->agent($model)->id], $models);
        $batch = app(InstrumentMethodResearchBindingService::class)->bindRequests($request, array_reverse($models));
        foreach (array_keys($arms) as $index => $key) {
            $this->assertSame($key, $batch['strategies'][$index]['instrument_method_study']['arm']);
            $this->assertEquals($caps, $batch['strategies'][$index]['instrument_method_study']['resource_caps']);
        }
        $this->assertTrue($batch['emit_decision_trace']); $this->assertTrue($batch['emit_trade_ledger']);
        $otherModels = []; $otherDesign = $design; $otherDesign['study_key'] = 'second-study-different-runtime-cap';
        foreach (array_keys($arms) as $key) {
            [$model, $policy] = $this->boundModel(str_starts_with($key, 'L1') ? $this->recipe : $secondRecipe,
                str_ends_with($key, 'A1') ? 'BASELINE' : 'WAIT');
            $otherModels[] = $model; $otherDesign['arms'][$key] = ['model_id' => $model->id, 'policy_hash' => $policy['policy_hash']];
            $request['strategies'][] = ['lab_agent_id' => $this->agent($model)->id];
        }
        $otherDesign['resource_caps']['max_runtime_seconds'] = 121;
        $this->assertSame('preregistered_diagnostic', app(InstrumentMethodStudyService::class)->preregister($otherDesign)['status']);
        $this->refuses(fn () => app(InstrumentMethodResearchBindingService::class)->bindRequests($request, [...$models, ...$otherModels]),
            'METHOD_RESEARCH_BATCH_RESOURCE_CAP_DRIFT');
    }

    public function test_sparse_original_model_seal_preserves_legacy_basis_and_rejects_post_seal_policy_edits(): void
    {
        $evidence = app(LabImmutableEvidenceService::class); $legacy = $this->model();
        $this->assertArrayNotHasKey('instrument_method_learning', $evidence->modelRuntimeBasis($legacy)['components']);
        [$model] = $this->boundModel($this->recipe); $agent = $this->agent($model);
        $request = app(InstrumentMethodResearchBindingService::class)->bindRequests($this->request(), [$model]);
        $run = LabEvaluationRun::create(['run_id' => (string) Str::uuid(), 'model_version_id' => $model->id,
            'lab_generation_id' => $agent->lab_generation_id, 'lab_agent_id' => $agent->id,
            'phase' => 'screening', 'mode' => 'research', 'status' => 'started', 'started_at' => now(),
            'data_hash' => $request['replay_dataset_hash']]);
        $evidence->attachRequest($run, $request);
        $this->assertNotNull($evidence->verifiedModelRuntimeIdentity($run));
        $this->assertSame($model->metadata['instrument_method_learning'],
            $evidence->modelRuntimeBasis($model)['components']['instrument_method_learning']);
        $replacement = $this->policy($model, $this->recipe, 'WAIT', 2);
        $metadata = $model->metadata; $metadata['instrument_method_learning']['policy_hash'] = $replacement['policy_hash'];
        $model->update(['metadata' => $metadata]);
        $this->assertNull($evidence->verifiedModelRuntimeIdentity($run));
    }

    private function registeredRecipe(int $revision, float $multiplier): array
    {
        $owner = app(InstrumentMethodLibraryService::class);
        $source = $owner->registerSource(['kind' => 'imported_text', 'reference' => 'synthetic closed-rule fixture',
            'content' => 'Software contract test only; no market observations.', 'metadata' => [
                'license' => 'user supplied research permission', 'access' => 'user_supplied',
                'exposure' => ['status' => 'unknown', 'historical_training_inventory_complete' => false]]]);
        $methods = [];
        foreach (['confirmation' => 'trend_pullback', 'risk_multiplier' => 'atr_risk_envelope'] as $target => $instrument) {
            $method = $owner->registerMethod(['instrument_key' => $instrument, 'method_key' => 'binding-'.$target,
                'revision' => $revision, 'context_predicate' => $this->context(), 'horizon' => 'hour',
                'known_limitations' => ['Synthetic test declaration; no confirmation evidence.'], 'source_keys' => [$source['source_key']],
                'implementation' => ['kind' => 'typed_ast', 'target' => $target,
                    'ast' => $target === 'risk_multiplier' ? ['op' => 'CONST', 'type' => 'number', 'value' => $multiplier]
                        : ['op' => 'GREATER_THAN', 'args' => [['op' => 'PRICE_CLOSE', 'available_at' => '2025-01-01T00:00:00Z'],
                            ['op' => 'CONST', 'type' => 'number', 'value' => 1.0]]],
                    'input_bindings' => $target === 'risk_multiplier' ? [] : ['price_close' => 'close'],
                    'budget' => ['cpu_seconds' => .5, 'max_calls' => 100, 'max_node_evaluations' => 4800]]]);
            $this->assertSame('registered_research_only', $method['status'], json_encode($method)); $methods[] = $method['method_hash'];
        }
        $recipe = $owner->registerRecipe(['recipe_key' => 'binding-closed-hybrid', 'revision' => $revision,
            'method_hashes' => $methods, 'context_predicate' => $this->context()]);
        $this->assertSame('registered_research_only', $recipe['status'], json_encode($recipe));
        return $recipe;
    }
    private function model(): ModelVersion
    {
        return ModelVersion::create(['name' => 'binding-test-'.Str::uuid(), 'strategy' => 'breakout', 'version' => 1, 'status' => 'draft',
            'parameters' => app(StrategyParameterSchemaService::class)->defaults('breakout'), 'metadata' => ['base_strategy' => 'breakout']]);
    }
    private function policy(ModelVersion $model, array $recipe, string $default = 'BASELINE', int $revision = 1): array
    {
        $policy = app(AgentMethodLearningService::class)->registerPolicy(['agent_key' => 'model:'.$model->id, 'revision' => $revision,
            'recipe_hashes' => [$recipe['recipe_hash']], 'rules' => [['context_predicate' => $this->context(),
                'preferred_recipes' => [$recipe['recipe_hash']]]], 'default_action' => $default]);
        $this->assertSame('registered_research_only', $policy['status'], json_encode($policy)); return $policy;
    }
    private function boundModel(array $recipe, string $default = 'BASELINE'): array
    {
        $model = $this->model(); $policy = $this->policy($model, $recipe, $default);
        app(InstrumentMethodResearchBindingService::class)->attachToUnusedModel($model->id, $policy['policy_hash']);
        return [$model->fresh(), $policy];
    }
    private function agent(ModelVersion $model): LabAgent
    {
        $lab = AiLaboratory::firstOrCreate(['symbol' => 'XAUUSD', 'timeframe' => 'M5'],
            ['name' => 'binding-lab-'.Str::uuid(), 'strategy_families' => ['breakout'], 'is_active' => false]);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id,
            'generation' => 1 + (int) LabGeneration::where('ai_laboratory_id', $lab->id)->max('generation'),
            'status' => 'draft', 'trigger_type' => 'manual']);
        return LabAgent::withoutEvents(fn () => LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'M5', 'strategy_family' => 'breakout', 'origin' => 'constructor', 'lifecycle_status' => 'draft']));
    }
    private function request(): array
    {
        return ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'replay_dataset_hash' => str_repeat('a', 64),
            'initial_balance' => 1000, 'risk_per_trade' => 1, 'execution_contract' => app(ExecutionContractService::class)->for('XAUUSD', 'M5'),
            'candles' => [['time' => '2025-01-01T12:00:00Z'], ['time' => '2025-01-01T12:10:00Z']]];
    }
    private function context(): array { return ['regime' => 'trend_up', 'session' => 'london', 'volatility' => 'normal']; }
    private function refuses(callable $call, string $reason): void
    {
        try { $call(); $this->fail('Expected '.$reason); }
        catch (LogicException $error) { $this->assertSame($reason, $error->getMessage()); }
    }
}
