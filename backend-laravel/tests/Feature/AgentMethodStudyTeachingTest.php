<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Services\AgentMethodGenerationService;
use App\Services\AgentMethodLearningService;
use App\Services\AgentMethodStudyPreparationService;
use App\Services\CuratedInstrumentMethodCatalogService;
use App\Services\ExecutionContractService;
use App\Services\InstrumentMethodLibraryService;
use App\Services\InstrumentMethodResearchBindingService;
use App\Services\InstrumentMethodSourceService;
use App\Services\LabImmutableEvidenceService;
use App\Services\ResearchPaperEpochContractService;
use App\Services\StrategyParameterSchemaService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Real bounded operator and immutable-owner software fixtures, never market qualification. */
class AgentMethodStudyTeachingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        config()->set('app.key', 'study-teaching-conditional-software-fixture-key');
        $this->travelTo(CarbonImmutable::parse('2026-10-14T12:00:00Z'));
        Storage::fake('study_teaching_fixture');
        config()->set('services.lab_evidence.disk', 'study_teaching_fixture');
    }

    public function test_all_four_study_carriers_seal_exact_code_owned_teaching_before_registration_without_mutating_sources(): void
    {
        [$child, $input] = $this->studyInput();
        $source = $child->fresh()->toArray();
        $originalMethods = DB::table('instrument_method_revisions')->orderBy('id')->get()->toJson();
        $originalRecipes = DB::table('instrument_recipe_revisions')->orderBy('id')->get()->toJson();
        $result = app(AgentMethodStudyPreparationService::class)->prepare($input);
        $this->assertSame('own_policy_study_registered', $result['status'], json_encode($result));
        $catalog = app(CuratedInstrumentMethodCatalogService::class);
        $library = app(InstrumentMethodLibraryService::class);
        foreach ($result['arms'] as $arm => $identity) {
            $model = ModelVersion::findOrFail($identity['model_id']);
            $policy = app(AgentMethodLearningService::class)->policy($identity['policy_hash']);
            $proof = $policy['learning_provenance'];
            $pack = $proof['teaching_pack'];
            $expectedRecipes = $input['libraries'][substr($arm, 0, 2)];
            $this->assertSame('operating_rules_taught', $pack['status']);
            $this->assertSame($expectedRecipes, $pack['recipe_hashes']);
            $hashes = app(ResearchPaperEpochContractService::class);
            $this->assertSame($hashes->parameterHash($catalog->teachingPack($expectedRecipes)), $hashes->parameterHash($pack));
            $this->assertTrue($catalog->verifyTeachingPack($pack, $expectedRecipes));
            $this->assertSame(['trend_pullback'], array_keys($pack['lessons']));
            $this->assertSame($hashes->parameterHash($proof), $hashes->parameterHash($model->metadata[AgentMethodStudyPreparationService::METADATA]));
            $this->assertSame($child->parameters, $model->parameters);
            foreach ($pack['methods'] as $methodHash => $method) {
                $this->assertSame($library->inspectMethod($methodHash)['contract'], $method);
                foreach ($method['source_keys'] as $sourceKey) {
                    $this->assertSame('registered_reference_only', app(InstrumentMethodSourceService::class)->inspectSource($sourceKey)['status']);
                }
            }
            foreach (['agent_skill_earned', 'economic_authority', 'confirmation_proven', 'qualification_granted', 'paper_authority', 'live_authority'] as $field) {
                $this->assertFalse($pack[$field]);
            }
        }
        $this->assertSame($source, $child->fresh()->toArray());
        $this->assertSame($originalMethods, DB::table('instrument_method_revisions')->orderBy('id')->get()->toJson());
        $this->assertSame($originalRecipes, DB::table('instrument_recipe_revisions')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('research_experiment_work_items', 1);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_reinspection_rejects_altered_teaching_and_keeps_original_legacy_proof_without_pack_compatible(): void
    {
        [$child, $input] = $this->studyInput();
        $owner = app(AgentMethodStudyPreparationService::class);
        $result = $owner->prepare($input);
        $this->assertSame('own_policy_study_registered', $result['status'], json_encode($result));
        $identity = $result['arms']['L2A2'];
        $target = ModelVersion::findOrFail($identity['model_id']);
        $metadata = $target->metadata;
        $proof = $metadata[AgentMethodStudyPreparationService::METADATA];
        try {
            // Conditional issuer fixture: a valid test HMAC is insufficient to
            // replace code-owned instructions or this arm's selected source pool.
            $changed = $proof;
            $changed['teaching_pack']['lessons']['trend_pullback']['source_vs_lab'] = 'Caller replacement';
            $changed = $this->sealFixture($changed);
            $this->setUnboundFixture($target, $metadata, $changed);
            $this->assertFalse($owner->verifyProvenance($changed, $this->policyBody($changed)));
            $changed = $proof;
            $changed['teaching_pack'] = app(CuratedInstrumentMethodCatalogService::class)->teachingPack($input['libraries']['L1']);
            $changed = $this->sealFixture($changed);
            $this->setUnboundFixture($target, $metadata, $changed);
            $this->assertFalse($owner->verifyProvenance($changed, $this->policyBody($changed)));
            $legacy = $proof;
            unset($legacy['teaching_pack']);
            $legacy = $this->sealFixture($legacy);
            $this->setUnboundFixture($target, $metadata, $legacy);
            $this->assertTrue($owner->verifyProvenance($legacy, $this->policyBody($legacy)));
        } finally { $target->update(['metadata' => $metadata]); }
        $this->assertArrayHasKey('policy_hash', app(AgentMethodLearningService::class)->policy($identity['policy_hash']));
        $this->assertSame('original_policy_study_exists', $owner->prepare($input)['status']);
        $this->assertDatabaseCount('research_experiment_work_items', 1);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_missing_or_invalid_mapped_teaching_rolls_back_all_carriers_and_caller_cannot_supply_a_pack(): void
    {
        [$child, $input] = $this->studyInput();
        $catalog = app(CuratedInstrumentMethodCatalogService::class);
        $count = ModelVersion::count();
        $policyCount = DB::table('agent_method_policy_revisions')->count();
        $invalid = $catalog->teachingPack($input['libraries']['L2']);
        $invalid['economic_authority'] = true;
        $originalFirstPack = $catalog->teachingPack($input['libraries']['L1']);
        foreach ([['status' => 'blocked', 'reason' => 'OPERATING_CURRICULUM_BYTE_DRIFT'], $invalid] as $index => $pack) {
            $mock = Mockery::mock($catalog);
            $mock->shouldReceive('teachingPack')->with($input['libraries']['L1'])->andReturn($originalFirstPack);
            $mock->shouldReceive('teachingPack')->with($input['libraries']['L2'])->andReturn($pack);
            $mock->shouldReceive('verifyTeachingPack')->andReturnUsing(fn ($actual, $hashes) => $catalog->verifyTeachingPack($actual, $hashes));
            $this->app->instance(CuratedInstrumentMethodCatalogService::class, $mock);
            $result = app(AgentMethodStudyPreparationService::class)->prepare($input);
            $this->assertSame('blocked', $result['status'], json_encode($result));
            $this->assertSame($index === 0 ? 'METHOD_POLICY_STUDY_TEACHING_PACK_REQUIRED' : 'METHOD_POLICY_STUDY_TEACHING_PACK_INVALID', $result['reason']);
            $this->assertSame($count, ModelVersion::count());
            $this->assertSame($policyCount, DB::table('agent_method_policy_revisions')->count());
            $this->assertDatabaseCount('instrument_method_studies', 0);
            $this->assertDatabaseCount('research_experiment_work_items', 0);
            $this->app->instance(CuratedInstrumentMethodCatalogService::class, $catalog);
        }
        $input['teaching_pack'] = $catalog->teachingPack($input['libraries']['L1']);
        $this->assertSame('METHOD_POLICY_STUDY_EXACT_PREPARATION_REQUIRED', app(AgentMethodStudyPreparationService::class)->prepare($input)['reason']);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    private function studyInput(): array
    {
        $library = app(InstrumentMethodLibraryService::class);
        $recipeHashes = [];
        foreach (['closed-ema-pullback', 'closed-macd-cross'] as $index => $folder) {
            $source = json_decode(File::get(resource_path('research/instrument-methods/'.$folder.'/source.json')), true);
            $this->assertSame('registered_reference_only', $library->registerSource($source)['status']);
            $method = $library->registerMethod(json_decode(File::get(resource_path('research/instrument-methods/'.$folder.'/method.json')), true));
            $this->assertSame('registered_research_only', $method['status']);
            $recipe = $library->registerRecipe(['recipe_key' => 'study-teaching-selected-method', 'revision' => $index + 1,
                'method_hashes' => [$method['method_hash']], 'context_predicate' => $this->context()]);
            $this->assertSame('registered_research_only', $recipe['status']);
            $recipeHashes[] = $recipe['recipe_hash'];
        }
        $execution = app(ExecutionContractService::class)->for('XAUUSD', 'M5');
        [$parent, $run] = $this->originalParent($recipeHashes[0], $execution);
        $this->assertSame('learned_diagnostic', app(AgentMethodLearningService::class)->learnFromRun($run)['status']);
        LabGeneration::query()->update(['status' => 'screened']);
        $child = $this->model($execution);
        $prepared = app(AgentMethodGenerationService::class)->prepareFreshChild($child, $parent);
        $this->assertSame('prospective_child_policy_attached', $prepared['status'], json_encode($prepared));
        $root = storage_path('app/lab-datasets/study-teaching-test-'.Str::uuid());
        File::ensureDirectoryExists($root);
        $start = CarbonImmutable::parse('2025-11-03T07:00:00Z');
        $csv = "time,open,high,low,close\n";
        for ($index = 0; $index < 512; $index++) $csv .= $start->addMinutes($index * 5)->format('Y-m-d\TH:i:s\Z').",2000,2002,1998,2001\n";
        File::put($root.'/m5.csv', $csv);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($root));
        return [$child->fresh(), ['source_model_id' => (int) $child->id, 'libraries' => ['L1' => [$recipeHashes[0]], 'L2' => [$recipeHashes[1]]],
            'source' => ['dataset_path' => $root.'/m5.csv', 'dataset_sha256' => hash('sha256', $csv)],
            'study' => ['data_hash' => hash('sha256', $csv), 'execution_hash' => $execution['execution_hash'],
                'scope' => ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'context' => $this->context(),
                    'evaluated_start' => $start->addMinutes(1000)->format('Y-m-d\TH:i:s\Z'), 'evaluated_end' => $start->addMinutes(2555)->format('Y-m-d\TH:i:s\Z')],
                'evaluated_rows' => 312, 'initial_balance' => 10000.0, 'risk_per_trade' => 1.0, 'execution_costs' => $execution['parameters'],
                'hard_limits' => ['max_total_risk_percent' => 5.0], 'resource_caps' => ['max_rows' => 512, 'max_decision_events' => 4096, 'max_runtime_seconds' => 120], 'minimum_trades' => 10]]];
    }

    private function originalParent(string $recipeHash, array $execution): array
    {
        $parent = $this->model($execution);
        $initial = app(AgentMethodGenerationService::class)->prepareFreshChild($parent, null, ['curated_recipe_hashes' => [$recipeHash]]);
        $this->assertSame('prospective_child_policy_attached', $initial['status'], json_encode($initial));
        $lab = AiLaboratory::create(['name' => 'study teaching software fixture', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_families' => ['breakout']]);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1, 'status' => 'draft']);
        $agent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $parent->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'breakout', 'origin' => 'fixture', 'lifecycle_status' => 'draft', 'parameter_diff' => []]);
        $scope = ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'data_hash' => str_repeat('a', 64), 'execution_hash' => str_repeat('b', 64),
            'evaluated_start' => '2025-01-01T12:00:00Z', 'evaluated_end' => '2025-01-01T12:05:00Z', 'context' => $this->context()];
        $contract = app(InstrumentMethodResearchBindingService::class)->compilePolicy($initial['policy_hash'],
            ['strategy_family' => 'breakout', 'parameters' => $parent->parameters], $scope, 'model:'.$parent->id);
        $script = 'import json,sys,pandas as pd; from app.services.instrument_method_learning import InstrumentMethodLearning; c=json.load(sys.stdin); '
            .'e=InstrumentMethodLearning(c,dataset_hash=c["data_hash"],execution_hash=c["execution_hash"],expected_agent_key=c["expected_agent_key"]); '
            .'f=pd.DataFrame([{"time":pd.Timestamp("2024-12-31T19:20:00Z")+pd.Timedelta(minutes=i*5),"open":100.0,"high":101.0,"low":99.0,"close":100.0} for i in range(201)]); '
            .'e.validate_frame(f); v=e.evaluate({"time":f.iloc[-1]["time"],"open":100.0,"high":101.0,"low":99.0,"close":100.0,"market_regime":"trend_up","market_session":"london","volatility_regime":"normal_volatility"},'
            .'decision_id="study-teaching-input",phase="entry",decision_at="2025-01-01T12:05:00Z",observed_at="2025-01-01T12:05:00Z",direction="BUY"); '
            .'print(json.dumps({"event":v,"receipt":e.receipt()}))';
        $process = new Process(['python', '-B', '-c', $script], dirname(base_path()).'/ai-service-python');
        $process->setTimeout(30); $process->setInput(json_encode($contract)); $process->mustRun();
        $actual = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $request = ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'strategy' => 'breakout_v1', 'parameters' => $parent->parameters,
            'replay_dataset_hash' => str_repeat('a', 64), 'evaluation_mode' => 'screening', 'emit_decision_trace' => true, 'emit_trade_ledger' => true,
            'policy_context' => ['instrument_method_learning' => $contract], 'candles' => array_map(
                fn ($i) => ['time' => CarbonImmutable::parse('2024-12-31T19:20:00Z')->addMinutes($i * 5)->format('Y-m-d\TH:i:s\Z'),
                    'open' => 100.0, 'high' => 101.0, 'low' => 99.0, 'close' => 100.0], range(0, 200))];
        $evidence = app(LabImmutableEvidenceService::class);
        $response = ['decision_trace' => [['event_type' => 'signal_evaluation', 'candle_index' => 200, 'candle_time' => '2025-01-01T12:00:00Z', 'decision' => 'WAIT'],
            ['event_type' => 'instrument_method_decision', 'candle_index' => 200, 'method_learning' => $actual['event']]],
            'trades' => [], 'trade_ledger' => [], 'total_trades' => 0, 'trade_ledger_hash' => $evidence->hash([]),
            'data_quality' => ['instrument_method_learning_receipt' => $actual['receipt'], 'decision_trace' => ['protocol' => 'candle_decision_trace_v1', 'requested' => true,
                'complete' => true, 'input_candle_count' => 201, 'event_count' => 2, 'evaluated_candle_count' => 1, 'covered_candle_count' => 1]]];
        $this->travel(1)->seconds();
        $run = LabEvaluationRun::create(['run_id' => (string) Str::uuid(), 'lab_generation_id' => $generation->id, 'lab_agent_id' => $agent->id,
            'model_version_id' => $parent->id, 'phase' => 'screening', 'mode' => 'research', 'status' => 'started', 'started_at' => now(), 'data_hash' => str_repeat('a', 64)]);
        $evidence->attachRequest($run, $request); $this->travel(1)->seconds(); $evidence->finishRun($run, 'completed', $response);
        $this->assertNotNull($evidence->verifiedModelRuntimeIdentity($run->fresh()));
        return [$parent->fresh(), $run->fresh()];
    }

    private function model(array $execution): ModelVersion
    {
        return ModelVersion::create(['name' => 'study-teaching-'.Str::uuid(), 'strategy' => 'breakout_v1', 'status' => 'draft',
            'parameters' => app(StrategyParameterSchemaService::class)->defaults('breakout'),
            'metadata' => ['base_strategy' => 'breakout_v1', 'risk_governor' => ['risk_per_trade' => 1.0], 'trade_management' => ['time_stop_candles' => 2],
                'lab_symbol' => 'XAUUSD', 'lab_timeframe' => 'H1', 'execution_contract' => $execution]]);
    }

    private function setUnboundFixture(ModelVersion $target, array $metadata, array $proof): void
    {
        unset($metadata[InstrumentMethodResearchBindingService::METADATA]);
        $target->update(['metadata' => [...$metadata, AgentMethodStudyPreparationService::METADATA => $proof]]);
    }

    private function policyBody(array $proof): array
    {
        return ['agent_key' => 'model:'.$proof['target_model_id'], 'revision' => 1, 'library_hash' => $proof['library_hash'],
            'library_contract' => $proof['library_contract'], 'rules' => $proof['rules'], 'default_action' => $proof['default_action'], 'learning_provenance' => $proof];
    }

    private function sealFixture(array $proof): array
    {
        unset($proof['handoff_hash'], $proof['server_seal']);
        $hashes = app(ResearchPaperEpochContractService::class);
        $proof['handoff_hash'] = $hashes->parameterHash($proof);
        $proof['server_seal'] = hash_hmac('sha256', AgentMethodStudyPreparationService::PROTOCOL.':'.$hashes->parameterHash($proof), config('app.key'));
        return $proof;
    }

    private function context(): array { return ['regime' => 'trend_up', 'session' => 'london', 'volatility' => 'normal']; }
}
