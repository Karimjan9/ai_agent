<?php

namespace Tests\Feature;

use App\Services\AgentMethodLearningService;
use App\Services\InstrumentMethodLibraryService;
use App\Services\InstrumentMethodResearchBindingService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Conditional software contract execution only; no market or trading authority. */
class InstrumentMethodPhaseSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_future_canonical_mixed_role_contract_executes_both_phases_and_preserves_legacy_absence(): void
    {
        $entry = $this->recipe('confirmation', 'entry'); $exit = $this->recipe('exit', 'exit');
        foreach ([[$entry, $exit], [$exit, $entry]] as $order) {
            $contract = $this->compile($order, 'phase-owner-'.($order[0]['recipe_key']));
            $this->assertSame(InstrumentMethodResearchBindingService::PHASE_SELECTION_PROTOCOL, $contract['selection_protocol']);
            $script = <<<'PY'
import copy,json,sys
from app.services.instrument_method_learning import InstrumentMethodLearning,validate_contract
from app.services.research_program_tasks import canonical_hash,canonical_json
c=json.load(sys.stdin)
validate_contract(c,dataset_hash=c['data_hash'],execution_hash=c['execution_hash'],expected_agent_key=c['expected_agent_key'])
def run(contract,phase):
    owner=InstrumentMethodLearning(contract,dataset_hash=contract['data_hash'],execution_hash=contract['execution_hash'])
    return owner.evaluate(dict(close=100.,market_regime='trend_up',market_session='london',volatility_regime='normal'),
        decision_id='synthetic-'+phase,phase=phase,decision_at='2025-01-06T12:05:00Z',observed_at='2025-01-06T12:00:00Z',direction='BUY')
entry,exit=run(c,'entry'),run(c,'exit')
assert entry['outputs']==dict(confirmation=True),entry
assert exit['outputs']==dict(exit=True),exit
assert entry['used'] and exit['used']
assert entry['policy_hash']==exit['policy_hash'] and not exit['economic_authority']
legacy={k:v for k,v in c.items() if k not in {'contract_hash','contract_json','selection_protocol'}}
legacy={**legacy,'contract_hash':canonical_hash(legacy),'contract_json':canonical_json(legacy)}
old_entry,old_exit=run(legacy,'entry'),run(legacy,'exit')
assert sum(bool(item['used']) for item in [old_entry,old_exit])==1
assert any(item['reason']=='recipe_target_unreached' for item in [old_entry,old_exit])
assert c['contract_hash']!=legacy['contract_hash']
assert c['policy']==legacy['policy'] and c['library_hash']==legacy['library_hash']
unknown={k:v for k,v in c.items() if k not in {'contract_hash','contract_json'}}
unknown['selection_protocol']='phase_target_applicable_v3'
unknown={**unknown,'contract_hash':canonical_hash(unknown),'contract_json':canonical_json(unknown)}
try:run(unknown,'entry')
except ValueError as error:assert str(error)=='METHOD_LEARNING_SELECTION_PROTOCOL_INVALID',error
else:raise AssertionError('unknown sealed selection protocol accepted')
print(json.dumps(dict(entry=entry['outputs'],exit=exit['outputs'],old_used=sum(bool(item['used']) for item in [old_entry,old_exit]),
    old_contract_hash=legacy['contract_hash'],new_contract_hash=c['contract_hash'])))
PY;
            $process = new Process([env('PYTHON_BINARY', 'python'), '-c', $script], dirname(base_path()).'/ai-service-python');
            $process->setInput(json_encode($contract, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
            $process->setTimeout(45); $process->run();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $actual = json_decode($process->getOutput(), true, 64, JSON_THROW_ON_ERROR);
            $this->assertSame(['confirmation' => true], $actual['entry']); $this->assertSame(['exit' => true], $actual['exit']);
            $this->assertSame(1, $actual['old_used']); $this->assertNotSame($actual['old_contract_hash'], $actual['new_contract_hash']);
        }
        $this->assertDatabaseCount('model_versions', 0); $this->assertDatabaseCount('lab_evaluation_runs', 0);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0); $this->assertDatabaseCount('jobs', 0);
    }

    public function test_pure_entry_and_single_combined_recipe_preserve_the_old_contract_shape(): void
    {
        $entry = $this->recipe('confirmation', 'entry-only'); $second = $this->recipe('confirmation', 'entry-second');
        $this->assertArrayNotHasKey('selection_protocol', $this->compile([$entry, $second], 'entry-only-owner'));
        $library = app(InstrumentMethodLibraryService::class);
        $combined = $library->registerRecipe(['recipe_key' => 'one-combined', 'revision' => 1,
            'method_hashes' => [$entry['method_hash'], $this->recipe('exit', 'combined-exit')['method_hash']],
            'context_predicate' => $this->context()]);
        $this->assertSame('registered_research_only', $combined['status'], json_encode($combined));
        $this->assertArrayNotHasKey('selection_protocol', $this->compile([['recipe_hash' => $combined['recipe_hash']]], 'combined-owner'));
    }

    public function test_future_compiler_does_not_silently_skip_construction_adapter_recipes(): void
    {
        $entry = $this->recipe('confirmation', 'adapter-source'); $exit = $this->recipe('exit', 'adapter-exit');
        $library = app(InstrumentMethodLibraryService::class);
        $method = $library->registerMethod(['instrument_key' => 'cost_aware_exit', 'method_key' => 'same-baseline-adapter',
            'revision' => 1, 'context_predicate' => $this->context(), 'horizon' => 'hour',
            'known_limitations' => ['Synthetic fresh-construction reference only.'], 'source_keys' => [$entry['source_key']],
            'implementation' => ['kind' => 'parameter_adapter', 'strategy_family' => 'ema_rsi', 'parameters' => ['time_stop_candles' => 24]]]);
        $this->assertSame('registered_research_only', $method['status'], json_encode($method));
        $recipe = $library->registerRecipe(['recipe_key' => 'same-baseline-adapter', 'revision' => 1,
            'method_hashes' => [$method['method_hash']], 'context_predicate' => $this->context()]);
        $this->assertSame('registered_research_only', $recipe['status'], json_encode($recipe));
        $schema = app(StrategyParameterSchemaService::class);
        $parameters = array_intersect_key($schema->defaults('ema_rsi'), $schema->schema('ema_rsi')); $parameters['time_stop_candles'] = 24;
        $compiled = $this->compile([$entry, $exit, ['recipe_hash' => $recipe['recipe_hash']]], 'adapter-owner', 'ema_rsi', $parameters);
        $this->assertArrayNotHasKey('selection_protocol', $compiled);
        $this->assertTrue((bool) array_filter($compiled['recipes'], fn ($row) => $row['parameter_adapters'] !== []));
    }

    private function recipe(string $target, string $name): array
    {
        $library = app(InstrumentMethodLibraryService::class);
        $source = $library->registerSource(['kind' => 'imported_text', 'reference' => 'synthetic phase-selection software fixture',
            'content' => 'Synthetic contract test; no market outcomes.', 'metadata' => ['license' => 'test fixture',
                'access' => 'user_supplied', 'exposure' => ['status' => 'unknown']]]);
        $this->assertSame('registered_reference_only', $source['status'], json_encode($source));
        $method = $library->registerMethod(['instrument_key' => $target === 'exit' ? 'cost_aware_exit' : 'trend_pullback',
            'method_key' => 'phase-'.$name, 'revision' => 1, 'context_predicate' => $this->context(), 'horizon' => 'hour',
            'known_limitations' => ['Synthetic operator, not independently qualified.'], 'source_keys' => [$source['source_key']],
            'implementation' => ['kind' => 'typed_ast', 'target' => $target, 'ast' => ['op' => 'CONST', 'type' => 'bool', 'value' => true],
                'input_bindings' => [], 'budget' => ['cpu_seconds' => .5, 'max_calls' => 100, 'max_node_evaluations' => 4800]]]);
        $this->assertSame('registered_research_only', $method['status'], json_encode($method));
        $recipe = $library->registerRecipe(['recipe_key' => 'phase-'.$name, 'revision' => 1,
            'method_hashes' => [$method['method_hash']], 'context_predicate' => $this->context()]);
        $this->assertSame('registered_research_only', $recipe['status'], json_encode($recipe));
        return [...$recipe, 'method_hash' => $method['method_hash'], 'source_key' => $source['source_key'], 'recipe_key' => 'phase-'.$name];
    }

    private function compile(array $recipes, string $key, string $family = 'breakout', ?array $parameters = null): array
    {
        $policy = app(AgentMethodLearningService::class)->registerPolicy(['agent_key' => $key, 'revision' => 1,
            'recipe_hashes' => array_column($recipes, 'recipe_hash'), 'rules' => [['context_predicate' => $this->context(),
                'preferred_recipes' => array_column($recipes, 'recipe_hash')]], 'default_action' => 'BASELINE']);
        $this->assertSame('registered_research_only', $policy['status'], json_encode($policy));
        return app(InstrumentMethodResearchBindingService::class)->compilePolicy($policy['policy_hash'],
            ['strategy_family' => $family, 'parameters' => $parameters ?? app(StrategyParameterSchemaService::class)->defaults($family)],
            ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'data_hash' => str_repeat('a', 64), 'execution_hash' => str_repeat('b', 64),
                'evaluated_start' => '2025-01-06T12:00:00Z', 'evaluated_end' => '2025-01-06T14:00:00Z'], $key);
    }

    private function context(): array { return ['regime' => 'trend_up', 'session' => 'london', 'volatility' => 'normal']; }
}
