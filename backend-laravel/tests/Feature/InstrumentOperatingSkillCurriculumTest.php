<?php

namespace Tests\Feature;

use App\Services\CuratedInstrumentMethodCatalogService;
use App\Services\InstrumentMethodLibraryService;
use App\Services\InstrumentMethodSourceService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class InstrumentOperatingSkillCurriculumTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_instruments_receive_instructions_without_mutating_an_agent_or_claiming_profit(): void
    {
        $owner = app(CuratedInstrumentMethodCatalogService::class);
        $curriculum = $owner->operatingCurriculum();
        $this->assertSame('operating_skill_curriculum', $curriculum['status'], json_encode($curriculum));
        $this->assertCount(26, $curriculum['lessons']);
        $this->assertCount(5, $curriculum['groups']);
        $this->assertCount(13, $curriculum['playbooks']);
        $this->assertFalse($curriculum['acceptance']['base_profit_required']);
        $this->assertSame('paper_only', $curriculum['acceptance']['all_2026_and_later_market_data']);
        $this->assertFalse($curriculum['agent_skill_earned']);
        foreach ($curriculum['lessons'] as $lesson) {
            $this->assertGreaterThanOrEqual(3, count($lesson['operating_steps']));
            $this->assertNotEmpty($lesson['wait_when']);
            $this->assertNotEmpty($lesson['common_mistakes']);
            $this->assertNotEmpty($lesson['agent_practice']);
            $this->assertNotEmpty($lesson['source_vs_lab']);
            foreach ($lesson['source_refs'] as $id) $this->assertArrayHasKey($id, $curriculum['sources']);
        }
        $this->assertSame($curriculum, $owner->operatingCurriculum());
        $this->assertSame(0, Artisan::call('trading:instrument-methods', ['action' => 'curriculum', '--json' => true]));
        $this->assertSame($curriculum['curriculum_hash'], json_decode(Artisan::output(), true)['curriculum_hash']);
        foreach (['instrument_method_sources', 'instrument_method_revisions', 'instrument_recipe_revisions',
            'agent_method_policy_revisions', 'agent_method_experiences', 'model_versions', 'jobs', 'lab_evolution_credit_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_each_primary_teaches_exact_original_program_and_all_exercises_execute_php_compiled_operators(): void
    {
        $this->install();
        $owner = app(CuratedInstrumentMethodCatalogService::class);
        $library = app(InstrumentMethodLibraryService::class);
        $schema = app(StrategyParameterSchemaService::class);
        $curriculum = $owner->operatingCurriculum();
        $cases = [];
        foreach ($curriculum['lessons'] as $instrument => $lesson) {
            [$methodKey, $revision] = explode('@', $lesson['primary_method_ref']);
            $method = DB::table('instrument_method_revisions')->where('method_key', $methodKey)->where('revision', (int) $revision)->first();
            $this->assertNotNull($method, $lesson['primary_method_ref']);
            $recipe = $library->registerRecipe(['recipe_key' => 'operating_test_'.$instrument, 'revision' => 1,
                'method_hashes' => [$method->method_hash], 'context_predicate' => json_decode($method->contract, true)['context_predicate']]);
            $this->assertSame('registered_research_only', $recipe['status'], json_encode($recipe));
            $pack = $owner->teachingPack([$recipe['recipe_hash']]);
            $this->assertSame('operating_rules_taught', $pack['status'], $instrument.': '.json_encode($pack));
            $this->assertSame([$instrument], array_keys($pack['lessons']));
            $this->assertTrue($owner->verifyTeachingPack($pack, [$recipe['recipe_hash']]));
            $this->assertFalse($pack['agent_skill_earned']);
            $this->assertFalse($pack['economic_authority']);
            $parameters = array_intersect_key($schema->defaults('differential_router'), $schema->schema('differential_router'));
            $compiled = $library->compileRecipe($recipe['recipe_hash'], ['strategy_family' => 'differential_router', 'parameters' => $parameters],
                ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'pre_2026_only' => true, 'data_hash' => str_repeat('a', 64),
                    'execution_hash' => str_repeat('b', 64), 'context_predicate' => $recipe['contract']['context_predicate']]);
            $this->assertSame('compiled_research_recipe', $compiled['status'], json_encode($compiled));
            $this->assertSame($parameters, $compiled['parameters']);
            $this->assertCount(1, $compiled['runtime_recipe']['operators']);
            $cases[$instrument] = ['operator' => $compiled['runtime_recipe']['operators'][0], 'exercises' => $lesson['exercises']];
        }
        $script = <<<'PY'
import json,sys
from app.services.research_program_tasks import BoundedDecisionProgram
cases=json.load(sys.stdin)
checked=0
for key,case in cases.items():
    for exercise in case['exercises']:
        program=BoundedDecisionProgram(case['operator'],allow_method_features=True)
        actual=program.evaluate(exercise['inputs'],decision_at='2025-12-01T12:00:00Z',observed_at='2025-12-01T11:55:00Z')
        assert type(actual)==type(exercise['expected']), (key,exercise['name'],actual,exercise['expected'])
        assert actual==exercise['expected'], (key,exercise['name'],actual,exercise['expected'])
        checked+=1
print(json.dumps(dict(instruments=len(cases),exercises=checked,synthetic_correctness=True,market_qualification=False)))
PY;
        $process = new Process([env('PYTHON_BINARY', 'python'), '-c', $script], dirname(base_path()).'/ai-service-python');
        $process->setInput(json_encode($cases, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        $process->setTimeout(30);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        $result = json_decode($process->getOutput(), true);
        $this->assertSame(26, $result['instruments']);
        $this->assertGreaterThanOrEqual(52, $result['exercises']);
        $this->assertFalse($result['market_qualification']);
        foreach (['agent_method_policy_revisions', 'agent_method_experiences', 'model_versions', 'jobs', 'lab_evolution_credit_events'] as $table) $this->assertDatabaseCount($table, 0);
    }

    public function test_teaching_reopens_originals_and_refuses_tampering_and_unsupported_recipe(): void
    {
        $installed = $this->install();
        $owner = app(CuratedInstrumentMethodCatalogService::class);
        $hash = $installed['typed_recipe_hashes'][0];
        $pack = $owner->teachingPack([$hash]);
        $this->assertSame('operating_rules_taught', $pack['status'], json_encode($pack));
        $changed = $pack;
        $changed['agent_skill_earned'] = true;
        $this->assertFalse($owner->verifyTeachingPack($changed, [$hash]));
        $changed = $pack;
        $key = array_key_first($changed['lessons']);
        $changed['lessons'][$key]['source_vs_lab'] = 'source guarantees profit';
        $this->assertFalse($owner->verifyTeachingPack($changed, [$hash]));
        $this->assertSame('blocked', $owner->teachingPack([$hash, $hash])['status']);
        $methodHash = array_key_first($pack['methods']);
        $body = $pack['methods'][$methodHash];
        $body['implementation']['budget']['max_calls'] += 1;
        DB::table('instrument_method_revisions')->where('method_hash', $methodHash)->update(['contract' => json_encode($body)]);
        $this->assertSame('blocked', $owner->teachingPack([$hash])['status']);
        $this->assertFalse($owner->verifyTeachingPack($pack, [$hash]));
        $this->assertSame('blocked', $owner->teachingPack([str_repeat('a', 64)])['status']);
    }

    private function install(): array
    {
        $owner = app(CuratedInstrumentMethodCatalogService::class);
        $installed = $owner->install(['catalog_key' => $owner::EXPANDED_CATALOG_KEY, 'catalog_sha256' => $owner::EXPANDED_CATALOG_SHA256]);
        $this->assertSame('curated_research_catalog_installed', $installed['status'], json_encode($installed));
        foreach (['observed-quote-firewall', 'closed-band-reentry', 'closed-ema-pullback', 'closed-donchian-retest',
            'calibrated-meta-score', 'closed-macd-cross', 'closed-rsi-recovery', 'closed-chandelier-exit', 'native-single-partial-runner'] as $folder) {
            $paths = glob(resource_path('research/instrument-methods/'.$folder.'/*source.json'));
            foreach ($paths as $path) {
                $registered = app(InstrumentMethodSourceService::class)->registerSource(json_decode(file_get_contents($path), true));
                $this->assertSame('registered_reference_only', $registered['status'], json_encode($registered));
            }
            $method = json_decode(file_get_contents(resource_path('research/instrument-methods/'.$folder.'/method.json')), true);
            $registered = app(InstrumentMethodLibraryService::class)->registerMethod($method);
            $this->assertSame('registered_research_only', $registered['status'], json_encode($registered));
        }
        return $installed;
    }
}
