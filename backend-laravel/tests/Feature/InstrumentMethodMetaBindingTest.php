<?php

namespace Tests\Feature;

use App\Models\ModelVersion;
use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\ResearchExperimentWorkItem;
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
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use LogicException;
use Carbon\CarbonImmutable;

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

    public function test_underpowered_roster_cannot_be_fabricated_as_asset_and_transfer_routes_refuse_invalid_issuer(): void
    {
        $run = $this->original(12); $models = app(InstrumentMethodMetaModelService::class);
        $registered = $models->preregister($this->design([$run->run_id])); $fit = $models->fit($registered['registration_hash']);
        $this->assertSame('underpowered', $fit['status']); $this->assertNull($fit['model_hash']);
        $target = $this->model();
        $this->assertSame('META_MODEL_FITTED_ARTIFACT_NOT_FOUND', app(InstrumentMethodMetaBindingService::class)->attachUnusedModel((int) $target->id, str_repeat('b', 64))['reason']);
        $target->update(['metadata' => [...$target->metadata, InstrumentMethodMetaBindingService::METADATA => ['protocol' => InstrumentMethodMetaBindingService::PROTOCOL, 'model_hash' => str_repeat('b', 64)]]]);
        $this->refused(fn () => app(AgentMethodCandidateService::class)->baseline($target->fresh()), 'META_MODEL_FITTED_ARTIFACT_NOT_FOUND');
        $child = $this->model();
        $this->assertSame('META_MODEL_FITTED_ARTIFACT_NOT_FOUND', app(AgentMethodGenerationService::class)->prepareFreshChild($child, $target->fresh())['reason']);
        $input = ['source_model_id' => (int) $target->id, 'libraries' => [], 'study' => [], 'source' => []];
        $this->assertSame('META_MODEL_FITTED_ARTIFACT_NOT_FOUND', app(AgentMethodStudyPreparationService::class)->prepare($input)['reason']);
    }

    public function test_original_parent_asset_is_reattached_with_fresh_target_seal_and_original_training_refs(): void
    {
        [$source, $hash] = $this->fitted();
        $owner = app(InstrumentMethodMetaBindingService::class);
        $this->assertSame('attached_meta_model_prospectively', $owner->attachUnusedModel((int) $source->id, $hash)['status']);
        $this->policy($source); $source->refresh();
        $beforeSource = $source->toArray();
        $beforeArtifact = DB::table('instrument_method_meta_models')->where('model_hash', $hash)->first()->artifact_json;
        $sourceAsset = $owner->assetForModel($source);
        $child = $this->model();
        $result = $owner->reattachToUnusedChild((int) $source->id, (int) $child->id);
        $this->assertSame('reattached_meta_model_prospectively', $result['status'], json_encode($result));
        $child->refresh();
        $asset = $owner->assetForModel($child);
        $this->assertSame($sourceAsset['reference'], $asset['reference']);
        $this->assertSame($sourceAsset['original_training_binding_hash'], $asset['original_training_binding_hash']);
        $this->assertNotSame($sourceAsset['attachment_hash'], $asset['attachment_hash']);
        $proof = $child->metadata[InstrumentMethodMetaBindingService::PROOF_METADATA];
        $this->assertSame((int) $child->id, $proof['target_model_id']);
        $this->assertSame((int) $source->id, $proof['transfer']['source_model_id']);
        $this->assertSame($sourceAsset['attachment_hash'], $proof['transfer']['source_attachment_hash']);
        $this->assertSame(1, $proof['transfer']['depth']);
        $beforeChild = $child->toArray();
        $this->assertSame('original_meta_model_transfer_exists', $owner->reattachToUnusedChild((int) $source->id, (int) $child->id)['status']);
        $this->assertSame($beforeChild, $child->fresh()->toArray());
        $childPolicy = $this->policy($child); $child->refresh();
        $request = $this->request($child);
        $bound = app(InstrumentMethodResearchBindingService::class)->forModel($child, $request);
        $this->assertSame($hash, $bound['meta_model']['artifact']['artifact_hash']);
        $this->assertSame($sourceAsset['original_training_binding_hash'], $this->hash($bound['meta_model']['artifact']['binding']));
        $this->assertNotSame($bound['meta_model']['expected_training_binding']['effective_policy_hash'], $childPolicy['policy_hash']);
        $this->assertSame($beforeSource, $source->fresh()->toArray());
        $this->assertSame($beforeArtifact, DB::table('instrument_method_meta_models')->where('model_hash', $hash)->first()->artifact_json);
        $this->assertDatabaseCount('instrument_method_meta_models', 1);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_transferred_asset_rejects_ancestor_declared_scope_drift(): void
    {
        [$source, $hash] = $this->fitted();
        $owner = app(InstrumentMethodMetaBindingService::class);
        $this->assertSame('attached_meta_model_prospectively', $owner->attachUnusedModel((int) $source->id, $hash)['status']);
        $child = $this->model();
        $this->assertSame('reattached_meta_model_prospectively', $owner->reattachToUnusedChild((int) $source->id, (int) $child->id)['status']);
        $this->assertSame($hash, $owner->assetForModel($child->fresh())['model_hash']);
        $source->refresh();
        $source->update(['metadata' => [...$source->metadata, 'lab_timeframe' => 'H1']]);
        $this->refused(fn () => $owner->assetForModel($source->fresh()), 'META_BINDING_TRANSFER_SCOPE_DRIFT');
        $this->refused(fn () => $owner->assetForModel($child->fresh()), 'META_BINDING_TRANSFER_SCOPE_DRIFT');
    }

    public function test_transfer_refuses_copied_binding_physical_scope_chronology_and_already_owned_targets(): void
    {
        [$source, $hash] = $this->fitted();
        $owner = app(InstrumentMethodMetaBindingService::class);
        $this->assertSame('attached_meta_model_prospectively', $owner->attachUnusedModel((int) $source->id, $hash)['status']);
        $source->refresh();
        foreach (['copy', 'parameter', 'risk', 'clock', 'future_created', 'native', 'owned'] as $case) {
            $child = $this->model();
            if ($case === 'copy') $child->update(['metadata' => $source->metadata]);
            if ($case === 'parameter') $child->update(['parameters' => [...$child->parameters, 'atr_target_multiplier' => $child->parameters['atr_target_multiplier'] + .1]]);
            if ($case === 'risk') $child->update(['metadata' => [...$child->metadata, 'risk_governor' => ['risk_per_trade' => 2.0]]]);
            if ($case === 'clock') $child->update(['metadata' => [...$child->metadata, 'lab_timeframe' => 'H1']]);
            if ($case === 'future_created') $child->forceFill(['created_at' => now()->addDay()])->save();
            if ($case === 'native') $child->update(['metadata' => [...$child->metadata, 'specialist_council' => ['protocol' => 'unsupported-native-fixture']]]);
            if ($case === 'owned') $this->policy($child);
            $child->refresh(); $before = $child->toArray();
            $result = $owner->reattachToUnusedChild((int) $source->id, (int) $child->id);
            $expected = match ($case) {
                'copy' => 'META_BINDING_ORIGINAL_ATTACHMENT_REQUIRED',
                'parameter' => 'META_BINDING_ORIGINAL_PHYSICAL_MODEL_REQUIRED',
                'risk' => 'META_BINDING_TRANSFER_PHYSICAL_DRIFT',
                'clock' => 'META_BINDING_TRANSFER_SCOPE_DRIFT',
                'future_created' => 'META_BINDING_TRANSFER_CHRONOLOGY_REQUIRED',
                'native' => 'METHOD_CANDIDATE_SOURCE_RUNTIME_REQUIRES_EXISTING_CONSTRUCTOR',
                'owned' => 'META_BINDING_MUST_PRECEDE_OWN_POLICY_SEAL',
            };
            $this->assertSame('blocked', $result['status'], $case);
            $this->assertSame($expected, $result['reason'], $case);
            $this->assertSame($before, $child->fresh()->toArray());
        }
        $this->assertDatabaseCount('instrument_method_meta_models', 1);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_generation_reattaches_issuer_asset_and_pins_it_without_claiming_parent_selection_learning(): void
    {
        [$source, $hash] = $this->fitted();
        $meta = app(InstrumentMethodMetaBindingService::class);
        $this->assertSame('attached_meta_model_prospectively', $meta->attachUnusedModel((int) $source->id, $hash)['status']);
        $source->refresh(); $beforeSource = $source->toArray();
        $recipeOwner = $this->model(); $recipePolicy = $this->policy($recipeOwner);
        $recipe = $recipePolicy['library_contract']['recipe_hashes'][0];
        $child = $this->model(); $beforeParameters = $child->parameters;
        $result = app(AgentMethodGenerationService::class)->prepareFreshChild($child, $source,
            ['curated_recipe_hashes' => [$recipe], 'generation_id' => 2, 'slot' => 1]);
        $this->assertSame('prospective_child_policy_attached', $result['status'], json_encode($result));
        $policy = app(AgentMethodLearningService::class)->policy($result['policy_hash']);
        $this->assertSame('curated_default', $policy['learning_provenance']['mode']);
        $this->assertSame($hash, $policy['learning_provenance']['meta_asset_binding']['model_hash']);
        $this->assertSame($meta->assetForModel($child->fresh())['attachment_hash'], $policy['learning_provenance']['meta_asset_binding']['attachment_hash']);
        $this->assertSame($beforeParameters, $child->fresh()->parameters);
        $this->assertSame($beforeSource, $source->fresh()->toArray());
        $this->assertFalse($result['mastery_proven']); $this->assertFalse($result['economic_authority']);
        $metadata = $child->fresh()->metadata;
        unset($metadata[InstrumentMethodMetaBindingService::METADATA], $metadata[InstrumentMethodMetaBindingService::PROOF_METADATA]);
        $child->update(['metadata' => $metadata]);
        $this->assertSame('blocked', app(AgentMethodLearningService::class)->policy($result['policy_hash'])['status']);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_original_fitted_asset_follows_new_owned_practice_into_candidate_and_learned_generation_child(): void
    {
        [$source, $hash] = $this->fitted();
        $meta = app(InstrumentMethodMetaBindingService::class);
        $this->assertSame('attached_meta_model_prospectively', $meta->attachUnusedModel((int) $source->id, $hash)['status']);
        [$source, $initial] = $this->metaPractice($source->fresh());
        $beforeSource = $source->toArray();
        $beforeArtifact = DB::table('instrument_method_meta_models')->where('model_hash', $hash)->first()->artifact_json;
        $sourceAsset = $meta->assetForModel($source);
        $candidate = app(AgentMethodCandidateService::class)->evolve($initial['policy_hash'], 2);
        $this->assertSame('fresh_research_candidate', $candidate['status'], json_encode($candidate));
        $candidateModel = ModelVersion::findOrFail($candidate['model_id']);
        $candidateAsset = $meta->assetForModel($candidateModel);
        $this->assertSame($hash, $candidateAsset['model_hash']);
        $this->assertNotSame($sourceAsset['attachment_hash'], $candidateAsset['attachment_hash']);
        $candidatePolicy = app(AgentMethodLearningService::class)->policy($candidate['policy_hash']);
        $this->assertTrue(app(AgentMethodCandidateService::class)->verifyRebasedProvenance($candidatePolicy['learning_provenance'], $candidatePolicy));
        $this->assertSame('original_research_candidate_exists', app(AgentMethodCandidateService::class)->evolve($initial['policy_hash'], 2)['status']);
        $child = $this->model();
        $generated = app(AgentMethodGenerationService::class)->prepareFreshChild($child, $source);
        $this->assertSame('prospective_child_policy_attached', $generated['status'], json_encode($generated));
        $policy = app(AgentMethodLearningService::class)->policy($generated['policy_hash']);
        $this->assertSame('original_parent_observation', $policy['learning_provenance']['mode']);
        $this->assertSame($hash, $policy['learning_provenance']['meta_asset_binding']['model_hash']);
        $this->assertSame($beforeSource, $source->fresh()->toArray());
        $this->assertSame($beforeArtifact, DB::table('instrument_method_meta_models')->where('model_hash', $hash)->first()->artifact_json);
        $this->assertDatabaseCount('instrument_method_meta_models', 1);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_four_meta_study_carriers_reopen_same_original_asset_and_actual_physical_request(): void
    {
        $limits = ['max_total_risk_percent' => 5.0];
        [$source, $hash] = $this->fitted(false, ['policy_context' => ['hard_limits' => $limits]]);
        $execution = app(ExecutionContractService::class)->for('XAUUSD', 'M5');
        $source->update(['metadata' => [...$source->metadata, 'execution_contract' => $execution]]);
        $meta = app(InstrumentMethodMetaBindingService::class);
        $this->assertSame('attached_meta_model_prospectively', $meta->attachUnusedModel((int) $source->id, $hash)['status']);
        [$source, $initial] = $this->metaPractice($source->fresh(), $limits);
        $child = $this->model();
        $child->update(['metadata' => [...$child->metadata, 'execution_contract' => $execution]]);
        $child->refresh();
        $generated = app(AgentMethodGenerationService::class)->prepareFreshChild($child, $source);
        $this->assertSame('prospective_child_policy_attached', $generated['status'], json_encode($generated));
        $child->refresh(); $beforeChild = $child->toArray();
        $original = $initial['library_contract']['recipe_hashes'][0];
        $library = app(InstrumentMethodLibraryService::class);
        $recipe = $library->inspectRecipe($original)['contract'];
        $method = array_values($recipe['method_contracts'])[0];
        $revised = $library->registerMethod(array_intersect_key($method,
            array_flip(['instrument_key', 'method_key', 'context_predicate', 'horizon', 'known_limitations', 'source_keys', 'implementation']))
            + ['revision' => 2]);
        $this->assertSame('registered_research_only', $revised['status']);
        $l2 = $library->registerRecipe(['recipe_key' => $recipe['recipe_key'], 'revision' => 2,
            'method_hashes' => [$revised['method_hash']], 'context_predicate' => $recipe['context_predicate']]);
        $this->assertSame('registered_research_only', $l2['status']);
        $folder = storage_path('app/lab-datasets/meta-transfer-study-fixture-'.Str::uuid());
        File::ensureDirectoryExists($folder);
        $start = CarbonImmutable::parse('2025-11-03T07:00:00Z');
        $csv = "time,open,high,low,close\n";
        for ($index = 0; $index < 512; $index++) $csv .= $start->addMinutes(5 * $index)->format('Y-m-d\TH:i:s\Z').",100,101,99,100\n";
        File::put($folder.'/m5.csv', $csv);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($folder));
        $input = ['source_model_id' => (int) $child->id,
            'libraries' => ['L1' => [$original], 'L2' => [$l2['recipe_hash']]],
            'source' => ['dataset_path' => $folder.'/m5.csv', 'dataset_sha256' => hash('sha256', $csv)],
            'study' => ['data_hash' => hash('sha256', $csv), 'execution_hash' => $execution['execution_hash'],
                'scope' => ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'context' => $recipe['context_predicate'],
                    'evaluated_start' => $start->addMinutes(1000)->format('Y-m-d\TH:i:s\Z'),
                    'evaluated_end' => $start->addMinutes(2555)->format('Y-m-d\TH:i:s\Z')],
                'evaluated_rows' => 312, 'initial_balance' => 10000.0, 'risk_per_trade' => 1.0,
                'execution_costs' => $execution['parameters'], 'hard_limits' => $limits,
                'resource_caps' => ['max_rows' => 512, 'max_decision_events' => 4096, 'max_runtime_seconds' => 120], 'minimum_trades' => 10]];
        $beforeArtifact = DB::table('instrument_method_meta_models')->where('model_hash', $hash)->first()->artifact_json;
        $prepared = app(AgentMethodStudyPreparationService::class)->prepare($input);
        $this->assertSame('own_policy_study_registered', $prepared['status'], json_encode($prepared));
        foreach ($prepared['arms'] as $arm => $identity) {
            $carrier = ModelVersion::findOrFail($identity['model_id']);
            $asset = $meta->assetForModel($carrier);
            $this->assertSame($hash, $asset['model_hash']);
            $this->assertSame($asset['reference'], app(AgentMethodCandidateService::class)->baseline($carrier)['metadata'][InstrumentMethodMetaBindingService::METADATA]);
            $withoutCosts = clone $carrier; $withoutCostMetadata = (array) $carrier->metadata;
            unset($withoutCostMetadata['execution_contract']); $withoutCosts->metadata = $withoutCostMetadata;
            $this->refused(fn () => app(AgentMethodCandidateService::class)->baseline($withoutCosts), 'META_BINDING_ORIGINAL_ATTACHMENT_REQUIRED');
            $this->assertSame((int) $carrier->id, $carrier->metadata[InstrumentMethodMetaBindingService::PROOF_METADATA]['target_model_id']);
            $this->assertSame((int) $child->id, $carrier->metadata[InstrumentMethodMetaBindingService::PROOF_METADATA]['transfer']['source_model_id']);
            $request = $this->request($carrier);
            $request['policy_context'] = ['hard_limits' => $limits];
            $bound = app(InstrumentMethodResearchBindingService::class)->forModel($carrier, $request);
            $this->assertSame($hash, $bound['meta_model']['artifact']['artifact_hash'], $arm);
            $this->assertSame($asset['original_training_binding_hash'], $this->hash($bound['meta_model']['artifact']['binding']));
        }
        config()->set('services.autonomous_mode.default_enabled', true);
        config()->set('queue.default', 'database');
        // The existing canonical work stream is H1; this does not replace the
        // independently pinned M5 execution clock of the trained asset/arms.
        AiLaboratory::create(['name' => 'meta-study-canonical-h1-stream-fixture', 'symbol' => 'XAUUSD',
            'timeframe' => 'H1', 'strategy_families' => ['differential_router']]);
        Bus::fake();
        $work = ResearchExperimentWorkItem::findOrFail($prepared['work_id']);
        $executionOwner = app(\App\Services\InstrumentMethodStudyExecutionService::class);
        $ready = $executionOwner->inspectWork($work);
        $this->assertSame('ready', $ready['status'], json_encode($ready));
        $claimed = app(\App\Services\ResearchExperimentConversionKernelService::class)
            ->claimForOwner(\App\Services\ResearchLoopArbiterService::class, 1);
        $this->assertCount(1, $claimed);
        $leased = $claimed[0];
        $queued = app(\App\Services\ResearchExperimentWorkConsumerService::class)
            ->execute($leased->id, $leased->lease_token, $leased->fence_version);
        $this->assertSame('queued', $queued['status'], json_encode($queued));
        $barrier = data_get($leased->fresh()->result, 'original_barrier');
        $this->assertTrue($barrier['all_four_originals_ready']);
        $this->assertCount(4, $barrier['request_hashes']);
        $this->assertSame('ready', $executionOwner->inspectWork($leased->fresh())['status']);
        Bus::assertDispatched(\App\Jobs\ExecuteScopedResearchArmJob::class, 1);
        Bus::assertNotDispatched(\App\Jobs\EvaluateLabAgentJob::class);
        $this->assertSame($beforeChild, $child->fresh()->toArray());
        $this->assertSame($beforeArtifact, DB::table('instrument_method_meta_models')->where('model_hash', $hash)->first()->artifact_json);
        $this->assertDatabaseCount('instrument_method_meta_models', 1);
        $this->assertDatabaseCount('research_experiment_work_items', 1);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_original_float_wire_model_attaches_integer_numeric_target_and_actual_simple_api_consumes_projection(): void
    {
        [$target, $hash] = $this->fitted(true); $owner = app(InstrumentMethodMetaBindingService::class);
        $before = DB::table('instrument_method_meta_models')->where('model_hash', $hash)->first()->artifact_json;
        $attached = $owner->attachUnusedModel((int) $target->id, $hash);
        $this->assertSame('attached_meta_model_prospectively', $attached['status'], json_encode($attached));
        $this->policy($target); $target->refresh(); $request = $this->request($target);
        $request['execution'] = $request['execution_contract']['parameters'];
        $bound = app(InstrumentMethodResearchBindingService::class)->forModel($target, $request);
        $this->assertIsInt($target->parameters['atr_target_multiplier']);
        $this->assertNotSame($this->hash($target->parameters), $bound['meta_model']['expected_training_binding']['parameters_hash']);
        $this->assertSame($before, DB::table('instrument_method_meta_models')->where('model_hash', $hash)->first()->artifact_json);
        $request['policy_context']['instrument_method_learning'] = $bound;
        $script = <<<'PY'
import json,sys,pandas as pd
from app import main
from app.schemas import SimpleBacktestRequest
from app.services.instrument_method_learning import InstrumentMethodLearning
v=json.load(sys.stdin)
captured={}
def bounded(operation,p):
    assert operation=='simple'
    assert type(p.parameters['atr_target_multiplier']) is float
    assert type(p.parameters['time_stop_candles']) is int
    e=InstrumentMethodLearning(p.policy_context['instrument_method_learning'],p)
    e.validate_frame(pd.DataFrame([{'time':pd.Timestamp('2025-01-01T07:30:00Z'),'open':100.0,'high':101.0,'low':99.0,'close':100.0}]))
    r=e.evaluate({'time':pd.Timestamp('2025-01-01T07:30:00Z'),'open':100.0,'high':101.0,'low':99.0,'close':100.0,'market_regime':'trend_up','market_session':'london','volatility_regime':'normal_volatility','signal_confidence':.8},decision_id='actual-simple-projection',phase='entry',observed_at='2025-01-01T07:35:00Z',decision_at='2025-01-01T07:35:00Z',direction='BUY')
    assert r['used'] and r['outputs']['confirmation'] is False
    assert r['meta_score_status']=='diagnostic_calibrated_score_available'
    assert not r['economic_authority']
    captured.update(r)
    return dict(strategy=p.strategy,parameters=p.parameters,instrument=p.symbol,timeframe=p.timeframe,period='conditional API normalization fixture',initial_balance=p.initial_balance,final_balance=p.initial_balance,net_profit_percent=0,total_trades=0,wins=0,losses=0,winrate=0,profit_factor=0,max_drawdown=0,stability_score=0,top_mistakes=[],trades=[],conclusion='Conditional API/finite-model consumption only; no replay or economic proof.')
main._run_bounded_replay=bounded
result=main.run_simple_backtest_api(SimpleBacktestRequest(**v['request']))
assert type(result.parameters['atr_target_multiplier']) is float
print(json.dumps({'event':captured,'parameters':result.parameters}))
PY;
        [$exit, $stdout, $stderr] = $this->python($script, ['request' => $request]);
        $this->assertSame(0, $exit, $stderr);
        $actual = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($hash, $actual['event']['meta_model_hash']);
        $this->assertTrue($actual['event']['used']);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_schema_numeric_projection_rejects_other_type_aliases_unknown_fields_and_changed_values(): void
    {
        $models = app(InstrumentMethodMetaModelService::class);
        $base = ['atr_target_multiplier' => 2, 'time_stop_candles' => 4, 'meta_label_enabled' => false, 'volume_lane' => 'none'];
        $projected = $models->projectPhysicalParameters('differential_router', $base);
        $this->assertSame(2.0, $projected['atr_target_multiplier']);
        $this->assertSame(4, $projected['time_stop_candles']);
        $this->assertFalse($projected['meta_label_enabled']);
        foreach ([['atr_target_multiplier', true], ['atr_target_multiplier', '2'], ['atr_target_multiplier', INF],
            ['time_stop_candles', 4.0], ['meta_label_enabled', 0], ['volume_lane', 0], ['unknown_field', 2]] as [$field, $value]) {
            $reason = $field === 'unknown_field' ? 'META_MODEL_PARAMETER_PROJECTION_SCHEMA_REQUIRED' : 'META_MODEL_PARAMETER_PROJECTION_TYPE_REQUIRED';
            $this->refused(fn () => $models->projectPhysicalParameters('differential_router', [...$base, $field => $value]), $reason);
        }
        $this->refused(fn () => $models->projectPhysicalParameters('unknown_strategy', $base), 'META_MODEL_PARAMETER_PROJECTION_SCHEMA_REQUIRED');
        $this->refused(fn () => $models->projectPhysicalParameters('differential_router', $base, 'unknown_base'), 'META_MODEL_PARAMETER_PROJECTION_SCHEMA_REQUIRED');
        [$target, $hash] = $this->fitted(true); $owner = app(InstrumentMethodMetaBindingService::class);
        $this->assertSame('attached_meta_model_prospectively', $owner->attachUnusedModel((int) $target->id, $hash)['status']);
        $request = $this->request($target->fresh());
        foreach ([['atr_target_multiplier', 2.01], ['time_stop_candles', 4.0], ['meta_label_enabled', 0], ['unknown_field', 2]] as [$field, $value]) {
            $changed = $request; $changed['parameters'][$field] = $value;
            $this->refused(fn () => $owner->forModel($target->fresh(), $changed), 'META_BINDING_ACTUAL_MODEL_PARAMETERS_REQUIRED');
        }
        foreach ([false, 2, 'unknown_base'] as $baseStrategy) {
            $this->refused(fn () => $owner->forModel($target->fresh(), [...$request, 'base_strategy' => $baseStrategy]), 'META_BINDING_ACTUAL_MODEL_PARAMETERS_REQUIRED');
        }
    }

    public function test_projection_export_reopens_raw_original_and_does_not_rewrite_legacy_export_or_artifact(): void
    {
        [$_target, $hash] = $this->fitted(true); $models = app(InstrumentMethodMetaModelService::class);
        $legacy = $models->exportOriginalModel($hash); $projected = $models->exportOriginalModel($hash, true);
        $this->assertArrayNotHasKey('parameter_projection', $legacy);
        $this->assertSame($legacy['artifact'], $projected['artifact']);
        $this->assertSame($legacy['expected_binding'], $projected['expected_binding']);
        $this->assertSame($legacy['artifact']['binding']['parameters_hash'], $this->hash($projected['parameter_projection']['original_parameters']));
        $registration = DB::table('instrument_method_meta_registrations')->first(); $design = json_decode($registration->design, true);
        $artifact = \App\Models\LabEvidenceArtifact::where('run_id', $design['roster'][0]['run_id'])->where('artifact_type', 'evaluation_request')->first();
        Storage::disk('meta_original_test')->put($artifact->storage_path, gzencode('{"parameters":{"atr_target_multiplier":2}}'));
        $this->assertSame('META_MODEL_ORIGINAL_MODEL_PROOF_REQUIRED', $models->exportOriginalModel($hash, true)['reason']);
    }

    private function fitted(bool $floatWire = false, array $requestOptions = []): array
    {
        $target = $this->model();
        $owner = app(InstrumentMethodMetaModelService::class);
        if ($floatWire) { $target->update(['parameters' => [...$target->parameters, 'atr_target_multiplier' => 2]]); $target->refresh(); }
        $parameters = $floatWire ? $owner->projectPhysicalParameters($target->strategy, $target->parameters) : $target->parameters;
        $run = $this->original(30, ['parameters' => $parameters, ...$requestOptions]);
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
    /** New synthetic original practice; it never changes a fitted issuer's training artifacts. */
    private function metaPractice(ModelVersion $source, ?array $limits = null): array
    {
        $policy = $this->policy($source); $source->refresh();
        $request = $this->request($source);
        $request['execution'] = $request['execution_contract']['parameters'];
        $request['candles'] = [];
        $start = CarbonImmutable::parse('2024-12-31T19:20:00Z');
        for ($index = 0; $index <= 200; $index++) $request['candles'][] = [
            'time' => $start->addMinutes(5 * $index)->format('Y-m-d\TH:i:s\Z'),
            'open' => 100.0, 'high' => 101.0, 'low' => 99.0, 'close' => 100.0,
            'market_regime' => 'trend_up', 'market_session' => 'london', 'volatility_regime' => 'normal_volatility',
            'signal_confidence' => .8];
        $request['evaluation_mode'] = 'incremental';
        $request['emit_decision_trace'] = $request['emit_trade_ledger'] = true;
        if ($limits !== null) $request['policy_context'] = ['hard_limits' => $limits];
        $bound = app(InstrumentMethodResearchBindingService::class)->forModel($source, $request);
        $request['policy_context']['instrument_method_learning'] = $bound;
        $script = <<<'PY'
import json,sys,pandas as pd
from app.schemas import SimpleBacktestRequest
from app.services.instrument_method_learning import InstrumentMethodLearning
v=json.load(sys.stdin); request=SimpleBacktestRequest(**v)
frame=pd.DataFrame(v['candles']); frame['time']=pd.to_datetime(frame['time'],utc=True)
runtime=InstrumentMethodLearning(v['policy_context']['instrument_method_learning'],request)
runtime.validate_frame(frame)
event=runtime.evaluate(frame.iloc[199].to_dict(),decision_id='original-meta-transfer-fixture',phase='entry',
    observed_at='2025-01-01T12:00:00Z',decision_at='2025-01-01T12:00:00Z',direction='BUY')
assert event['used'] and event['outputs']['confirmation'] is False
assert event['meta_score_status']=='diagnostic_calibrated_score_available'
assert not event['economic_authority']
print(json.dumps({'event':event,'receipt':runtime.receipt()}))
PY;
        [$exit, $stdout, $stderr] = $this->python($script, $request);
        $this->assertSame(0, $exit, $stderr);
        $native = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        $lab = AiLaboratory::create(['name' => 'meta-transfer-conditional-fixture', 'symbol' => 'XAUUSD', 'timeframe' => 'M5',
            'strategy_families' => ['differential_router']]);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1, 'status' => 'screened']);
        $agent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $source->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'M5', 'strategy_family' => 'differential_router',
            'origin' => 'synthetic-meta-transfer-fixture', 'lifecycle_status' => 'screened', 'parameter_diff' => []]);
        $run = LabEvaluationRun::create(['run_id' => (string) Str::uuid(), 'lab_generation_id' => $generation->id,
            'lab_agent_id' => $agent->id, 'model_version_id' => $source->id, 'phase' => 'screening', 'mode' => 'research',
            'status' => 'started', 'started_at' => now(), 'data_hash' => $request['replay_dataset_hash']]);
        $evidence = app(LabImmutableEvidenceService::class);
        $evidence->attachRequest($run, $request);
        $response = ['decision_trace' => [
            ['event_type' => 'signal_evaluation', 'candle_index' => 200, 'candle_time' => '2025-01-01T12:00:00Z', 'decision' => 'WAIT'],
            ['event_type' => 'instrument_method_decision', 'candle_index' => 200, 'method_learning' => $native['event']]],
            'trades' => [], 'trade_ledger' => [], 'total_trades' => 0, 'trade_ledger_hash' => $evidence->hash([]),
            'data_quality' => ['instrument_method_learning_receipt' => $native['receipt'],
                'decision_trace' => ['protocol' => 'candle_decision_trace_v1', 'requested' => true, 'complete' => true,
                    'input_candle_count' => 201, 'event_count' => 2, 'evaluated_candle_count' => 1, 'covered_candle_count' => 1]]];
        $evidence->finishRun($run, 'completed', $response);
        $learned = app(AgentMethodLearningService::class)->learnFromRun($run->fresh());
        $this->assertSame('learned_diagnostic', $learned['status'], json_encode($learned));
        return [$source->fresh(), $policy, $run->fresh()];
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
