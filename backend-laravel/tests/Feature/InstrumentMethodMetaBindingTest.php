<?php

namespace Tests\Feature;

use App\Models\ModelVersion;
use App\Services\AgentMethodCandidateService;
use App\Services\AgentMethodGenerationService;
use App\Services\AgentMethodLearningService;
use App\Services\AgentMethodStudyPreparationService;
use App\Services\ExecutionContractService;
use App\Services\InstrumentMethodLibraryService;
use App\Services\InstrumentMethodMetaBindingService;
use App\Services\InstrumentMethodMetaModelService;
use App\Services\InstrumentMethodResearchBindingService;
use App\Services\LabImmutableEvidenceService;
use App\Services\ResearchPaperEpochContractService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;

/** Reuses genuine compressed synthetic producer products; never independent market evidence. */
class InstrumentMethodMetaBindingTest extends InstrumentMethodMetaModelTest
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', 'meta-binding-conditional-fixture-server-key');
    }

    public function test_fitted_original_attachment_is_target_owned_and_runtime_identity_is_sparse(): void
    {
        [$target, $hash] = $this->fitted();
        $owner = app(InstrumentMethodMetaBindingService::class);
        $evidence = app(LabImmutableEvidenceService::class);
        $before = $evidence->modelRuntimeBasis($target);
        $this->assertArrayNotHasKey(InstrumentMethodMetaBindingService::METADATA, $before['components']);
        $attached = $owner->attachUnusedModel((int) $target->id, $hash);
        $this->assertSame('attached_meta_model_prospectively', $attached['status'], json_encode($attached));
        $target->refresh();
        $this->assertEquals(['protocol' => InstrumentMethodMetaBindingService::PROTOCOL, 'model_hash' => $hash],
            $target->metadata[InstrumentMethodMetaBindingService::METADATA]);
        $this->assertSame('original_meta_model_binding_exists', $owner->attachUnusedModel((int) $target->id, $hash)['status']);
        $after = $evidence->modelRuntimeBasis($target);
        $this->assertArrayHasKey(InstrumentMethodMetaBindingService::METADATA, $after['components']);
        $this->assertArrayHasKey(InstrumentMethodMetaBindingService::PROOF_METADATA, $after['components']);
        $this->assertNotSame($this->hash($before), $this->hash($after));
        $originalMetadata = $target->metadata;
        $metadata = $originalMetadata; $metadata[InstrumentMethodMetaBindingService::METADATA]['model_hash'] = str_repeat('b', 64);
        $target->metadata = $metadata;
        $this->assertNotSame($this->hash($after), $this->hash($evidence->modelRuntimeBasis($target)));
        $this->assertDatabaseCount('agent_method_policy_revisions', 0);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        $this->assertFalse($attached['mastery_proven']);
    }

    public function test_php_original_fit_compiles_full_python_runtime_and_consumes_own_score_in_new_entry_method(): void
    {
        [$target, $hash] = $this->fitted();
        $attached = app(InstrumentMethodMetaBindingService::class)->attachUnusedModel((int) $target->id, $hash);
        $this->assertSame('attached_meta_model_prospectively', $attached['status'], json_encode($attached));
        $this->policy($target);
        $request = $this->request($target);
        $request['execution'] = $request['execution_contract']['parameters'];
        $bound = app(InstrumentMethodResearchBindingService::class)->forModel($target->fresh(), $request);
        $this->assertArrayHasKey('meta_model', $bound);
        $this->assertSame(str_repeat('d', 64), $bound['meta_model']['expected_training_binding']['data_hash']);
        $this->assertSame(str_repeat('e', 64), $bound['data_hash']);
        $this->assertNotSame($bound['meta_model']['expected_training_binding']['effective_policy_hash'], $bound['library_hash']);
        $this->assertCount(7, $bound['meta_model']['evaluation_binding']);
        $script = 'import json,sys,pandas as pd; from app.schemas import SimpleBacktestRequest; '
            .'from app.services.instrument_method_learning import InstrumentMethodLearning; v=json.load(sys.stdin); '
            .'p=SimpleBacktestRequest(**v["request"]); e=InstrumentMethodLearning(v["contract"],p); '
            .'f=pd.DataFrame([{"time":pd.Timestamp("2025-01-01T07:30:00Z"),"open":100.0,"high":101.0,"low":99.0,"close":100.0}]); '
            .'e.validate_frame(f); r=e.evaluate({"time":pd.Timestamp("2025-01-01T07:30:00Z"),"open":100.0,"high":101.0,"low":99.0,"close":100.0,"market_regime":"trend_up","market_session":"london",'
            .'"volatility_regime":"normal_volatility","signal_confidence":.8,"method_meta_score":.99,"method_meta_model_available":True},'
            .'decision_id="issuer-meta-entry",phase="entry",observed_at="2025-01-01T07:35:00Z",decision_at="2025-01-01T07:35:00Z",direction="BUY"); '
            .'assert r["used"] and r["outputs"]["confirmation"] is False and r["behavior_changed"]; '
            .'assert r["meta_score_status"]=="diagnostic_calibrated_score_available"; '
            .'assert r["meta_model_hash"]==v["contract"]["meta_model"]["artifact"]["artifact_hash"]; '
            .'assert not r["economic_authority"]; print(json.dumps({"event":r,"receipt":e.receipt()}))';
        [$exit, $stdout, $stderr] = $this->python($script, ['contract' => $bound, 'request' => $request]);
        $this->assertSame(0, $exit, $stderr);
        $actual = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($actual['event']['used']);
        $this->assertFalse($actual['event']['outputs']['confirmation']);
        $this->assertSame($hash, $actual['event']['meta_model_hash']);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        $this->assertDatabaseCount('agent_method_experiences', 0);
    }

    public function test_asset_cannot_late_attach_after_an_own_policy_or_copy_its_proof_to_a_new_model(): void
    {
        [$target, $hash] = $this->fitted(); $owner = app(InstrumentMethodMetaBindingService::class);
        $this->policy($target);
        $this->assertSame('META_BINDING_MUST_PRECEDE_OWN_POLICY_SEAL', $owner->attachUnusedModel((int) $target->id, $hash)['reason']);
        $fresh = $this->model(); $attached = $owner->attachUnusedModel((int) $fresh->id, $hash);
        $this->assertSame('attached_meta_model_prospectively', $attached['status']);
        $copy = $this->model(); $copy->update(['metadata' => $fresh->fresh()->metadata]);
        $this->refused(fn () => $owner->forModel($copy->fresh(), $this->request($copy)), 'META_BINDING_ORIGINAL_ATTACHMENT_REQUIRED');
        $metadata = $fresh->fresh()->metadata;
        $metadata[InstrumentMethodMetaBindingService::PROOF_METADATA]['target_model_id'] = (int) $copy->id;
        $copy->update(['metadata' => $metadata]);
        $this->refused(fn () => $owner->forModel($copy->fresh(), $this->request($copy)), 'META_BINDING_ORIGINAL_ATTACHMENT_REQUIRED');
    }

    public function test_actual_parameters_cost_account_clock_and_plain_scope_are_required_before_cache(): void
    {
        [$target, $hash] = $this->fitted(); $owner = app(InstrumentMethodMetaBindingService::class);
        $this->assertSame('attached_meta_model_prospectively', $owner->attachUnusedModel((int) $target->id, $hash)['status']);
        $target->refresh(); $request = $this->request($target);
        foreach ([['initial_balance' => 20000], ['risk_per_trade' => 2],
            ['policy_context' => ['hard_limits' => ['max_risk' => 1]]],
            ['timeframe' => 'M15', 'execution_contract' => app(ExecutionContractService::class)->for('XAUUSD', 'M15')]] as $change) {
            $this->refused(fn () => $owner->forModel($target, [...$request, ...$change]), 'META_BINDING_PHYSICAL_EVALUATION_DRIFT');
        }
        $this->refused(fn () => $owner->forModel($target, [...$request, 'parameters' => ['entry_confidence' => .6]]), 'META_BINDING_ACTUAL_MODEL_PARAMETERS_REQUIRED');
        $this->refused(fn () => $owner->forModel($target, [...$request, 'portfolio_members' => [['allocation' => .25]]]), 'META_BINDING_ORDINARY_EVALUATION_REQUIRED');
        $this->refused(fn () => $owner->forModel($target, [...$request, 'hard_limits' => []]), 'META_BINDING_UNSUPPORTED_TOP_LEVEL_CONTROL');
        $changed = $request; $changed['execution_contract']['parameters']['spread_points'] += 1;
        $this->refused(fn () => $owner->forModel($target, $changed), 'META_BINDING_ACTUAL_EXECUTION_REQUIRED');
        $changed = $request; $changed['execution'] = $request['execution_contract']['parameters'];
        $changed['execution']['spread_points'] += 1;
        $this->refused(fn () => $owner->forModel($target, $changed), 'META_BINDING_ACTUAL_EXECUTION_REQUIRED');
        $this->policy($target);
        $this->refused(fn () => app(InstrumentMethodResearchBindingService::class)->compilePolicy(
            data_get($target->fresh()->metadata, 'instrument_method_learning.policy_hash'),
            ['strategy_family' => $target->strategy, 'parameters' => $target->parameters],
            app(InstrumentMethodResearchBindingService::class)->scopeFromRequest($request), 'model:'.$target->id),
            'META_BINDING_ACTUAL_EVALUATION_REQUEST_REQUIRED');
        $source = DB::table('instrument_method_meta_registrations')->first();
        $sourceDesign = json_decode($source->design, true);
        $artifact = \App\Models\LabEvidenceArtifact::where('run_id', $sourceDesign['roster'][0]['run_id'])->where('artifact_type', 'trade_ledger')->first();
        Storage::disk('meta_original_test')->put($artifact->storage_path, gzencode('[{"poison":true}]'));
        $this->refused(fn () => $owner->forModel($target->fresh(), $request), 'META_MODEL_ORIGINAL_MODEL_PROOF_REQUIRED');
    }

    public function test_underpowered_roster_cannot_be_fabricated_as_asset_and_existing_transfer_routes_refuse_silent_drop(): void
    {
        $run = $this->original(12); $models = app(InstrumentMethodMetaModelService::class);
        $registered = $models->preregister($this->design([$run->run_id])); $fit = $models->fit($registered['registration_hash']);
        $this->assertSame('underpowered', $fit['status']); $this->assertNull($fit['model_hash']);
        $target = $this->model();
        $this->assertSame('META_MODEL_FITTED_ARTIFACT_NOT_FOUND', app(InstrumentMethodMetaBindingService::class)->attachUnusedModel((int) $target->id, str_repeat('b', 64))['reason']);
        $target->update(['metadata' => [...$target->metadata, InstrumentMethodMetaBindingService::METADATA => ['protocol' => InstrumentMethodMetaBindingService::PROTOCOL, 'model_hash' => str_repeat('b', 64)]]]);
        $this->refused(fn () => app(AgentMethodCandidateService::class)->baseline($target->fresh()), 'METHOD_CANDIDATE_META_ASSET_TRANSFER_OWNER_REQUIRED');
        $child = $this->model();
        $this->assertSame('METHOD_GENERATION_META_ASSET_TRANSFER_OWNER_REQUIRED', app(AgentMethodGenerationService::class)->prepareFreshChild($child, $target->fresh())['reason']);
        $input = ['source_model_id' => (int) $target->id, 'libraries' => [], 'study' => [], 'source' => []];
        $this->assertSame('METHOD_POLICY_STUDY_META_ASSET_TRANSFER_OWNER_REQUIRED', app(AgentMethodStudyPreparationService::class)->prepare($input)['reason']);
    }

    private function fitted(): array
    {
        $target = $this->model();
        $run = $this->original(30, ['parameters' => $target->parameters]); $owner = app(InstrumentMethodMetaModelService::class);
        $registered = $owner->preregister($this->design([$run->run_id])); $fit = $owner->fit($registered['registration_hash']);
        $this->assertSame('fitted_diagnostic', $fit['status'], json_encode($fit));
        return [$target, $fit['model_hash']];
    }
    private function model(): ModelVersion
    {
        return ModelVersion::create(['name' => 'meta-binding-target-'.Str::uuid(), 'strategy' => 'differential_router',
            'status' => 'draft', 'parameters' => app(\App\Services\StrategyParameterSchemaService::class)->defaults('differential_router'),
            'metadata' => ['base_strategy' => 'differential_router']])->fresh();
    }
    private function policy(ModelVersion $target): array
    {
        $library = app(InstrumentMethodLibraryService::class);
        $source = $library->registerSource(['kind' => 'imported_text', 'reference' => 'Genuine synthetic meta producer consumer fixture',
            'content' => 'A new finite model score veto with no authority.', 'metadata' => ['license' => 'owned test fixture',
                'access' => 'user_supplied', 'exposure' => ['status' => 'synthetic_fixture']]]);
        $context = ['regime' => 'trend_up', 'session' => 'london', 'volatility' => 'normal'];
        $method = $library->registerMethod(['instrument_key' => 'confidence_firewall', 'method_key' => 'new-issued-meta-score-fixture',
            'revision' => 1, 'context_predicate' => $context, 'horizon' => 'hour', 'source_keys' => [$source['source_key']],
            'known_limitations' => ['Conditional synthetic issuer fixture; no independently qualified trading model.'],
            'implementation' => ['kind' => 'typed_ast', 'target' => 'confirmation',
                'ast' => ['op' => 'GREATER_THAN', 'args' => [['op' => 'NUMBER', 'input_key' => 'score', 'available_at' => '2000-01-01T00:00:00Z'],
                    ['op' => 'CONST', 'type' => 'number', 'value' => .7]]], 'input_bindings' => ['score' => 'method_meta_score'],
                'budget' => ['cpu_seconds' => 1.0, 'max_calls' => 100000, 'max_node_evaluations' => 4800000]]]);
        $this->assertSame('registered_research_only', $method['status'], json_encode($method));
        $recipe = $library->registerRecipe(['recipe_key' => 'issued-meta-score-fixture-recipe', 'revision' => 1,
            'method_hashes' => [$method['method_hash']], 'context_predicate' => $context]);
        $policy = app(AgentMethodLearningService::class)->registerPolicy(['agent_key' => 'model:'.$target->id,
            'revision' => 1, 'recipe_hashes' => [$recipe['recipe_hash']],
            'rules' => [['context_predicate' => $context, 'preferred_recipes' => [$recipe['recipe_hash']]]], 'default_action' => 'BASELINE']);
        app(InstrumentMethodResearchBindingService::class)->attachToUnusedModel((int) $target->id, $policy['policy_hash']);
        return $policy;
    }
    private function request(ModelVersion $target): array
    {
        return ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'strategy' => $target->strategy, 'parameters' => $target->parameters,
            'initial_balance' => 10000, 'risk_per_trade' => 1, 'replay_dataset_hash' => str_repeat('e', 64),
            'execution_contract' => app(ExecutionContractService::class)->for('XAUUSD', 'M5'),
            'candles' => [['time' => '2025-01-01T07:30:00Z', 'open' => 100.0, 'high' => 101.0, 'low' => 99.0, 'close' => 100.0],
                ['time' => '2025-01-01T07:35:00Z', 'open' => 100.0, 'high' => 101.0, 'low' => 99.0, 'close' => 100.0]]];
    }
    private function refused(callable $call, string $reason): void
    {
        try { $call(); $this->fail('Expected original asset refusal '.$reason); }
        catch (LogicException $error) { $this->assertSame($reason, $error->getMessage()); }
    }
    /** Direct argv avoids CMD and the explicit Windows option avoids a console flash. */
    private function python(string $script, array $input): array
    {
        $process = proc_open([env('PYTHON_BINARY', 'python'), '-c', $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
            dirname(base_path()).'/ai-service-python', null,
            ['bypass_shell' => true, 'create_no_window' => true]);
        $this->assertIsResource($process);
        fwrite($pipes[0], json_encode($input, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)); fclose($pipes[0]);
        stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $stdout = ''; $stderr = ''; $deadline = microtime(true) + 30;
        do {
            $stdout .= stream_get_contents($pipes[1]); $stderr .= stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (! $status['running']) break;
            if (microtime(true) >= $deadline) { proc_terminate($process); $stderr .= ' TEST_PYTHON_FIXED_30_SECOND_CAP'; break; }
            usleep(20000);
        } while (true);
        $stdout .= stream_get_contents($pipes[1]); $stderr .= stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $closed = proc_close($process);
        return [$status['exitcode'] >= 0 ? $status['exitcode'] : $closed, $stdout, $stderr];
    }
    private function hash(array $value): string { return app(ResearchPaperEpochContractService::class)->parameterHash($value); }
}
