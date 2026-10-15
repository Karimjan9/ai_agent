<?php

namespace Tests\Feature;

use App\Services\InstrumentMethodLibraryService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ClosedBandwidthSqueezeMethodTest extends TestCase
{
    use RefreshDatabase;

    public function test_original_declared_method_compiles_to_actual_closed_python_bandwidth(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $owner = app(InstrumentMethodLibraryService::class);
        $source = $owner->registerSource($this->input('source'));
        $this->assertSame('registered_reference_only', $source['status'], json_encode($source));
        $this->assertSame([$source['source_key']], $this->input('method')['source_keys']);
        $method = $owner->registerMethod($this->input('method'));
        $this->assertSame('registered_research_only', $method['status'], json_encode($method));
        $this->assertSame([$method['method_hash']], $this->input('recipe')['method_hashes']);
        $recipe = $owner->registerRecipe($this->input('recipe'));
        $this->assertSame('registered_research_only', $recipe['status'], json_encode($recipe));
        $this->assertSame($recipe['recipe_hash'], $owner->registerRecipe($this->input('recipe'))['recipe_hash']);
        $schema = app(StrategyParameterSchemaService::class);
        $parameters = array_intersect_key($schema->defaults('ema_rsi'), $schema->schema('ema_rsi'));
        $compiled = $owner->compileRecipe($recipe['recipe_hash'], ['strategy_family'=>'ema_rsi', 'parameters'=>$parameters],
            ['symbol'=>'XAUUSD', 'timeframe'=>'M5', 'pre_2026_only'=>true, 'data_hash'=>str_repeat('a',64),
                'execution_hash'=>str_repeat('b',64), 'context_predicate'=>$this->input('method')['context_predicate']]);
        $this->assertSame('compiled_research_recipe', $compiled['status'], json_encode($compiled));
        $this->assertSame($parameters, $compiled['parameters']);
        $this->assertSame([], $compiled['runtime_recipe']['parameter_adapters']);
        $operator = $compiled['runtime_recipe']['operators'][0];
        $this->assertSame('confirmation', $operator['target']);
        $script = <<<'PY'
import json,sys
import pandas as pd
from app.services.instrument_method_features import ClosedInstrumentMethodFeatures
from app.services.research_program_tasks import BoundedDecisionProgram
operator=json.load(sys.stdin)
answers=[]
for side,last in [('BUY',101.),('SELL',99.)]:
    values=[100.]*144+[last]
    data=pd.DataFrame(dict(time=pd.date_range('2025-01-06T00:00:00Z',periods=145,freq='5min'),open=values,high=[x+.1 for x in values],low=[x-.1 for x in values],close=values))
    owner=ClosedInstrumentMethodFeatures(data)
    programme=BoundedDecisionProgram(operator,allow_method_features=True)
    for i,direction in [(143,side),(144,'SELL' if side=='BUY' else 'BUY'),(144,side)]:
        row=data.iloc[i].to_dict();when=(row['time']+pd.Timedelta(minutes=5)).isoformat()
        inputs=owner.inputs(row,direction=direction,observed_at=when)
        answers.append(programme.evaluate(inputs,decision_at=when,observed_at=when))
assert answers==[False,False,True,False,False,True],answers
print(json.dumps(answers))
PY;
        $process = new Process([env('PYTHON_BINARY','python'), '-c', $script], dirname(base_path()).'/ai-service-python');
        $process->setInput(json_encode($operator, JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR));
        $process->setTimeout(60); $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame([false,false,true,false,false,true], json_decode($process->getOutput(), true));
        $this->assertFalse($compiled['qualification_granted']);
        foreach (['agent_method_policy_revisions','agent_method_experiences','lab_generations','jobs','lab_evolution_credit_events'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $changed = $this->input('method');
        $changed['implementation']['input_bindings']['breakout']='method_compression_expansion';
        $this->assertSame('METHOD_IMMUTABLE_REVISION_COLLISION', $owner->registerMethod($changed)['reason']);
    }

    private function input(string $kind): array
    {
        return json_decode(file_get_contents(resource_path('research/instrument-methods/closed-bandwidth-squeeze/'.$kind.'.json')),true,64,JSON_THROW_ON_ERROR);
    }
}
