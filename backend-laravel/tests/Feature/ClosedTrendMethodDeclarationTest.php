<?php

namespace Tests\Feature;

use App\Services\InstrumentMethodLibraryService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Primary-source hypotheses and executable software guards, never market qualification. */
class ClosedTrendMethodDeclarationTest extends TestCase
{
    use RefreshDatabase;

    private const DECLARATIONS = [
        'closed-ema-pullback' => [
            'source' => '3ab3d22426a0babdb6a5e10f3b5ae228611483f89262e15c4e2805dfeb5573a6',
            'method' => '8da55685e120d9ddd5fdecb4d0bc8b7efa54bee36f955bf4c981d67df383e590',
            'recipe' => '29239338b134c35d103acb560cf69ecf3bc339b2f71c71d2d4502c77a8bdbccd',
        ],
        'closed-donchian-retest' => [
            'source' => '5d89c6ade98b8d3ece16d713569f4bc575f9cb85eda7f0dcdd47b698e946db67',
            'method' => '094252dde2eb8e58f775d439479d9197ea8a2a1b22cd158aedd4a272079d20aa',
            'recipe' => 'b6b26b1c4b87ac822441720e90983564f00bd742959a69e9645d703ee1d36084',
        ],
    ];

    public function test_existing_cli_registers_two_append_only_hypotheses_without_catalog_or_agent_mutation(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        foreach (range(1, 2) as $attempt) {
            foreach (self::DECLARATIONS as $directory => $hashes) $this->register($directory);
        }
        $this->assertDatabaseCount('instrument_method_sources', 2);
        $this->assertDatabaseCount('instrument_method_revisions', 2);
        $this->assertDatabaseCount('instrument_recipe_revisions', 2);
        foreach (['agent_method_policy_revisions', 'agent_method_experiences', 'model_versions',
            'research_experiment_work_items', 'lab_generations', 'lab_evolution_credit_events', 'jobs'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame('ef8e53d5549ccbb55800dc4ec2f25e3d7d466b8eb64faf325d4202314e497e20',
            hash_file('sha256', resource_path('research/instrument-methods/curated-v1.json')));
        $this->assertSame('2a62f3d69e223b1cd4cc180c10f3f0628949a9af6ca02645abd92c5e23090ae7',
            hash_file('sha256', resource_path('research/instrument-methods/curated-v2.json')));
    }

    public function test_php_compiled_programs_consume_real_closed_directional_features_and_preserve_baseline(): void
    {
        $schema = app(StrategyParameterSchemaService::class);
        $parameters = array_intersect_key($schema->defaults('differential_router'), $schema->schema('differential_router'));
        $operators = [];
        foreach (self::DECLARATIONS as $directory => $hashes) {
            $this->register($directory);
            $compiled = app(InstrumentMethodLibraryService::class)->compileRecipe($hashes['recipe'],
                ['strategy_family' => 'differential_router', 'parameters' => $parameters],
                ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'pre_2026_only' => true,
                    'data_hash' => str_repeat('a', 64), 'execution_hash' => str_repeat('b', 64),
                    'context_predicate' => $this->input($directory, 'method')['context_predicate']]);
            $this->assertSame('compiled_research_recipe', $compiled['status'], json_encode($compiled));
            $this->assertSame($parameters, $compiled['parameters']);
            $this->assertSame([], $compiled['runtime_recipe']['parameter_adapters']);
            $this->assertSame(InstrumentMethodLibraryService::FEATURE_OWNER,
                $compiled['runtime_recipe']['scope_bindings']['method_feature_owner']);
            $this->assertFalse($compiled['qualification_granted']);
            $this->assertFalse($compiled['promotion_evidence']);
            $operators[$directory] = $compiled['runtime_recipe']['operators'][0];
            $this->assertSame('confirmation', $operators[$directory]['target']);
            $this->assertSame($this->input($directory, 'method')['implementation']['input_bindings'],
                $operators[$directory]['input_bindings']);
        }
        $script = <<<'PY'
import json,sys
import numpy as np
import pandas as pd
from app.services.instrument_method_features import ClosedInstrumentMethodFeatures
from app.services.research_program_tasks import BoundedDecisionProgram
operators=json.load(sys.stdin)

def frame(closes):
    closes=np.asarray(closes,dtype=float)
    return pd.DataFrame(dict(time=pd.date_range('2025-01-06T08:00:00Z',periods=len(closes),freq='5min'),
        open=closes,high=closes+.2,low=closes-.2,close=closes,volume=0.,volume_available=False))

def evaluate(directory,data,cases):
    programme=BoundedDecisionProgram(operators[directory],allow_method_features=True)
    owner=ClosedInstrumentMethodFeatures(data)
    answers=[]
    for index,direction in cases:
        row=data.iloc[index].to_dict()
        observed=(row['time']+pd.Timedelta(minutes=5)).isoformat()
        values=owner.inputs(row,direction=direction,observed_at=observed)
        answers.append(programme.evaluate(values,decision_at=observed,observed_at=observed))
    assert programme.receipt()['promotion_evidence'] is False
    return answers

# Explicitly synthetic price sequences prove software semantics only.
pullback=frame([100.+i*.1 for i in range(60)]+[0.,0.])
ema20=pullback.loc[:59,'close'].ewm(span=20,adjust=False,min_periods=50).mean().iloc[-1]
pullback.loc[60,['open','close']]=ema20-.5
pullback.loc[60,'low']=ema20-.7
pullback.loc[60,'high']=ema20-.3
pullback.loc[61,['open','close']]=ema20+1.
pullback.loc[61,'low']=ema20+.8
pullback.loc[61,'high']=ema20+1.2
bounce=evaluate('closed-ema-pullback',pullback,[(49,'BUY'),(60,'BUY'),(61,'BUY'),(61,'SELL')])
assert bounce == [False,False,True,False], bounce
mirror=pullback.copy()
mirror['open']=200.-pullback['open'];mirror['close']=200.-pullback['close']
mirror['high']=200.-pullback['low'];mirror['low']=200.-pullback['high']
assert evaluate('closed-ema-pullback',mirror,[(61,'SELL'),(61,'BUY')]) == [True,False]

retest=frame([100.]*20+[102.,101.5,101.4,101.6])
retest.loc[:19,'high']=101.;retest.loc[:19,'low']=99.
retest.loc[20,'high']=102.5;retest.loc[20,'low']=100.5
retest.loc[21:,'low']=100.5
delayed=evaluate('closed-donchian-retest',retest,[(19,'BUY'),(20,'BUY'),(21,'BUY'),(21,'SELL'),(22,'BUY')])
assert delayed == [False,False,True,False,False], delayed
mirror=retest.copy()
mirror['open']=200.-retest['open'];mirror['close']=200.-retest['close']
mirror['high']=200.-retest['low'];mirror['low']=200.-retest['high']
assert evaluate('closed-donchian-retest',mirror,[(20,'SELL'),(21,'SELL'),(22,'SELL')]) == [False,True,False]
for data in [pullback,retest]:
    row=data.iloc[-1].to_dict()
    try:
        ClosedInstrumentMethodFeatures(data).inputs(row,direction='BUY',observed_at=row['time'].isoformat())
    except ValueError as error:
        assert 'ORIGINAL_CLOSED_ROW' in str(error)
    else:
        raise AssertionError('premature bar accepted')
print(json.dumps(dict(bounce=bounce,delayed=delayed,software_fixture=True)))
PY;
        $process = new Process([env('PYTHON_BINARY', 'python'), '-c', $script], dirname(base_path()).'/ai-service-python');
        $process->setInput(json_encode($operators, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        $process->setTimeout(45);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame([false, false, true, false], $result['bounce']);
        $this->assertSame([false, false, true, false, false], $result['delayed']);
        $this->assertTrue($result['software_fixture']);
        $this->assertDatabaseCount('agent_method_policy_revisions', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_registered_revisions_cannot_replace_closed_rule_with_availability_alone(): void
    {
        foreach (self::DECLARATIONS as $directory => $hashes) {
            $this->register($directory);
            $input = $this->input($directory, 'method');
            $originalRule = $input['implementation']['ast']['args'][1]['input_key'];
            $input['implementation']['ast']['args'][1]['input_key'] = 'available';
            $result = app(InstrumentMethodLibraryService::class)->registerMethod($input);
            $this->assertSame('METHOD_IMMUTABLE_REVISION_COLLISION', $result['reason']);
            $original = app(InstrumentMethodLibraryService::class)->inspectMethod($hashes['method']);
            $this->assertSame($originalRule, $original['contract']['implementation']['ast']['args'][1]['input_key']);
        }
        $this->assertDatabaseCount('instrument_method_revisions', 2);
        $this->assertDatabaseCount('instrument_recipe_revisions', 2);
        $this->assertDatabaseCount('jobs', 0);
    }

    private function register(string $directory): void
    {
        foreach (self::DECLARATIONS[$directory] as $action => $hash) {
            $exit = Artisan::call('trading:instrument-methods', ['action' => $action, '--input' => $this->path($directory, $action), '--json' => true]);
            $printed = Artisan::output();
            $this->assertSame(0, $exit, $printed);
            $result = json_decode($printed, true, 128, JSON_THROW_ON_ERROR);
            $this->assertSame($hash, $result[$action === 'source' ? 'source_key' : $action.'_hash']);
            $this->assertFalse($result['qualification_granted']);
            $this->assertFalse($result['promotion_evidence']);
            if ($action === 'source') $this->assertArrayNotHasKey('content', $result);
        }
    }

    private function input(string $directory, string $action): array
    {
        return json_decode(file_get_contents($this->path($directory, $action)), true, 64, JSON_THROW_ON_ERROR);
    }

    private function path(string $directory, string $action): string
    {
        return resource_path('research/instrument-methods/'.$directory.'/'.$action.'.json');
    }
}
