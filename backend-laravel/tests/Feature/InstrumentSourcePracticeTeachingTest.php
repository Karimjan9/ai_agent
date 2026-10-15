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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** New source-specific practice extends instruction, never historical evidence or agent mastery. */
class InstrumentSourcePracticeTeachingTest extends TestCase
{
    use RefreshDatabase;

    private const V2 = 'source_taught_operating_skills_20261015_v2';
    private const FOLDERS = ['closed-ema-slope-pullback', 'closed-atr-percent'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        config()->set('app.key', 'source-practice-teaching-software-fixture-only-key');
    }

    public function test_v2_extends_exact_original_twenty_six_lessons_without_replacing_ninety_primary_exercises(): void
    {
        $owner = app(CuratedInstrumentMethodCatalogService::class);
        $before = $owner->operatingCurriculum(); $bytes = $this->originalParts();
        $new = $owner->operatingCurriculum(self::V2);
        $this->assertSame('operating_skill_curriculum', $new['status'], json_encode($new));
        $this->assertSame(self::V2, $new['curriculum_key']); $this->assertCount(26, $new['lessons']);
        $this->assertCount(36, $new['sources']); $this->assertCount(5, $new['manifest_parts']);
        $this->assertArrayNotHasKey('curator_archive_observations', $before);
        $observations = $new['curator_archive_observations'];
        $this->assertSame('curator_backtest_observation_not_original_agent_experience', $observations['attribution']);
        $this->assertCount(10, $observations['results']); $this->assertCount(8, $observations['control_contrasts']);
        foreach ($observations['control_contrasts'] as $contrast) {
            $this->assertSame($contrast['window'] === 'october_seen' ? -97.44 : 0, $contrast['net_cash_delta']);
            $this->assertFalse($contrast['useful_skill_confirmed']);
        }
        foreach (['own_agent_outcome', 'skill_credit', 'independent_validation', 'mastery_proven', 'economic_authority'] as $flag)
            $this->assertFalse($observations[$flag]);
        $profileCount = $exerciseCount = $oldExerciseCount = 0;
        foreach ($before['lessons'] as $key => $lesson) {
            $this->assertSame($lesson['primary_method_ref'], $new['lessons'][$key]['primary_method_ref']);
            $this->assertSame($lesson['exercises'], $new['lessons'][$key]['exercises']);
            $oldExerciseCount += count($new['lessons'][$key]['exercises']);
            $profiles = $new['lessons'][$key]['practice_profiles'] ?? [];
            $profileCount += count($profiles);
            foreach ($profiles as $profile) {
                $this->assertContains($profile['method_ref'], $new['lessons'][$key]['additional_method_refs']);
                $this->assertNotEmpty($profile['reviewed_rule']); $this->assertNotEmpty($profile['wait_when']);
                $this->assertNotEmpty($profile['why_could_fail']); $this->assertNotEmpty($profile['agent_practice']);
                $exerciseCount += count($profile['exercises']);
                foreach ($profile['source_refs'] as $ref) $this->assertArrayHasKey($ref, $new['sources']);
            }
            if ($profiles === []) $this->assertSame($lesson, $new['lessons'][$key]);
        }
        $this->assertSame(90, $oldExerciseCount); $this->assertSame(2, $profileCount); $this->assertSame(8, $exerciseCount);
        $this->assertSame($before, $owner->operatingCurriculum()); $this->assertSame($bytes, $this->originalParts());
        $this->assertFalse($new['agent_skill_earned']); $this->assertFalse($new['economic_authority']);
        $this->assertSame('paper_only', $new['acceptance']['all_2026_and_later_market_data']);
        $this->assertNoOwnLearningOrAuthority();
    }

