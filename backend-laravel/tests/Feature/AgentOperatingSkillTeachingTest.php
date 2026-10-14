<?php

namespace Tests\Feature;

use App\Models\ModelVersion;
use App\Services\AgentMethodGenerationService;
use App\Services\AgentMethodLearningService;
use App\Services\CuratedInstrumentMethodCatalogService;
use App\Services\InstrumentMethodLibraryService;
use App\Services\ResearchPaperEpochContractService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/** Instruction/identity fixtures only: no market result, original replay or earned skill. */
class AgentOperatingSkillTeachingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        config()->set('app.key', 'operating-teaching-software-fixture-only-key');
    }

    public function test_new_unused_child_receives_exact_code_owned_teaching_before_its_own_policy_seal(): void
    {
        $recipe = $this->mappedRecipe();
        $catalog = app(CuratedInstrumentMethodCatalogService::class);
        $beforeLibrary = $this->libraryBytes();
        $child = $this->model();
        $beforeParameters = $child->parameters;
        $beforeRisk = $child->metadata['risk_governor'];
        $result = app(AgentMethodGenerationService::class)->prepareFreshChild($child, null,
            ['curated_recipe_hashes' => [$recipe], 'generation_id' => 7, 'slot' => 1]);

        $this->assertSame('prospective_child_policy_attached', $result['status'], json_encode($result));
        $policy = app(AgentMethodLearningService::class)->policy($result['policy_hash']);
        $proof = $policy['learning_provenance'];
        $pack = $proof['teaching_pack'];
        $this->assertSame('operating_rules_taught', $pack['status']);
        $this->assertSame([$recipe], $pack['recipe_hashes']);
        $this->assertSame([$recipe], $policy['library_contract']['recipe_hashes']);
        $this->assertSame('internet_closed_regime_alignment_v2@1', $pack['lessons']['regime_router']['primary_method_ref']);
        $hashes = app(ResearchPaperEpochContractService::class);
        $this->assertSame($hashes->parameterHash($catalog->teachingPack([$recipe])), $hashes->parameterHash($pack));
        $this->assertTrue($catalog->verifyTeachingPack($pack, [$recipe]));
        $this->assertTrue(app(AgentMethodGenerationService::class)->verifyProvenance($proof, $policy));
        $this->assertSame($hashes->parameterHash($proof), $hashes->parameterHash(
            $child->fresh()->metadata[AgentMethodGenerationService::METADATA]));
        foreach (['agent_skill_earned', 'economic_authority', 'confirmation_proven', 'qualification_granted',
            'paper_authority', 'live_authority'] as $flag) $this->assertFalse($pack[$flag]);
        $this->assertFalse($result['mastery_proven']);
        $this->assertSame($beforeParameters, $child->fresh()->parameters);
        $this->assertSame($beforeRisk, $child->fresh()->metadata['risk_governor']);
        $this->assertSame($beforeLibrary, $this->libraryBytes());
        foreach (['agent_method_experiences', 'agent_method_owned_outcomes', 'instrument_method_meta_models',
            'lab_evaluation_runs', 'research_experiment_work_items', 'lab_evolution_credit_events', 'jobs'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_teaching_or_recipe_tampering_refuses_reinspection_without_granting_new_authority(): void
    {
        $recipe = $this->mappedRecipe();
        $child = $this->model();
        $owner = app(AgentMethodGenerationService::class);
        $result = $owner->prepareFreshChild($child, null, ['curated_recipe_hashes' => [$recipe]]);
        $policy = app(AgentMethodLearningService::class)->policy($result['policy_hash']);
        $proof = $policy['learning_provenance'];
        $changed = $proof['teaching_pack'];
        $changed['lessons']['regime_router']['definition'] = 'Caller-authored replacement';
        $this->assertFalse(app(CuratedInstrumentMethodCatalogService::class)->verifyTeachingPack($changed, [$recipe]));
        $this->assertFalse(app(CuratedInstrumentMethodCatalogService::class)->verifyTeachingPack(
            $proof['teaching_pack'], [$this->customRecipe()]));

        $proof['teaching_pack'] = $changed;
        $child->update(['metadata' => [...$child->metadata, AgentMethodGenerationService::METADATA => $proof]]);
        $this->assertFalse($owner->verifyProvenance($proof, $policy));
        $this->assertSame('blocked', app(AgentMethodLearningService::class)->policy($result['policy_hash'])['status']);
        $this->assertDatabaseCount('agent_method_policy_revisions', 1);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_meta_workflow_instruction_does_not_create_or_attach_trained_weights(): void
    {
        $library = app(InstrumentMethodLibraryService::class);
        foreach (['source', 'method', 'recipe'] as $action) {
            $input = json_decode(file_get_contents(resource_path('research/instrument-methods/calibrated-meta-score/'.$action.'.json')),
                true, 64, JSON_THROW_ON_ERROR);
            $result = match ($action) {
                'source' => $library->registerSource($input),
                'method' => $library->registerMethod($input),
                'recipe' => $library->registerRecipe($input),
            };
            $this->assertSame($action === 'source' ? 'registered_reference_only' : 'registered_research_only', $result['status']);
        }
        $recipe = $result['recipe_hash'];
        $child = $this->model();
        $prepared = app(AgentMethodGenerationService::class)->prepareFreshChild($child, null,
            ['curated_recipe_hashes' => [$recipe]]);
        $this->assertSame('prospective_child_policy_attached', $prepared['status'], json_encode($prepared));
        $pack = $child->fresh()->metadata[AgentMethodGenerationService::METADATA]['teaching_pack'];
        $this->assertSame('internet_calibrated_meta_score@1', $pack['lessons']['meta_label_filter']['primary_method_ref']);
        $this->assertSame('rules_supplied_not_agent_mastery', $pack['acceptance']);
        $this->assertArrayNotHasKey('instrument_method_meta_model', $child->fresh()->metadata);
        $this->assertDatabaseCount('instrument_method_meta_models', 0);
        $this->assertDatabaseCount('instrument_method_meta_registrations', 0);
        $this->assertDatabaseCount('agent_method_owned_outcomes', 0);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_same_genuine_source_cannot_substitute_a_familiar_method_implementation(): void
    {
        $library = app(InstrumentMethodLibraryService::class);
        $folder = resource_path('research/instrument-methods/calibrated-meta-score/');
        $source = json_decode(file_get_contents($folder.'source.json'), true, 64, JSON_THROW_ON_ERROR);
        $registeredSource = $library->registerSource($source);
        $this->assertSame('registered_reference_only', $registeredSource['status']);
        $declared = json_decode(file_get_contents($folder.'method.json'), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame([$registeredSource['source_key']], $declared['source_keys']);

        // Register before the legitimate revision exists: the registry sees a
        // valid source and internally valid bounded operator, not a broken
        // receipt or same-key overwrite. Only the reviewed declaration fence
        // can distinguish this substituted threshold from the taught rule.
        $substituted = $declared;
        $substituted['implementation']['ast']['args'][1]['args'][1]['value'] = .10;
        $method = $library->registerMethod($substituted);
        $this->assertSame('registered_research_only', $method['status'], json_encode($method));
        $this->assertSame($declared['source_keys'], $method['contract']['source_keys']);
        $this->assertNotSame('e4c355b43ee008d7373fb427dec5ce70b7efd951663a387501ba06513127628b', $method['method_hash']);
        $this->assertSame('registered_research_only', $library->inspectMethod($method['method_hash'])['status']);
        $recipeInput = json_decode(file_get_contents($folder.'recipe.json'), true, 64, JSON_THROW_ON_ERROR);
        $recipeInput['method_hashes'] = [$method['method_hash']];
        $recipe = $library->registerRecipe($recipeInput);
        $this->assertSame('registered_research_only', $recipe['status']);
        $this->assertSame('registered_research_only', $library->inspectRecipe($recipe['recipe_hash'])['status']);

        $teaching = app(CuratedInstrumentMethodCatalogService::class)->teachingPack([$recipe['recipe_hash']]);
        $this->assertSame('blocked', $teaching['status']);
        $this->assertSame('OPERATING_DECLARED_METHOD_DRIFT', $teaching['reason']);
        $this->assertFalse($teaching['agent_skill_earned']);
        $child = $this->model(); $before = $child->toArray();
        $result = app(AgentMethodGenerationService::class)->prepareFreshChild($child, null,
            ['curated_recipe_hashes' => [$recipe['recipe_hash']]]);
        $this->assertSame('METHOD_GENERATION_TEACHING_PACK_REQUIRED', $result['reason']);
        $this->assertSame($before, $child->fresh()->toArray());
        $this->assertDatabaseCount('agent_method_policy_revisions', 0);
        $this->assertDatabaseCount('instrument_method_meta_models', 0);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_caller_cannot_supply_instruction_pack_or_change_an_existing_child(): void
    {
        $recipe = $this->mappedRecipe();
        $child = $this->model();
        $before = $child->toArray();
        $result = app(AgentMethodGenerationService::class)->prepareFreshChild($child, null,
            ['curated_recipe_hashes' => [$recipe], 'teaching_pack' => app(CuratedInstrumentMethodCatalogService::class)->teachingPack([$recipe])]);
        $this->assertSame('METHOD_GENERATION_CODE_OWNED_TEACHING_PACK_REQUIRED', $result['reason']);
        $this->assertSame($before, $child->fresh()->toArray());
        $this->assertDatabaseCount('agent_method_policy_revisions', 0);

        $child->update(['promoted_at' => now()]);
        $result = app(AgentMethodGenerationService::class)->prepareFreshChild($child->fresh(), null,
            ['curated_recipe_hashes' => [$recipe]]);
        $this->assertSame('METHOD_GENERATION_UNUSED_CONSTRUCTOR_CHILD_REQUIRED', $result['reason']);
        $this->assertArrayNotHasKey(AgentMethodGenerationService::METADATA, $child->fresh()->metadata);
    }

    public function test_even_a_fixture_resealed_handoff_cannot_change_the_pinned_instruction_contract(): void
    {
        $recipe = $this->mappedRecipe();
        $child = $this->model();
        $owner = app(AgentMethodGenerationService::class);
        $result = $owner->prepareFreshChild($child, null, ['curated_recipe_hashes' => [$recipe]]);
        $policy = app(AgentMethodLearningService::class)->policy($result['policy_hash']);
        $proof = $policy['learning_provenance'];
        $proof['teaching_pack']['agent_skill_earned'] = true;
        $hashes = app(ResearchPaperEpochContractService::class);
        // Deliberately emulate an authenticated but semantically corrupt test
        // issuer. The original pinned teaching owner must still refuse it.
        $proof['handoff_hash'] = $hashes->parameterHash(array_diff_key($proof, ['handoff_hash' => true, 'server_seal' => true]));
        $proof['server_seal'] = hash_hmac('sha256', AgentMethodGenerationService::PROTOCOL.':'.$hashes->parameterHash(
            array_diff_key($proof, ['server_seal' => true])), config('app.key'));
        $child->update(['metadata' => [...$child->metadata, AgentMethodGenerationService::METADATA => $proof]]);
        $this->assertFalse($owner->verifyProvenance($proof, $policy));
        $this->assertDatabaseCount('agent_method_policy_revisions', 1);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_unmapped_custom_and_pack_absent_original_policies_remain_immutable_and_valid(): void
    {
        $custom = $this->customRecipe();
        $owner = app(AgentMethodGenerationService::class);
        $legacy = $this->model();
        $created = $owner->prepareFreshChild($legacy, null, ['curated_recipe_hashes' => [$custom]]);
        $this->assertSame('prospective_child_policy_attached', $created['status'], json_encode($created));
        $oldPolicy = app(AgentMethodLearningService::class)->policy($created['policy_hash']);
        $this->assertArrayNotHasKey('teaching_pack', $oldPolicy['learning_provenance']);
        $oldRow = DB::table('agent_method_policy_revisions')->where('policy_hash', $created['policy_hash'])->first();
        $oldModel = $legacy->fresh()->toArray();
        $mapped = $this->mappedRecipe();
        config()->set('services.instrument_methods.generation_recipe_hashes', [$mapped]);
        $newChild = $this->model();
        $new = $owner->prepareFreshChild($newChild, null);
        $this->assertSame('prospective_child_policy_attached', $new['status'], json_encode($new));
        $this->assertArrayHasKey('teaching_pack', $newChild->fresh()->metadata[AgentMethodGenerationService::METADATA]);
        config()->set('services.instrument_methods.generation_recipe_hashes', []);
        $this->assertSame($oldPolicy, app(AgentMethodLearningService::class)->policy($created['policy_hash']));
        $this->assertEquals($oldRow, DB::table('agent_method_policy_revisions')->where('policy_hash', $created['policy_hash'])->first());
        $this->assertSame($oldModel, $legacy->fresh()->toArray());
        $this->assertTrue($owner->verifyProvenance($oldPolicy['learning_provenance'], $oldPolicy));
        $this->assertSame('original_child_policy_exists', $owner->prepareFreshChild($legacy->fresh(), null)['status']);
        $this->assertDatabaseCount('agent_method_policy_revisions', 2);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_blocked_or_invalid_mapped_instruction_rolls_back_instead_of_silently_downgrading(): void
    {
        $recipe = $this->mappedRecipe();
        foreach (['blocked', 'invalid_taught'] as $case) {
            $catalog = Mockery::mock(CuratedInstrumentMethodCatalogService::class)->makePartial();
            if ($case === 'blocked') {
                $catalog->shouldReceive('teachingPack')->once()->with([$recipe])->andReturn([
                    'status' => 'blocked', 'reason' => 'OPERATING_CURRICULUM_BYTE_DRIFT']);
            } else {
                $catalog->shouldReceive('teachingPack')->once()->with([$recipe])->andReturn(['status' => 'operating_rules_taught']);
                $catalog->shouldReceive('verifyTeachingPack')->once()->andReturn(false);
            }
            $this->app->instance(CuratedInstrumentMethodCatalogService::class, $catalog);
            $child = $this->model(); $before = $child->toArray();
            $result = app(AgentMethodGenerationService::class)->prepareFreshChild($child, null,
                ['curated_recipe_hashes' => [$recipe]]);
            $expected = $case === 'blocked' ? 'METHOD_GENERATION_TEACHING_PACK_REQUIRED' : 'METHOD_GENERATION_TEACHING_PACK_INVALID';
            $this->assertSame($expected, $result['reason']);
            $this->assertSame($before, $child->fresh()->toArray());
            $this->assertDatabaseCount('agent_method_policy_revisions', 0);
        }
    }

    private function mappedRecipe(): string
    {
        $installed = app(CuratedInstrumentMethodCatalogService::class)->install([
            'catalog_key' => CuratedInstrumentMethodCatalogService::EXPANDED_CATALOG_KEY,
            'catalog_sha256' => CuratedInstrumentMethodCatalogService::EXPANDED_CATALOG_SHA256]);
        $this->assertSame('curated_research_catalog_installed', $installed['status'], json_encode($installed));
        return $installed['recipe_hashes']['internet_closed_regime_alignment_v2_single@1'];
    }

    private function model(): ModelVersion
    {
        $schemas = app(StrategyParameterSchemaService::class);
        return ModelVersion::create(['name' => 'operating-teaching-'.Str::uuid(), 'strategy' => 'breakout_v1',
            'status' => 'draft', 'parameters' => array_intersect_key($schemas->defaults('breakout'), $schemas->schema('breakout')),
            'metadata' => ['base_strategy' => 'breakout_v1', 'risk_governor' => ['risk_per_trade' => 1.0],
                'trade_management' => ['time_stop_candles' => 2], 'lab_symbol' => 'XAUUSD', 'lab_timeframe' => 'H1']])->fresh();
    }

    private function customRecipe(): string
    {
        $library = app(InstrumentMethodLibraryService::class);
        $source = $library->registerSource(['kind' => 'imported_text', 'reference' => 'Conditional software fixture',
            'content' => 'Finite custom operator; not a curated operating lesson.',
            'metadata' => ['license' => 'test fixture owned', 'access' => 'user_supplied',
                'exposure' => ['status' => 'synthetic_fixture']]]);
        $method = $library->registerMethod(['instrument_key' => 'regime_router', 'method_key' => 'unmapped-teaching-fixture',
            'revision' => 1, 'context_predicate' => ['regime' => 'trend_up', 'session' => 'london', 'volatility' => 'normal'],
            'horizon' => 'hour', 'known_limitations' => ['Conditional fixture only.'], 'source_keys' => [$source['source_key']],
            'implementation' => ['kind' => 'typed_ast', 'target' => 'confirmation',
                'ast' => ['op' => 'CONST', 'type' => 'bool', 'value' => false], 'input_bindings' => [],
                'budget' => ['cpu_seconds' => .5, 'max_calls' => 100, 'max_node_evaluations' => 4800]]]);
        $this->assertSame('registered_research_only', $method['status']);
        $recipe = $library->registerRecipe(['recipe_key' => 'unmapped-teaching-fixture', 'revision' => 1,
            'method_hashes' => [$method['method_hash']],
            'context_predicate' => ['regime' => 'trend_up', 'session' => 'london', 'volatility' => 'normal']]);
        $this->assertSame('registered_research_only', $recipe['status']);
        return $recipe['recipe_hash'];
    }

    private function libraryBytes(): array
    {
        return array_map(fn (string $table): string => DB::table($table)->orderBy('id')->get()->toJson(),
            ['instrument_method_sources', 'instrument_method_revisions', 'instrument_recipe_revisions']);
    }
}
