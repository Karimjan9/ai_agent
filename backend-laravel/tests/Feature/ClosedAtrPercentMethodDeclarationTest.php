<?php

namespace Tests\Feature;

use App\Services\InstrumentMethodLibraryService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Exact published normalization plus disclosed lab choices; no market qualification. */
class ClosedAtrPercentMethodDeclarationTest extends TestCase
{
    use RefreshDatabase;

    public function test_append_only_atr_declaration_registers_idempotently_without_touching_agents_or_old_catalogs(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $first = $this->register(); $second = $this->register();
        $this->assertSame($first['recipe_hash'], $second['recipe_hash']);
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
        $this->assertSame(['regime' => 'trend_up', 'session' => 'new_york', 'volatility' => 'normal'],
            $first['contract']['context_predicate']);
        $this->assertStringContainsString('earlier archive opportunity inspection', $this->input('source')['content']);
        $this->assertContains('method_pullback_pretrend_available', InstrumentMethodLibraryService::FEATURE_BOOL_INPUTS);
        $this->assertContains('method_pullback_pretrend_confirmed', InstrumentMethodLibraryService::FEATURE_BOOL_INPUTS);
        $this->assertContains('method_atr_percent_available', InstrumentMethodLibraryService::FEATURE_BOOL_INPUTS);
        $this->assertContains('method_atr_percent', InstrumentMethodLibraryService::FEATURE_NUMBER_INPUTS);
    }

    public function test_actual_php_compilation_consumes_closed_python_atr_percent_with_equal_boundary_and_unchanged_baseline(): void
    {
        $recipe = $this->register(); $schema = app(StrategyParameterSchemaService::class);
        $parameters = array_intersect_key($schema->defaults('differential_router'), $schema->schema('differential_router'));
        $compiled = app(InstrumentMethodLibraryService::class)->compileRecipe($recipe['recipe_hash'],
            ['strategy_family' => 'differential_router', 'parameters' => $parameters],
            ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'pre_2026_only' => true,
                'data_hash' => str_repeat('a', 64), 'execution_hash' => str_repeat('b', 64),
                'context_predicate' => $this->input('method')['context_predicate']]);
        $this->assertSame('compiled_research_recipe', $compiled['status'], json_encode($compiled));
        $this->assertSame($parameters, $compiled['parameters']);
        $this->assertSame([], $compiled['runtime_recipe']['parameter_adapters']);
        $this->assertFalse($compiled['qualification_granted']); $this->assertFalse($compiled['promotion_evidence']);
        $operator = $compiled['runtime_recipe']['operators'][0];
        $this->assertSame('confirmation', $operator['target']);
        $this->assertSame($this->input('method')['implementation']['input_bindings'], $operator['input_bindings']);
        $script = <<<'PY'
import json,sys
import pandas as pd
from app.services.instrument_method_features import ClosedInstrumentMethodFeatures
from app.services.research_program_tasks import BoundedDecisionProgram
programme=BoundedDecisionProgram(json.load(sys.stdin),allow_method_features=True)
answers=[];percentages=[]
for width in [1.,2.,4.]:
    data=pd.DataFrame(dict(time=pd.date_range('2025-01-06T08:00:00Z',periods=16,freq='5min'),
        open=2000.,high=2000.+width/2,low=2000.-width/2,close=2000.))
    owner=ClosedInstrumentMethodFeatures(data)
    for index in [12,13]:
        row=data.iloc[index].to_dict();stamp=(row['time']+pd.Timedelta(minutes=5)).isoformat()
        inputs=owner.inputs(row,direction='BUY',observed_at=stamp)
        answers.append(programme.evaluate(inputs,decision_at=stamp,observed_at=stamp))
        percentages.append(inputs.get('method_atr_percent'))
assert answers == [False,True,False,True,False,False], answers
assert percentages == [None,.05,None,.1,None,.2], percentages
receipt=programme.receipt()
assert receipt['calls']==6 and receipt['promotion_evidence'] is False
print(json.dumps(dict(answers=answers,percentages=percentages,calls=receipt['calls'])))
PY;
        $process = new Process([env('PYTHON_BINARY', 'python'), '-c', $script], dirname(base_path()).'/ai-service-python');
        $process->setInput(json_encode($operator, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        $process->setTimeout(45); $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $actual = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame([false, true, false, true, false, false], $actual['answers']);
        $this->assertSame(6, $actual['calls']);
        $this->assertDatabaseCount('agent_method_policy_revisions', 0);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0); $this->assertDatabaseCount('jobs', 0);
    }

