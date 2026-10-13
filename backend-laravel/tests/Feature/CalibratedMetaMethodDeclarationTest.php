<?php

namespace Tests\Feature;

use App\Services\InstrumentMethodLibraryService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Reproducible inputs and software guards; no empirical qualification follows. */
class CalibratedMetaMethodDeclarationTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = 'd3f86298e4343b4f116811e68d09700c718711c72b74e5295068c882b900b5f2';
    private const METHOD = 'e4c355b43ee008d7373fb427dec5ce70b7efd951663a387501ba06513127628b';
    private const RECIPE = '092312d4d77ab61deda7df2e309bb99e0e506aae66dc31a315d11f56969ed5d4';

    public function test_existing_cli_inputs_reproduce_exact_append_only_hashes_idempotently_without_agent_state(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        foreach (range(1, 2) as $attempt) {
            foreach (['source', 'method', 'recipe'] as $action) {
                [$exit, $result, $printed] = $this->command($action);
                $this->assertSame(0, $exit, $printed);
                $key = $action === 'source' ? 'source_key' : $action.'_hash';
                $expected = ['source' => self::SOURCE, 'method' => self::METHOD, 'recipe' => self::RECIPE][$action];
                $this->assertSame($expected, $result[$key]);
                if ($action === 'source') $this->assertArrayNotHasKey('content', $result);
                $this->assertFalse($result['qualification_granted']);
                $this->assertFalse($result['promotion_evidence']);
            }
        }
        $this->assertDatabaseCount('instrument_method_sources', 1);
        $this->assertDatabaseCount('instrument_method_revisions', 1);
        $this->assertDatabaseCount('instrument_recipe_revisions', 1);
        foreach (['agent_method_policy_revisions', 'agent_method_experiences', 'model_versions',
            'research_experiment_work_items', 'lab_generations', 'lab_evolution_credit_events', 'jobs'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame('ef8e53d5549ccbb55800dc4ec2f25e3d7d466b8eb64faf325d4202314e497e20',
            hash_file('sha256', resource_path('research/instrument-methods/curated-v1.json')));
        $this->assertSame('2a62f3d69e223b1cd4cc180c10f3f0628949a9af6ca02645abd92c5e23090ae7',
            hash_file('sha256', resource_path('research/instrument-methods/curated-v2.json')));
    }

    public function test_same_revision_collision_cannot_rewrite_the_declaration_or_activate_it(): void
    {
        foreach (['source', 'method', 'recipe'] as $action) $this->assertSame(0, $this->command($action)[0]);
        $input = $this->input('method');
        $input['implementation']['ast']['args'][1]['args'][1]['value'] = .71;
        $result = app(InstrumentMethodLibraryService::class)->registerMethod($input);
        $this->assertSame('METHOD_IMMUTABLE_REVISION_COLLISION', $result['reason']);
        $this->assertDatabaseCount('instrument_method_revisions', 1);
        $actual = app(InstrumentMethodLibraryService::class)->inspectMethod(self::METHOD);
        $this->assertSame(.70, $actual['contract']['implementation']['ast']['args'][1]['args'][1]['value']);
        $this->assertDatabaseCount('agent_method_policy_revisions', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_php_compiled_threshold_uses_finite_computed_scores_and_missing_model_wait(): void
    {
        foreach (['source', 'method', 'recipe'] as $action) $this->assertSame(0, $this->command($action)[0]);
        $library = app(InstrumentMethodLibraryService::class);
        $parameters = app(StrategyParameterSchemaService::class)->defaults('differential_router');
        $compiled = $library->compileRecipe(self::RECIPE,
            ['strategy_family' => 'differential_router', 'parameters' => $parameters],
            ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'pre_2026_only' => true,
                'data_hash' => str_repeat('a', 64), 'execution_hash' => str_repeat('b', 64),
                'context_predicate' => $this->input('recipe')['context_predicate']]);
        $this->assertSame('compiled_research_recipe', $compiled['status'], json_encode($compiled));
        $operator = $compiled['runtime_recipe']['operators'][0];
        $script = <<<'PY'
import copy,json,sys
sys.path.insert(0, 'tests')
from test_instrument_method_meta_model import original_export,reseal
from app.services.instrument_method_meta_model import FiniteInstrumentMetaModel
from app.services.research_program_tasks import BoundedDecisionProgram
programme=BoundedDecisionProgram(json.load(sys.stdin),allow_method_features=True)
clock=dict(observed_at='2025-01-01T07:30:00+00:00',decision_at='2025-01-01T07:30:00+00:00')
missing=programme.evaluate({'method_meta_model_available':False},**clock)
artifact,reference=original_export()
context=dict(regime='trend_up',volatility='normal',session='london',direction='BUY')
low=FiniteInstrumentMetaModel(artifact,reference).predict(context,.8,**clock)
body=json.loads(artifact['artifact_json'])
body['calibration_bins'][0].update(wins=9,score=10/12)
high_artifact,high_reference=reseal(body)
high=FiniteInstrumentMetaModel(high_artifact,high_reference).predict(context,.8,**clock)
low_result=programme.evaluate({'method_meta_model_available':True,'method_meta_score':low},**clock)
high_result=programme.evaluate({'method_meta_model_available':True,'method_meta_score':high},**clock)
assert missing is False and low_result is False and high_result is True
assert abs(low-7/12)<1e-12 and abs(high-10/12)<1e-12
assert programme.receipt()['promotion_evidence'] is False
print(json.dumps(dict(missing=missing,low=low_result,high=high_result,low_score=low,high_score=high,software_fixture=True)))
PY;
        $process = new Process([env('PYTHON_BINARY', 'python'), '-c', $script], dirname(base_path()).'/ai-service-python');
        $process->setInput(json_encode($operator, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        $process->setTimeout(30);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
        $this->assertFalse($result['missing']);
        $this->assertFalse($result['low']);
        $this->assertTrue($result['high']);
        $this->assertTrue($result['software_fixture']);
        $this->assertDatabaseCount('agent_method_policy_revisions', 0);
        $this->assertDatabaseCount('instrument_method_meta_models', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    private function input(string $action): array
    {
        return json_decode(file_get_contents($this->path($action)), true, 64, JSON_THROW_ON_ERROR);
    }

    private function path(string $action): string
    {
        return resource_path('research/instrument-methods/calibrated-meta-score/'.$action.'.json');
    }

    private function command(string $action): array
    {
        $exit = Artisan::call('trading:instrument-methods', ['action' => $action, '--input' => $this->path($action), '--json' => true]);
        $printed = Artisan::output();
        return [$exit, json_decode($printed, true, 128, JSON_THROW_ON_ERROR), $printed];
    }
}
