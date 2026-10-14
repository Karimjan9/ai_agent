<?php

namespace Tests\Feature;

use App\Services\InstrumentMethodLibraryService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Original primary-source declarations and software guards, not market evidence. */
class ClosedBandMethodDeclarationTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE = 'cf02f74c9bb1f072d4e729e3916143a346bef36a4cd455ed7feec8b60d09c5bd';
    private const METHOD = '0e400298fcd5f22ecfe90a94c237f58bd6e2ff16ddb1b5a8a19fec93c60023ce';
    private const RECIPE = '345ed44cadf270d44acd70e1bbbb395f20cf3e0f85bdbeb1e6d5a9ce296c148b';

    public function test_existing_cli_registers_fresh_closed_band_declaration_without_touching_old_catalogs_or_agent_state(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        foreach (range(1, 2) as $attempt) {
            $source = $this->command('source');
            $method = $this->command('method');
            $recipe = $this->command('recipe');
            $this->assertSame(self::SOURCE, $source['source_key']);
            $this->assertSame(self::METHOD, $method['method_hash']);
            $this->assertSame(self::RECIPE, $recipe['recipe_hash']);
        }
        $this->assertFalse($method['qualification_granted']);
        $this->assertFalse($recipe['promotion_evidence']);
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

    public function test_php_compiled_confirmation_consumes_real_closed_directional_bands_and_never_future_bars(): void
    {
        $this->command('source');
        $this->command('method');
        $recipe = $this->command('recipe');
        $library = app(InstrumentMethodLibraryService::class);
        $compiled = $library->compileRecipe($recipe['recipe_hash'],
            ['strategy_family' => 'differential_router', 'parameters' => app(StrategyParameterSchemaService::class)->defaults('differential_router')],
            ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'pre_2026_only' => true,
                'data_hash' => str_repeat('a', 64), 'execution_hash' => str_repeat('b', 64),
                'context_predicate' => $this->input('method')['context_predicate']]);
        $this->assertSame('compiled_research_recipe', $compiled['status'], json_encode($compiled));
        $operator = $compiled['runtime_recipe']['operators'][0];
        $this->assertSame('confirmation', $operator['target']);
        $this->assertSame(['available' => 'method_band_available', 'reentry' => 'method_band_reentry'], $operator['input_bindings']);
        $script = <<<'PY'
import json,sys
sys.path.insert(0,'tests')
import pandas as pd
from test_instrument_method_band_reentry import frame
from app.services.instrument_method_features import ClosedInstrumentMethodFeatures
from app.services.research_program_tasks import BoundedDecisionProgram
programme=BoundedDecisionProgram(json.load(sys.stdin),allow_method_features=True)
data=frame()
owner=ClosedInstrumentMethodFeatures(data)
answers=[]
for index,direction in [(19,'BUY'),(24,'BUY'),(25,'BUY'),(25,'SELL')]:
    row=data.iloc[index].to_dict()
    observed=(row['time']+pd.Timedelta(minutes=5)).isoformat()
    values=owner.inputs(row,direction=direction,observed_at=observed)
    answers.append(programme.evaluate(values,decision_at=observed,observed_at=observed))
assert answers == [False,False,True,False]
try:
    row=data.iloc[25].to_dict()
    ClosedInstrumentMethodFeatures(data).inputs(row,direction='BUY',observed_at=row['time'].isoformat())
except ValueError as error:
    assert 'ORIGINAL_CLOSED_ROW' in str(error)
else:
    raise AssertionError('premature bar accepted')
assert programme.receipt()['promotion_evidence'] is False
print(json.dumps(dict(answers=answers,software_fixture=True)))
PY;
        $process = new Process([env('PYTHON_BINARY', 'python'), '-c', $script], dirname(base_path()).'/ai-service-python');
        $process->setInput(json_encode($operator, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        $process->setTimeout(45);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame([false, false, true, false], $result['answers']);
        $this->assertTrue($result['software_fixture']);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('agent_method_policy_revisions', 0);
    }

    public function test_same_new_revision_cannot_change_the_rule_after_registration(): void
    {
        foreach (['source', 'method', 'recipe'] as $action) $this->command($action);
        $input = $this->input('method');
        $input['implementation']['ast']['args'][1]['input_key'] = 'available';
        $result = app(InstrumentMethodLibraryService::class)->registerMethod($input);
        $this->assertSame('METHOD_IMMUTABLE_REVISION_COLLISION', $result['reason']);
        $original = app(InstrumentMethodLibraryService::class)->inspectMethod(self::METHOD);
        $this->assertSame('reentry', $original['contract']['implementation']['ast']['args'][1]['input_key']);
        $this->assertDatabaseCount('instrument_method_revisions', 1);
        $this->assertDatabaseCount('instrument_recipe_revisions', 1);
        $this->assertDatabaseCount('jobs', 0);
    }

    private function input(string $action): array
    {
        return json_decode(file_get_contents($this->path($action)), true, 64, JSON_THROW_ON_ERROR);
    }

    private function path(string $action): string
    {
        return resource_path('research/instrument-methods/closed-band-reentry/'.$action.'.json');
    }

    private function command(string $action): array
    {
        $exit = Artisan::call('trading:instrument-methods', ['action' => $action, '--input' => $this->path($action), '--json' => true]);
        $printed = Artisan::output();
        $this->assertSame(0, $exit, $printed);

        return json_decode($printed, true, 128, JSON_THROW_ON_ERROR);
    }
}