    public function test_existing_revision_cannot_change_cap_or_source_and_new_context_does_not_relax_validation(): void
    {
        $this->register(); $owner = app(InstrumentMethodLibraryService::class);
        $changed = $this->input('method');
        $changed['implementation']['ast']['args'][1]['args'][0]['args'][1]['value'] = .20;
        $this->assertSame('METHOD_IMMUTABLE_REVISION_COLLISION', $owner->registerMethod($changed)['reason']);
        foreach ([[], ['regime' => 'trend_up'], ['regime' => 'trend_up', 'session' => 'new_york', 'volatility' => 'normal', 'unsupported' => 'all']] as $context) {
            $changed = $this->input('method'); $changed['method_key'] .= '-invalid'; $changed['context_predicate'] = $context;
            $this->assertSame('METHOD_IDENTITY_USAGE_CONTEXT_AND_SOURCES_REQUIRED', $owner->registerMethod($changed)['reason']);
        }
        $this->assertDatabaseCount('instrument_method_revisions', 1); $this->assertDatabaseCount('jobs', 0);
    }

    public function test_companion_ema_pretrend_declaration_registers_its_exact_new_scope_and_compiles_owner_features(): void
    {
        $owner = app(InstrumentMethodLibraryService::class);
        $input = fn (string $kind): array => json_decode(file_get_contents(resource_path(
            'research/instrument-methods/closed-ema-slope-pullback/'.$kind.'.json')), true, 64, JSON_THROW_ON_ERROR);
        $source = $owner->registerSource($input('source'));
        $this->assertSame('registered_reference_only', $source['status'], json_encode($source));
        $this->assertSame([$source['source_key']], $input('method')['source_keys']);
        $method = $owner->registerMethod($input('method'));
        $this->assertSame('registered_research_only', $method['status'], json_encode($method));
        $this->assertSame([$method['method_hash']], $input('recipe')['method_hashes']);
        $recipe = $owner->registerRecipe($input('recipe'));
        $this->assertSame('registered_research_only', $recipe['status'], json_encode($recipe));
        $schema = app(StrategyParameterSchemaService::class);
        $parameters = array_intersect_key($schema->defaults('differential_router'), $schema->schema('differential_router'));
        $compiled = $owner->compileRecipe($recipe['recipe_hash'], ['strategy_family' => 'differential_router', 'parameters' => $parameters],
            ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'pre_2026_only' => true, 'data_hash' => str_repeat('a', 64),
                'execution_hash' => str_repeat('b', 64), 'context_predicate' => $input('method')['context_predicate']]);
        $this->assertSame('compiled_research_recipe', $compiled['status'], json_encode($compiled));
        $this->assertSame($parameters, $compiled['parameters']);
        $this->assertSame(['regime' => 'trend_up', 'session' => 'new_york', 'volatility' => 'normal'],
            $recipe['contract']['context_predicate']);
        $bindings = $compiled['runtime_recipe']['operators'][0]['input_bindings'];
        $this->assertContains('method_pullback_pretrend_available', $bindings);
        $this->assertContains('method_pullback_pretrend_confirmed', $bindings);
        $this->assertFalse($compiled['qualification_granted']); $this->assertFalse($compiled['promotion_evidence']);
        $this->assertDatabaseCount('agent_method_policy_revisions', 0); $this->assertDatabaseCount('jobs', 0);
    }

    private function register(): array
    {
        $owner = app(InstrumentMethodLibraryService::class); $source = $owner->registerSource($this->input('source'));
        $this->assertSame('registered_reference_only', $source['status'], json_encode($source));
        $this->assertSame([$source['source_key']], $this->input('method')['source_keys']);
        $method = $owner->registerMethod($this->input('method'));
        $this->assertSame('registered_research_only', $method['status'], json_encode($method));
        $this->assertSame([$method['method_hash']], $this->input('recipe')['method_hashes']);
        $recipe = $owner->registerRecipe($this->input('recipe'));
        $this->assertSame('registered_research_only', $recipe['status'], json_encode($recipe));
        $this->assertFalse($recipe['qualification_granted']); $this->assertFalse($recipe['promotion_evidence']);
        return $recipe;
    }

    private function input(string $kind): array
    {
        return json_decode(file_get_contents(resource_path('research/instrument-methods/closed-atr-percent/'.$kind.'.json')),
            true, 64, JSON_THROW_ON_ERROR);
    }
}