    public function test_exact_registered_and_php_compiled_new_recipes_receive_v2_and_execute_only_their_specific_exercises(): void
    {
        $owner = app(CuratedInstrumentMethodCatalogService::class); $library = app(InstrumentMethodLibraryService::class);
        $schema = app(StrategyParameterSchemaService::class); $cases = [];
        foreach (self::FOLDERS as $folder) {
            $recipe = $this->register($folder); $method = $this->input($folder, 'method');
            $pack = $owner->teachingPack([$recipe['recipe_hash']]);
            $this->assertSame('operating_rules_taught', $pack['status'], json_encode($pack));
            $this->assertSame(self::V2, $pack['curriculum_key']);
            $this->assertSame('d2e813760297ed3900c921f16096641746b6112a39785227db7fcfa9e697ad16',
                $pack['curator_archive_observations']['original_summary_sha256']);
            $this->assertTrue($owner->verifyTeachingPack($pack, [$recipe['recipe_hash']]));
            $legacy = $owner->teachingPack([$recipe['recipe_hash']], $owner::OPERATING_CURRICULUM_KEY);
            $this->assertSame('unmapped', $legacy['status']);
            $this->assertSame('OPERATING_RECIPE_NOT_IN_REVIEWED_LESSONS', $legacy['reason']);
            $profile = $pack['lessons'][$method['instrument_key']]['practice_profiles'][0];
            $this->assertSame($method['method_key'].'@'.$method['revision'], $profile['method_ref']);
            $this->assertFalse($pack['agent_skill_earned']); $this->assertFalse($pack['economic_authority']);
            $parameters = array_intersect_key($schema->defaults('differential_router'), $schema->schema('differential_router'));
            $compiled = $library->compileRecipe($recipe['recipe_hash'], ['strategy_family' => 'differential_router', 'parameters' => $parameters],
                ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'pre_2026_only' => true, 'data_hash' => str_repeat('a', 64),
                    'execution_hash' => str_repeat('b', 64), 'context_predicate' => $method['context_predicate']]);
            $this->assertSame('compiled_research_recipe', $compiled['status'], json_encode($compiled));
            $this->assertSame($parameters, $compiled['parameters']);
            $cases[$profile['method_ref']] = ['operator' => $compiled['runtime_recipe']['operators'][0], 'exercises' => $profile['exercises']];
            $changed = $pack; $changed['lessons'][$method['instrument_key']]['practice_profiles'][0]['source_vs_lab'] = 'Publisher guarantees profit';
            $this->assertFalse($owner->verifyTeachingPack($changed, [$recipe['recipe_hash']]));
            $changed = $pack; $changed['curator_archive_observations']['results'][0]['evidence']['net_cash'] = 99999.0;
            $this->assertFalse($owner->verifyTeachingPack($changed, [$recipe['recipe_hash']]));
        }
        $script = <<<'PY'
import json,sys
import pandas as pd
from app.services.instrument_method_features import ClosedInstrumentMethodFeatures
from app.services.research_program_tasks import BoundedDecisionProgram
cases=json.load(sys.stdin);checked=0
for ref,case in cases.items():
    for exercise in case['exercises']:
        programme=BoundedDecisionProgram(case['operator'],allow_method_features=True)
        actual=programme.evaluate(exercise['inputs'],decision_at='2025-12-01T12:00:00Z',observed_at='2025-12-01T11:55:00Z')
        assert type(actual)==type(exercise['expected']) and actual==exercise['expected'], (ref,exercise['name'],actual)
        checked+=1
# Separately exercise actual closed-feature production. The eight operator
# examples above are not substitutes for genuine price-derived availability.
ema_operator=cases['internet_closed_ema_pretrend_pullback_20_50_v1@1']['operator']
for adverse in (False,True):
    closes=[100.+index*.25 for index in range(80)]
    if adverse:
        closes[59]=float(pd.Series(closes[:59]).ewm(span=50,adjust=False).mean().iloc[-1]-1.)
    prefix=pd.Series(closes[:60])
    closes[60]=float((prefix.ewm(span=20,adjust=False).mean().iloc[-1]+prefix.ewm(span=50,adjust=False).mean().iloc[-1])/2.)
    data=pd.DataFrame(dict(time=pd.date_range('2025-01-06T13:00:00Z',periods=80,freq='5min'),
        open=closes,high=[value+.2 for value in closes],low=[value-.2 for value in closes],close=closes))
    row=data.iloc[61].to_dict();stamp=(row['time']+pd.Timedelta(minutes=5)).isoformat()
    inputs=ClosedInstrumentMethodFeatures(data).inputs(row,direction='BUY',observed_at=stamp)
    assert inputs['method_pullback_confirmed'] and inputs['method_pullback_pretrend_available']
    assert BoundedDecisionProgram(ema_operator,allow_method_features=True).evaluate(inputs,decision_at=stamp,observed_at=stamp) is (not adverse)
data=pd.DataFrame(dict(time=pd.date_range('2025-01-06T13:00:00Z',periods=16,freq='5min'),open=2000.,high=2001.,low=1999.,close=2000.))
row=data.iloc[13].to_dict();stamp=(row['time']+pd.Timedelta(minutes=5)).isoformat()
inputs=ClosedInstrumentMethodFeatures(data).inputs(row,direction='BUY',observed_at=stamp)
assert inputs['method_atr_percent_available'] and inputs['method_atr_percent']==.1
assert BoundedDecisionProgram(cases['internet_closed_sma_atr_percent_cap_v1@1']['operator'],allow_method_features=True).evaluate(inputs,decision_at=stamp,observed_at=stamp) is True
print(json.dumps(dict(methods=len(cases),exercises=checked,closed_owner_checks=3,market_qualification=False)))
PY;
        $result = $this->python($script, $cases);
        $this->assertSame(2, $result['methods']); $this->assertSame(8, $result['exercises']);
        $this->assertSame(3, $result['closed_owner_checks']);
        $this->assertFalse($result['market_qualification']); $this->assertNoOwnLearningOrAuthority();
    }

    public function test_old_only_pack_remains_byte_exact_v1_after_new_declarations_and_mixed_roster_is_versioned(): void
    {
        $owner = app(CuratedInstrumentMethodCatalogService::class);
        $installed = $owner->install(['catalog_key' => $owner::EXPANDED_CATALOG_KEY, 'catalog_sha256' => $owner::EXPANDED_CATALOG_SHA256]);
        $this->assertSame('curated_research_catalog_installed', $installed['status'], json_encode($installed));
        $oldRecipe = $installed['recipe_hashes']['internet_closed_regime_alignment_v2_single@1'];
        $before = $owner->teachingPack([$oldRecipe]);
        $originalBytes = json_encode($before, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $newRecipe = $this->register('closed-atr-percent')['recipe_hash'];
        $this->assertSame($originalBytes, json_encode($owner->teachingPack([$oldRecipe]), JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        $this->assertTrue($owner->verifyTeachingPack($before, [$oldRecipe]));
        $mixed = $owner->teachingPack([$oldRecipe, $newRecipe]);
        $this->assertSame('operating_rules_taught', $mixed['status'], json_encode($mixed));
        $this->assertSame(self::V2, $mixed['curriculum_key']); $this->assertCount(2, $mixed['lessons']);
        $this->assertTrue($owner->verifyTeachingPack($mixed, [$oldRecipe, $newRecipe]));
        $this->assertNoOwnLearningOrAuthority();
    }

    public function test_familiar_new_ref_with_substituted_valid_rule_or_source_does_not_obtain_reviewed_teaching(): void
    {
        foreach (['rule', 'source'] as $case) {
            $folder = $case === 'rule' ? 'closed-atr-percent' : 'closed-ema-slope-pullback';
            $library = app(InstrumentMethodLibraryService::class); $source = $this->input($folder, 'source');
            if ($case === 'source') $source['content'] .= ' Caller-authored replacement is not the reviewed source.';
            $sourceResult = $library->registerSource($source); $this->assertSame('registered_reference_only', $sourceResult['status']);
            $method = $this->input($folder, 'method'); $method['source_keys'] = [$sourceResult['source_key']];
            if ($case === 'rule') $method['implementation']['ast']['args'][1]['args'][0]['args'][1]['value'] = .20;
            $registered = $library->registerMethod($method); $this->assertSame('registered_research_only', $registered['status'], json_encode($registered));
            $recipe = $this->input($folder, 'recipe'); $recipe['method_hashes'] = [$registered['method_hash']];
            $recipeResult = $library->registerRecipe($recipe); $this->assertSame('registered_research_only', $recipeResult['status']);
            $this->assertSame('registered_research_only', $library->inspectRecipe($recipeResult['recipe_hash'])['status']);
            $pack = app(CuratedInstrumentMethodCatalogService::class)->teachingPack([$recipeResult['recipe_hash']]);
            $this->assertSame('blocked', $pack['status'], json_encode($pack));
            $this->assertSame('OPERATING_DECLARED_METHOD_DRIFT', $pack['reason']);
            $this->assertFalse($pack['agent_skill_earned']);
        }
        $this->assertNoOwnLearningOrAuthority();
    }

    public function test_real_unused_child_receives_its_own_policy_and_exact_v2_teaching_without_learning_or_rewriting_library(): void
    {
        $recipes = array_map(fn ($folder) => $this->register($folder)['recipe_hash'], self::FOLDERS);
        $libraryBefore = $this->libraryBytes(); $schemas = app(StrategyParameterSchemaService::class);
        $parameters = [...array_intersect_key($schemas->defaults('trend'), $schemas->schema('trend')),
            'ema_fast' => 20, 'ema_slow' => 50, 'entry_topology_variant' => 'source_ema_pretrend_pullback_v1'];
        // Construct the explicit physical entry programme first. The existing
        // typed-only teaching owner must not become a parameter-adapter path.
        $schemas->validate('trend', $parameters);
        $child = ModelVersion::create(['name' => 'source-practice-child-'.Str::uuid(), 'strategy' => 'trend_v1',
            'status' => 'draft', 'parameters' => $parameters,
            'metadata' => ['base_strategy' => 'trend_v1', 'risk_governor' => ['risk_per_trade' => 1.0],
                'trade_management' => ['time_stop_candles' => 2], 'lab_symbol' => 'XAUUSD', 'lab_timeframe' => 'M5']])->fresh();
        $parametersBefore = $child->parameters; $riskBefore = $child->metadata['risk_governor'];
        $managementBefore = $child->metadata['trade_management'];
        $owner = app(AgentMethodGenerationService::class);
        $created = $owner->prepareFreshChild($child, null, ['curated_recipe_hashes' => $recipes, 'generation_id' => 7, 'slot' => 1]);
        $this->assertSame('prospective_child_policy_attached', $created['status'], json_encode($created));
        $this->assertFalse($created['mastery_proven']);
        $policy = app(AgentMethodLearningService::class)->policy($created['policy_hash']);
        $proof = $policy['learning_provenance']; $pack = $proof['teaching_pack'];
        $this->assertSame('model:'.$child->id, $policy['agent_key']);
        $this->assertSame(self::V2, $pack['curriculum_key']); $this->assertCount(2, $pack['methods']); $this->assertCount(2, $pack['lessons']);
        $this->assertSame('eloquent_metadata_json_numbers_v1', $pack['instruction_transport']);
        $this->assertSame(30, $pack['lessons']['high_volatility_firewall']['exercises'][2]['inputs']['_management_atr']);
        $this->assertSame(30.0, app(CuratedInstrumentMethodCatalogService::class)->operatingCurriculum()['lessons']['high_volatility_firewall']['exercises'][2]['inputs']['_management_atr']);
        $changedInstruction = $pack;
        $changedInstruction['lessons']['high_volatility_firewall']['exercises'][2]['inputs']['_management_atr'] = 31;
        $this->assertFalse(app(CuratedInstrumentMethodCatalogService::class)->verifyTeachingPack($changedInstruction, $recipes));
        $this->assertFalse($pack['curator_archive_observations']['own_agent_outcome']);
        $this->assertCount(10, $pack['curator_archive_observations']['results']);
        $expectedRecipes = $recipes; sort($expectedRecipes);
        $this->assertSame($expectedRecipes, $pack['recipe_hashes']);
        $this->assertSame($expectedRecipes, $policy['library_contract']['recipe_hashes']);
        $this->assertTrue(app(CuratedInstrumentMethodCatalogService::class)->verifyTeachingPack($pack, $recipes));
        $this->assertTrue($owner->verifyProvenance($proof, $policy));
        $hashes = app(ResearchPaperEpochContractService::class);
        $this->assertSame($hashes->parameterHash($proof), $hashes->parameterHash($child->fresh()->metadata[AgentMethodGenerationService::METADATA]));
        $this->assertSame($parametersBefore, $child->fresh()->parameters);
        $this->assertSame($riskBefore, $child->fresh()->metadata['risk_governor']);
        $this->assertSame($managementBefore, $child->fresh()->metadata['trade_management']);
        $this->assertSame($libraryBefore, $this->libraryBytes());
        foreach (['agent_skill_earned', 'economic_authority', 'confirmation_proven', 'qualification_granted', 'paper_authority', 'live_authority'] as $flag)
            $this->assertFalse($pack[$flag]);
        $this->assertSame('original_child_policy_exists', $owner->prepareFreshChild($child->fresh(), null, ['curated_recipe_hashes' => $recipes])['status']);
        $this->assertDatabaseCount('model_versions', 1); $this->assertDatabaseCount('agent_method_policy_revisions', 1);
        foreach (['agent_method_experiences', 'agent_method_owned_outcomes', 'agent_knowledge_cards', 'agent_learning_lessons', 'mutation_memories',
            'instrument_method_meta_models', 'lab_evaluation_runs', 'lab_generations', 'research_experiment_work_items', 'lab_evolution_credit_events', 'jobs'] as $table)
            $this->assertDatabaseCount($table, 0);
    }

    public function test_pinned_v2_resource_replacement_is_refused_without_touching_any_original_resource(): void
    {
        $originalRoot = base_path(); $owner = app(CuratedInstrumentMethodCatalogService::class); $bytes = $this->originalParts();
        $files = array_map(fn ($file) => 'research/instrument-operating-skills/'.$file,
            ['price-structure-v1.json', 'risk-execution-v1.json', 'model-v1.json', 'source-practice-v2.json', 'curator-archive-observations-v2.json']);
        foreach (self::FOLDERS as $folder) foreach (['source', 'method'] as $kind) $files[] = 'research/instrument-methods/'.$folder.'/'.$kind.'.json';
        foreach (['source-practice-v2.json', 'curator-archive-observations-v2.json'] as $index => $target) {
            Storage::fake('practice_resource_fixture_'.$index); $disk = Storage::disk('practice_resource_fixture_'.$index);
            foreach ($files as $file) {
                $content = file_get_contents(resource_path($file));
                if ($file === 'research/instrument-operating-skills/'.$target) $content .= "\n ";
                $disk->put('resources/'.$file, $content);
            }
            try {
                $this->app->setBasePath($disk->path(''));
                $result = $owner->operatingCurriculum(self::V2);
                $this->assertSame('blocked', $result['status']);
                $this->assertSame('OPERATING_CURRICULUM_BYTE_DRIFT', $result['reason']);
            } finally { $this->app->setBasePath($originalRoot); }
        }
        $this->assertSame($bytes, $this->originalParts()); $this->assertNoOwnLearningOrAuthority();
    }

    private function register(string $folder): array
    {
        $library = app(InstrumentMethodLibraryService::class);
        $source = $library->registerSource($this->input($folder, 'source'));
        $this->assertSame('registered_reference_only', $source['status'], json_encode($source));
        $this->assertSame([$source['source_key']], $this->input($folder, 'method')['source_keys']);
        $method = $library->registerMethod($this->input($folder, 'method'));
        $this->assertSame('registered_research_only', $method['status'], json_encode($method));
        $this->assertSame([$method['method_hash']], $this->input($folder, 'recipe')['method_hashes']);
        $recipe = $library->registerRecipe($this->input($folder, 'recipe'));
        $this->assertSame('registered_research_only', $recipe['status'], json_encode($recipe));
        return $recipe;
    }

    private function input(string $folder, string $kind): array
    {
        return json_decode(file_get_contents(resource_path('research/instrument-methods/'.$folder.'/'.$kind.'.json')), true, 64, JSON_THROW_ON_ERROR);
    }

    private function originalParts(): array
    {
        return array_map(fn ($file) => hash_file('sha256', resource_path('research/instrument-operating-skills/'.$file)),
            ['price-structure-v1.json', 'risk-execution-v1.json', 'model-v1.json']);
    }

    private function libraryBytes(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(),
            ['instrument_method_sources', 'instrument_method_revisions', 'instrument_recipe_revisions']);
    }

    private function python(string $script, array $input): array
    {
        $process = proc_open([env('PYTHON_BINARY', 'python'), '-c', $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(base_path()).'/ai-service-python',
            ['PYTHONPATH' => dirname(base_path()).'/ai-service-python', 'SYSTEMROOT' => getenv('SYSTEMROOT'), 'PATH' => getenv('PATH')],
            ['bypass_shell' => true, 'create_no_window' => true]);
        $this->assertIsResource($process);
        fwrite($pipes[0], json_encode($input, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)); fclose($pipes[0]);
        stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false); $out = ''; $err = ''; $end = microtime(true) + 30;
        do {
            $out .= stream_get_contents($pipes[1]); $err .= stream_get_contents($pipes[2]); $state = proc_get_status($process);
            if (! $state['running']) break;
            if (microtime(true) > $end) { proc_terminate($process); $err .= ' TEST_30_SECOND_LIMIT'; break; }
            usleep(20000);
        } while (true);
        $out .= stream_get_contents($pipes[1]); $err .= stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
        $this->assertSame(0, $state['exitcode'] >= 0 ? $state['exitcode'] : $exit, $err.$out);
        return json_decode($out, true, 32, JSON_THROW_ON_ERROR);
    }

    private function assertNoOwnLearningOrAuthority(): void
    {
        foreach (['agent_method_policy_revisions', 'agent_method_experiences', 'agent_method_owned_outcomes', 'agent_knowledge_cards',
            'agent_learning_lessons', 'mutation_memories', 'instrument_method_meta_models', 'model_versions', 'lab_evaluation_runs',
            'lab_generations', 'research_experiment_work_items', 'lab_evolution_credit_events', 'jobs'] as $table) $this->assertDatabaseCount($table, 0);
    }
}
