<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Services\AgentMethodCandidateService;
use App\Services\AgentMethodLearningService;
use App\Services\InstrumentMethodLibraryService;
use App\Services\InstrumentMethodResearchBindingService;
use App\Services\LabImmutableEvidenceService;
use App\Services\StrategyParameterSchemaService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AgentMethodCandidateTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_own_learning_creates_exact_fresh_model_and_server_owned_policy_without_inherited_authority(): void
    {
        [$source, $sourcePolicy] = $this->learnedSource();
        $before = $source->fresh()->toArray();
        $candidate = app(AgentMethodCandidateService::class)->evolve($sourcePolicy['policy_hash'], 2);
        $this->assertSame('fresh_research_candidate', $candidate['status'], json_encode($candidate));
        $target = ModelVersion::findOrFail($candidate['model_id']);
        $policy = app(AgentMethodLearningService::class)->policy($candidate['policy_hash']);
        $this->assertSame('model:'.$target->id, $policy['agent_key']);
        $this->assertSame(1, $policy['revision']);
        $this->assertSame(AgentMethodCandidateService::REBASE_PROTOCOL, $policy['learning_provenance']['protocol']);
        $this->assertSame($source->parameters, $target->parameters);
        $this->assertSame($source->strategy, $target->strategy);
        $this->assertSame($source->metadata['risk_governor'], $target->metadata['risk_governor']);
        $this->assertSame($sourcePolicy['library_hash'], $policy['library_hash']);
        $this->assertSame('draft', $target->status);
        $this->assertNull($target->promoted_at);
        $this->assertSame(0.0, (float) $target->best_score);
        $this->assertArrayNotHasKey('scoped_certificate', $target->metadata);
        $this->assertArrayNotHasKey('paper_authority', $target->metadata);
        $this->assertSame($before, $source->fresh()->toArray());
        $this->assertTrue($candidate['canonical_admission_required']);
        $this->assertFalse($candidate['queued']);
        $this->assertFalse($candidate['confirmation_proven']);
        $this->assertFalse($candidate['mastery_proven']);
        $this->assertDatabaseCount('lab_agents', 1);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        $this->assertSame(0, LabEvaluationRun::where('model_version_id', $target->id)->count());
        // Fresh data binding retains the intrinsic library and source lineage, while producing a new runtime contract.
        $compiled = app(InstrumentMethodResearchBindingService::class)->compilePolicy($candidate['policy_hash'],
            ['strategy_family' => 'breakout', 'parameters' => $target->parameters], $this->scope(str_repeat('e', 64)), 'model:'.$target->id);
        $this->assertSame($sourcePolicy['library_hash'], $compiled['library_hash']);
        $this->assertSame('model:'.$target->id, $compiled['policy']['agent_key']);
        $this->assertFalse($compiled['economic_authority']);
        $target->update(['metadata' => [...$target->metadata, 'mastery_proven' => true]]);
        $this->assertSame('blocked', app(AgentMethodLearningService::class)->policy($candidate['policy_hash'])['status']);
    }

    public function test_retries_and_changed_revision_labels_do_not_construct_another_candidate_and_poisoned_originals_revoke_reinspection(): void
    {
        [$source, $sourcePolicy] = $this->learnedSource();
        $service = app(AgentMethodCandidateService::class);
        $one = $service->evolve($sourcePolicy['policy_hash'], 2);
        $this->assertSame('fresh_research_candidate', $one['status'], json_encode($one));
        $two = $service->evolve($sourcePolicy['policy_hash'], 2);
        $three = $service->evolve($sourcePolicy['policy_hash'], 3);
        $this->assertSame('original_research_candidate_exists', $two['status']);
        $this->assertSame('original_research_candidate_exists', $three['status']);
        $this->assertSame($one['model_id'], $two['model_id']);
        $this->assertSame($one['model_id'], $three['model_id']);
        $this->assertSame($one['policy_hash'], $three['policy_hash']);
        $this->assertDatabaseCount('model_versions', 2);
        DB::table('agent_method_experiences')->update(['facts' => json_encode(['caller_score' => 999])]);
        $this->assertSame('blocked', app(AgentMethodLearningService::class)->policy($one['policy_hash'])['status']);
        $this->assertSame('blocked', $service->evolve($sourcePolicy['policy_hash'], 4)['status']);
        $this->assertDatabaseCount('model_versions', 2);
    }

    public function test_unlearned_or_changed_runtime_cannot_create_a_candidate_and_public_rebase_claims_are_refused(): void
    {
        [$source, $policy] = $this->learnedSource(false);
        $service = app(AgentMethodCandidateService::class);
        $this->assertSame('blocked', $service->evolve($policy['policy_hash'], 2)['status']);
        $this->assertDatabaseCount('model_versions', 1);
        $source->update(['metadata' => [...$source->metadata, 'specialist_council' => ['protocol' => 'different-owner']]]);
        $this->assertSame('METHOD_CANDIDATE_SOURCE_RUNTIME_REQUIRES_EXISTING_CONSTRUCTOR', $service->evolve($policy['policy_hash'], 2)['reason']);
        $this->assertDatabaseCount('model_versions', 1);
        $forged = ['agent_key' => 'model:999', 'revision' => 1, 'library_hash' => $policy['library_hash'],
            'library_contract' => $policy['library_contract'], 'rules' => [], 'default_action' => 'BASELINE',
            'learning_provenance' => ['protocol' => AgentMethodCandidateService::REBASE_PROTOCOL, 'source_policy_hash' => $policy['policy_hash']]];
        $this->assertSame('POLICY_REBASE_REQUIRES_SERVER_DERIVATION', app(AgentMethodLearningService::class)->registerPolicy($forged)['reason']);
    }

    public function test_copying_a_real_candidate_proof_to_another_model_cannot_create_a_second_target_policy(): void
    {
        [, $policy] = $this->learnedSource();
        $one = app(AgentMethodCandidateService::class)->evolve($policy['policy_hash'], 2);
        $this->assertSame('fresh_research_candidate', $one['status'], json_encode($one));
        $target = ModelVersion::findOrFail($one['model_id']);
        $metadata = $target->metadata;
        unset($metadata[InstrumentMethodResearchBindingService::METADATA]);
        $copy = ModelVersion::create(['name' => 'forged-copy-of-candidate-proof', 'strategy' => $target->strategy,
            'status' => 'draft', 'parameters' => $target->parameters, 'metadata' => $metadata]);
        $result = app(AgentMethodLearningService::class)->registerRebasedPolicy($one['proposal_policy_hash'], $copy->id);
        $this->assertSame('blocked', $result['status']);
        $this->assertSame(0, DB::table('agent_method_policy_revisions')->where('agent_key', 'model:'.$copy->id)->count());
    }

    public function test_post_run_bound_policy_metadata_drift_invalidates_original_identity_and_rebased_policy(): void
    {
        [$source, $policy] = $this->learnedSource();
        $run = LabEvaluationRun::where('model_version_id', $source->id)->firstOrFail();
        $owner = app(LabImmutableEvidenceService::class);
        $this->assertNotNull($owner->verifiedModelRuntimeIdentity($run));
        $candidate = app(AgentMethodCandidateService::class)->evolve($policy['policy_hash'], 2);
        $this->assertSame('fresh_research_candidate', $candidate['status'], json_encode($candidate));
        $metadata = $source->metadata;
        $metadata[InstrumentMethodResearchBindingService::METADATA]['policy_hash'] = str_repeat('f', 64);
        $source->update(['metadata' => $metadata]);
        $this->assertNull($owner->verifiedModelRuntimeIdentity($run->fresh()));
        $this->assertSame('ORIGINAL_MODEL_RUNTIME_IDENTITY_REQUIRED', app(AgentMethodLearningService::class)->learnFromRun($run)['reason']);
        $this->assertSame('blocked', app(AgentMethodLearningService::class)->policy($candidate['policy_hash'])['status']);
        $this->assertDatabaseCount('model_versions', 2);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    private function learnedSource(bool $learn = true): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-13T06:00:00Z'));
        Storage::fake('method_candidate_fixture'); config()->set('services.lab_evidence.disk', 'method_candidate_fixture');
        $library = app(InstrumentMethodLibraryService::class);
        $sourceText = $library->registerSource(['kind' => 'imported_text', 'reference' => 'Synthetic research rule', 'content' => 'Hypothesis: veto the reached entry.',
            'metadata' => ['license' => 'test fixture owned', 'access' => 'user_supplied', 'exposure' => ['status' => 'known_synthetic_fixture']]]);
        $method = $library->registerMethod(['instrument_key' => 'trend_pullback', 'method_key' => 'candidate-test-veto', 'revision' => 1,
            'context_predicate' => $this->context(), 'horizon' => 'hour', 'known_limitations' => ['Synthetic conditional veto, no profit claim'],
            'source_keys' => [$sourceText['source_key']], 'implementation' => ['kind' => 'typed_ast', 'target' => 'confirmation',
                'ast' => ['op' => 'CONST', 'type' => 'bool', 'value' => false], 'input_bindings' => [],
                'budget' => ['cpu_seconds' => .5, 'max_calls' => 100, 'max_node_evaluations' => 4800]]]);
        $recipe = $library->registerRecipe(['recipe_key' => 'candidate-test', 'revision' => 1,
            'method_hashes' => [$method['method_hash']], 'context_predicate' => $this->context()]);
        $source = ModelVersion::create(['name' => 'source-method-candidate', 'strategy' => 'breakout_v1', 'version' => 'original', 'status' => 'draft',
            'parameters' => app(StrategyParameterSchemaService::class)->defaults('breakout'), 'metadata' => ['base_strategy' => 'breakout_v1',
                'risk_governor' => ['risk_per_trade' => 1.0], 'trade_management' => ['time_stop_candles' => 2],
                'lab_symbol' => 'XAUUSD', 'lab_timeframe' => 'H1', 'scoped_certificate' => ['confirmed' => true], 'paper_authority' => true]]);
        $policy = app(AgentMethodLearningService::class)->registerPolicy(['agent_key' => 'model:'.$source->id, 'revision' => 1,
            'recipe_hashes' => [$recipe['recipe_hash']], 'rules' => [['context_predicate' => $this->context(), 'preferred_recipes' => [$recipe['recipe_hash']]]], 'default_action' => 'BASELINE']);
        $this->assertSame('registered_research_only', $policy['status'], json_encode($policy));
        app(InstrumentMethodResearchBindingService::class)->attachToUnusedModel($source->id, $policy['policy_hash']);
        $source->refresh();
        if (! $learn) return [$source, $policy];
        $lab = AiLaboratory::create(['name' => 'candidate fixture', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_families' => ['breakout']]);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1, 'status' => 'draft']);
        $agent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $source->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'breakout', 'origin' => 'fixture', 'lifecycle_status' => 'draft', 'parameter_diff' => []]);
        $contract = app(InstrumentMethodResearchBindingService::class)->compilePolicy($policy['policy_hash'],
            ['strategy_family' => 'breakout', 'parameters' => $source->parameters], $this->scope(str_repeat('a', 64)), 'model:'.$source->id);
        $script = 'import json,sys; from app.services.instrument_method_learning import InstrumentMethodLearning; c=json.load(sys.stdin); e=InstrumentMethodLearning(c,dataset_hash=c["data_hash"],execution_hash=c["execution_hash"],expected_agent_key=c["expected_agent_key"]); event=e.evaluate({"close":100.0,"market_regime":"trend_up","market_session":"london","volatility_regime":"normal_volatility"},decision_id="original-candidate-input",phase="entry",decision_at="2025-01-01T12:05:00Z",observed_at="2025-01-01T12:00:00Z",direction="BUY"); print(json.dumps({"event":event,"receipt":e.receipt()}))';
        $process = new Process(['python', '-c', $script], dirname(base_path()).'/ai-service-python');
        $process->setTimeout(20); $process->setInput(json_encode($contract)); $process->mustRun();
        $native = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $request = ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'strategy' => 'breakout_v1', 'parameters' => $source->parameters,
            'replay_dataset_hash' => str_repeat('a', 64), 'evaluation_mode' => 'screening', 'emit_decision_trace' => true, 'emit_trade_ledger' => true,
            'policy_context' => ['instrument_method_learning' => $contract], 'candles' => array_map(
                fn ($index) => ['time' => CarbonImmutable::parse('2024-12-31T19:20:00Z')->addMinutes($index * 5)->format('Y-m-d\TH:i:s\Z')], range(0, 200))];
        $response = ['decision_trace' => [['event_type' => 'signal_evaluation', 'candle_index' => 200, 'candle_time' => '2025-01-01T12:00:00Z', 'decision' => 'WAIT'],
                ['event_type' => 'instrument_method_decision', 'candle_index' => 200, 'method_learning' => $native['event']]],
            'trades' => [], 'trade_ledger' => [], 'total_trades' => 0, 'trade_ledger_hash' => app(LabImmutableEvidenceService::class)->hash([]),
            'data_quality' => ['instrument_method_learning_receipt' => $native['receipt'],
                'decision_trace' => ['protocol' => 'candle_decision_trace_v1', 'requested' => true, 'complete' => true,
                    'input_candle_count' => 201, 'event_count' => 2, 'evaluated_candle_count' => 1, 'covered_candle_count' => 1]]];
        $this->travel(1)->seconds();
        $run = LabEvaluationRun::create(['run_id' => (string) Str::uuid(), 'lab_generation_id' => $generation->id,
            'lab_agent_id' => $agent->id, 'model_version_id' => $source->id, 'phase' => 'screening', 'mode' => 'research',
            'status' => 'started', 'started_at' => now(), 'data_hash' => str_repeat('a', 64)]);
        $evidence = app(LabImmutableEvidenceService::class);
        $evidence->attachRequest($run, $request);
        $this->travel(1)->seconds();
        $evidence->finishRun($run, 'completed', $response);
        $this->assertNotNull($evidence->verifiedModelRuntimeIdentity($run->fresh()));
        $this->assertTrue($evidence->learningEligibility($run->fresh())['complete'], json_encode($evidence->learningEligibility($run->fresh())));
        $learned = app(AgentMethodLearningService::class)->learnFromRun($run);
        $this->assertSame('learned_diagnostic', $learned['status'], json_encode($learned));
        return [$source->fresh(), $policy];
    }

    private function context(): array { return ['regime' => 'trend_up', 'session' => 'london', 'volatility' => 'normal']; }
    private function scope(string $data): array { return ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'data_hash' => $data,
        'execution_hash' => str_repeat('b', 64), 'evaluated_start' => '2025-01-01T12:00:00Z', 'evaluated_end' => '2025-01-01T12:05:00Z', 'context' => $this->context()]; }
}
