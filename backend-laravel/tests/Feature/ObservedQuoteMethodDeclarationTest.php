<?php

namespace Tests\Feature;

use App\Services\InstrumentMethodLibraryService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Software and provenance fixtures; no market or economic qualification. */
class ObservedQuoteMethodDeclarationTest extends TestCase
{
    use RefreshDatabase;

    private const HASHES = [
        'quote-source' => 'd7eef36f3aca847123b24592921058200f9ae57dc4b6ce2117f3edb54e65415a',
        'atr-source' => '6f30ee62684fd9d29d58ed7fc4cfb40bf0a2abe73dd023ae4c3fd7b82ba8a030',
        'method' => '41044f73004c8c580fa3ba292177a4126411b991ca15e2a1c31b71f5e0b15e55',
        'recipe' => '4f82f684251176f2184d32ebe122bdfa816d2c1b938494c11b39e7b19fbee8e7',
    ];

    public function test_cli_imports_exact_append_only_declarations_without_policy_or_execution(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->register();
        $this->register();
        $this->assertDatabaseCount('instrument_method_sources', 2);
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

    public function test_threshold_collision_cannot_rewrite_registered_method(): void
    {
        $this->register();
        $input = $this->input('method');
        $input['implementation']['ast']['args'][1]['args'][1]['value'] = .11;
        $library = app(InstrumentMethodLibraryService::class);
        $this->assertSame('METHOD_IMMUTABLE_REVISION_COLLISION', $library->registerMethod($input)['reason']);
        $actual = $library->inspectMethod(self::HASHES['method']);
        $this->assertSame(.10, $actual['contract']['implementation']['ast']['args'][1]['args'][1]['value']);
        $this->assertDatabaseCount('instrument_method_revisions', 1);
        $this->assertDatabaseCount('agent_method_policy_revisions', 0);
    }

    public function test_php_compiled_quote_gate_uses_real_closed_features_and_retains_physical_parameters(): void
    {
        $this->register();
        $parameters = app(StrategyParameterSchemaService::class)->defaults('differential_router');
        $compiled = app(InstrumentMethodLibraryService::class)->compileRecipe(self::HASHES['recipe'],
            ['strategy_family' => 'differential_router', 'parameters' => $parameters],
            ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'pre_2026_only' => true,
                'data_hash' => str_repeat('a', 64), 'execution_hash' => str_repeat('b', 64),
                'context_predicate' => $this->input('recipe')['context_predicate']]);
        $this->assertSame('compiled_research_recipe', $compiled['status'], json_encode($compiled));
        $this->assertSame($parameters, $compiled['parameters']);
        $this->assertSame([], $compiled['runtime_recipe']['parameter_adapters']);
        $this->assertSame('closed_instrument_method_features_v1', $compiled['runtime_recipe']['scope_bindings']['method_feature_owner']);
        $script = <<<'PY'
import json,sys
import pandas as pd
from app.services.instrument_method_features import ClosedInstrumentMethodFeatures
from app.services.research_program_tasks import BoundedDecisionProgram
program=BoundedDecisionProgram(json.load(sys.stdin),allow_method_features=True)
data=pd.DataFrame({'time':pd.date_range('2025-01-06T08:00Z',periods=24,freq='5min'),
    'open':100.,'high':101.,'low':99.,'close':100.})
def decide(frame,index=20):
    row=frame.iloc[index].to_dict()
    clock=(row['time']+pd.Timedelta(minutes=5)).isoformat()
    observed=ClosedInstrumentMethodFeatures(frame).inputs(row,direction='BUY',observed_at=clock)
    return program.evaluate(observed,observed_at=clock,decision_at=clock)
def quotes(spread):
    result=data.copy()
    result['spread_available']=True
    result['bid_close']=result['close']
    result['ask_close']=result['close']+spread
    result['bid_ask_spread']=spread
    result['quote_time_utc']=result['time']+pd.Timedelta(minutes=4)
    result['quote_available_after_utc']=result['time']+pd.Timedelta(minutes=5)
    return result
missing=decide(data)
narrow=decide(quotes(.10))
boundary=decide(quotes(.20))
wide=decide(quotes(.40))
future=quotes(.10);future['quote_time_utc']=future['time']+pd.Timedelta(minutes=6)
inconsistent=quotes(.10);inconsistent['ask_close']+=1
immature=decide(quotes(.10),index=12)
assert missing is False and narrow is True and boundary is False and wide is False
assert decide(future) is False and decide(inconsistent) is False and immature is False
assert program.receipt()['promotion_evidence'] is False
print(json.dumps(dict(missing=missing,narrow=narrow,boundary=boundary,wide=wide,software_fixture=True)))
PY;
        $process = new Process([env('PYTHON_BINARY', 'python'), '-c', $script], dirname(base_path()).'/ai-service-python');
        $process->setInput(json_encode($compiled['runtime_recipe']['operators'][0], JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        $process->setTimeout(30);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
        $this->assertFalse($result['missing']);
        $this->assertTrue($result['narrow']);
        $this->assertFalse($result['boundary']);
        $this->assertFalse($result['wide']);
        $this->assertFalse($compiled['qualification_granted']);
        $this->assertFalse($compiled['promotion_evidence']);
        $this->assertDatabaseCount('agent_method_policy_revisions', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    private function register(): void
    {
        foreach (self::HASHES as $file => $hash) {
            $action = str_ends_with($file, '-source') ? 'source' : $file;
            $exit = Artisan::call('trading:instrument-methods', ['action' => $action, '--input' => $this->path($file), '--json' => true]);
            $printed = Artisan::output();
            $this->assertSame(0, $exit, $printed);
            $result = json_decode($printed, true, 128, JSON_THROW_ON_ERROR);
            $this->assertSame($hash, $result[$action === 'source' ? 'source_key' : $action.'_hash']);
            $this->assertFalse($result['qualification_granted']);
            $this->assertFalse($result['promotion_evidence']);
            if ($action === 'source') $this->assertArrayNotHasKey('content', $result);
        }
    }

    private function input(string $file): array
    {
        return json_decode(file_get_contents($this->path($file)), true, 64, JSON_THROW_ON_ERROR);
    }

    private function path(string $file): string
    {
        return resource_path('research/instrument-methods/observed-quote-firewall/'.$file.'.json');
    }
}
