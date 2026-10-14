<?php

namespace Tests\Feature;

use App\Services\InstrumentMethodLibraryService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** CME technique, explicitly lab settings and simplified native bookkeeping. */
class NativeSinglePartialMethodDeclarationTest extends TestCase
{
    use RefreshDatabase;

    private const HASHES = [
        'source' => 'c38756f9baa045b0d59c0aebb1f03500635556ae8e1ad7cbbf5ce628d1f540b2',
        'method' => 'fa8f367e9aa39f8a736a25ae61dbd74a1b322b36b297a247180f297ce3cd6b62',
        'recipe' => '352e921ced802745443930efe1996f68351b68770fdacda6207b306a681f3c37',
    ];

    public function test_cli_registers_append_only_source_method_and_recipe_without_agent_or_catalog_changes(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->register();
        $this->register();
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
        $method = $this->input('method');
        $limitations = implode(' ', $method['known_limitations']);
        foreach (['CURRENT evaluated candle', 'not a fixed 1R', 'one partial only',
            'realized-cash partial-close ledger', 'before as well as after', 'not a Chandelier',
            'prospective lab hypotheses'] as $limitation) {
            $this->assertStringContainsString($limitation, $limitations);
        }
        $method['implementation']['parameters']['partial_take_profit_fraction'] = 0.60;
        $collision = app(InstrumentMethodLibraryService::class)->registerMethod($method);
        $this->assertSame('METHOD_IMMUTABLE_REVISION_COLLISION', $collision['reason']);
    }

    public function test_adapter_changes_only_the_four_declared_management_fields_and_preserves_guards(): void
    {
        $this->register();
        $parameters = $this->parameters('ema_rsi');
        $compiled = $this->compile($parameters);
        $this->assertSame('parameter_adapter_recipe', $compiled['status'], json_encode($compiled));
        $expected = [...$parameters, ...$this->input('method')['implementation']['parameters']];
        $this->assertSame($expected, $compiled['parameters']);
        foreach (array_diff(array_keys($parameters), array_keys($this->input('method')['implementation']['parameters'])) as $gene) {
            $this->assertSame($parameters[$gene], $compiled['parameters'][$gene], $gene);
        }
        $this->assertSame([], $compiled['runtime_recipe']['operators']);
        $this->assertCount(1, $compiled['runtime_recipe']['parameter_adapters']);
        $this->assertFalse($compiled['qualification_granted']);
        $this->assertFalse($compiled['promotion_evidence']);
        $familyMismatch = $this->compile($this->parameters('breakout'), 'breakout');
        $this->assertSame('METHOD_RECIPE_ADAPTER_FAMILY_MISMATCH', $familyMismatch['reason']);
        unset($parameters['partial_target_atr_multiplier']);
        $this->assertSame('METHOD_RECIPE_ADAPTER_BASELINE_GENE_REQUIRED', $this->compile($parameters)['reason']);

        // A private negative guard fixture is not another external method or agent proposal.
        $library = app(InstrumentMethodLibraryService::class);
        $input = $this->input('method');
        $input['method_key'] = 'software_guard_risk_increase';
        $input['instrument_key'] = 'high_volatility_firewall';
        $input['implementation']['parameters'] = ['high_volatility_risk_multiplier' => 0.75];
        $guardMethod = $library->registerMethod($input);
        $this->assertSame('registered_research_only', $guardMethod['status'], json_encode($guardMethod));
        $guardRecipe = $library->registerRecipe(['recipe_key' => 'software_guard_risk_increase_recipe',
            'revision' => 1, 'method_hashes' => [$guardMethod['method_hash']],
            'context_predicate' => $input['context_predicate']]);
        $guardParameters = $this->parameters('ema_rsi');
        $guardParameters['high_volatility_risk_multiplier'] = 0.5;
        $refused = $library->compileRecipe($guardRecipe['recipe_hash'],
            ['strategy_family' => 'ema_rsi', 'parameters' => $guardParameters], $this->scope());
        $this->assertSame('METHOD_RISK_LIMIT_WEAKENING_REFUSED', $refused['reason']);
        $this->assertFalse($refused['qualification_granted']);
        $this->assertDatabaseCount('agent_method_policy_revisions', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_php_compiled_parameters_drive_one_native_partial_and_the_owned_runner_on_synthetic_prices(): void
    {
        $this->register();
        $compiled = $this->compile($this->parameters('ema_rsi'));
        $this->assertSame('parameter_adapter_recipe', $compiled['status'], json_encode($compiled));
        $script = <<<'PY'
import json,sys
from unittest.mock import patch
import pandas as pd
from app.schemas import SimpleBacktestRequest,ExecutionConfig
from app.services import backtester

parameters=json.load(sys.stdin)
payload=SimpleBacktestRequest(strategy='ema_rsi_v1',timeframe='M5',parameters=parameters,
    risk_per_trade=1.,initial_balance=10000.,evaluation_mode='incremental',
    emit_trade_ledger=True,emit_decision_trace=True,
    execution=ExecutionConfig(commission_percent=.01,swap_per_day_percent=.002,max_leverage=5.))
physical_before=(payload.risk_per_trade,payload.initial_balance,payload.execution.model_dump())

# Synthetic fixed-entry software fixture, not a strategy backtest or market evidence.
frame=pd.DataFrame(dict(time=pd.date_range('2025-01-06T00:00:00Z',periods=207,freq='5min'),
    open=[100.]*207,high=[100.5]*207,low=[99.5]*207,close=[100.]*207,
    volume=[0.]*207,volume_available=[False]*207))
frame.loc[200,['high','low','close']]=[101.6,99.5,101.2]
frame.loc[201,['open','high','low','close']]=[101.2,102.,101.2,101.8]
frame.loc[202,['open','high','low','close']]=[101.8,103.1,101.5,102.8]
def fixture_signal(data,_parameters):
    data=data.copy();data['signal']='WAIT';data.loc[199,'signal']='BUY'
    data['signal_confidence']=1.
    return data
hits=[]
native_partial=backtester._take_partial_profit
def observed_partial(position,candle,request):
    accepted=native_partial(position,candle,request)
    if accepted:
        hits.append(dict(price=position['partial_exit_price'],fraction=position['partial_fraction'],
            size=position['position_size_multiple'],atr=float(candle['_management_atr'])))
    return accepted
with patch('app.services.backtester.get_strategy',return_value=fixture_signal), \
        patch('app.services.backtester._take_partial_profit',side_effect=observed_partial):
    response=backtester.run_simple_ema_rsi_backtest_on_dataframe(payload,frame,
        include_differential_pair=False,lightweight=True)
assert response.total_trades == 1 and len(response.trade_ledger) == 1
trade=response.trade_ledger[0]
assert len(hits)==1 and hits[0]['fraction']==.5
assert abs(hits[0]['price']-(100.+hits[0]['atr'])) < 1e-10
assert trade.exit_reason == 'partial_target+intrabar_target',trade.exit_reason
assert abs(trade.position_size_multiple-hits[0]['size']) < 1e-5
assert physical_before==(payload.risk_per_trade,payload.initial_balance,payload.execution.model_dump())
assert abs(response.final_balance-10000.*(1.+trade.profit_percent/100.)) <= .10

# Native target depends on the evaluated candle ATR, not an entry-frozen R.
def position(direction='BUY'):
    return dict(direction=direction,market_entry_price=100.,entry_price=100.,
        stop_loss=95. if direction=='BUY' else 105.,partial_fraction=.5,
        partial_closed=False,partial_exit_price=None)
target_candle=pd.Series(dict(high=101.5,low=98.5,_management_atr=1.))
buy=position();assert native_partial(buy,target_candle,payload)
assert not native_partial(buy,target_candle,payload)
wide_atr_candle=target_candle.copy();wide_atr_candle['_management_atr']=2.
assert not native_partial(position(),wide_atr_candle,payload)
sell=position('SELL');assert native_partial(sell,target_candle,payload)
assert sell['partial_exit_price']==99.

# Trail is a preceding-close ratchet even before a partial, and never loosens.
runner=position();backtester._advance_trailing_stop(runner,pd.Series(dict(close=104.,_management_atr=1.)),payload)
assert runner['stop_loss']==102. and runner['partial_closed'] is False
backtester._advance_trailing_stop(runner,pd.Series(dict(close=103.,_management_atr=2.)),payload)
assert runner['stop_loss']==102.
short=position('SELL');backtester._advance_trailing_stop(short,pd.Series(dict(close=96.,_management_atr=1.)),payload)
assert short['stop_loss']==98.
print(json.dumps(dict(software_fixture=True,partial_hits=len(hits),fraction=hits[0]['fraction'],
    exit_reason=trade.exit_reason,physical_controls_preserved=True,entry_frozen_r=False,
    realized_cash_partial_ledger=False)))
PY;
        $process = new Process([env('PYTHON_BINARY', 'python'), '-B', '-c', $script], dirname(base_path()).'/ai-service-python');
        $process->setInput(json_encode($compiled['parameters'], JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        $process->setTimeout(60);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
        $this->assertTrue($result['software_fixture']);
        $this->assertSame(1, $result['partial_hits']);
        $this->assertSame(0.5, $result['fraction']);
        $this->assertSame('partial_target+intrabar_target', $result['exit_reason']);
        $this->assertTrue($result['physical_controls_preserved']);
        $this->assertFalse($result['entry_frozen_r']);
        $this->assertFalse($result['realized_cash_partial_ledger']);
        $this->assertDatabaseCount('agent_method_policy_revisions', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    private function register(): void
    {
        foreach (self::HASHES as $action => $hash) {
            $exit = Artisan::call('trading:instrument-methods', ['action' => $action,
                '--input' => $this->path($action), '--json' => true]);
            $printed = Artisan::output();
            $this->assertSame(0, $exit, $printed);
            $result = json_decode($printed, true, 128, JSON_THROW_ON_ERROR);
            $this->assertSame($hash, $result[$action === 'source' ? 'source_key' : $action.'_hash']);
            $this->assertFalse($result['qualification_granted']);
            $this->assertFalse($result['promotion_evidence']);
            if ($action === 'source') $this->assertArrayNotHasKey('content', $result);
        }
    }

    private function compile(array $parameters, string $family = 'ema_rsi'): array
    {
        return app(InstrumentMethodLibraryService::class)->compileRecipe(self::HASHES['recipe'],
            ['strategy_family' => $family, 'parameters' => $parameters], $this->scope());
    }

    private function scope(): array
    {
        return ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'pre_2026_only' => true,
            'data_hash' => str_repeat('a', 64), 'execution_hash' => str_repeat('b', 64),
            'context_predicate' => $this->input('method')['context_predicate']];
    }

    private function parameters(string $family): array
    {
        $schema = app(StrategyParameterSchemaService::class);
        return array_intersect_key($schema->defaults($family), $schema->schema($family));
    }

    private function input(string $action): array
    {
        return json_decode(file_get_contents($this->path($action)), true, 64, JSON_THROW_ON_ERROR);
    }

    private function path(string $action): string
    {
        return resource_path('research/instrument-methods/native-single-partial-runner/'.$action.'.json');
    }
}
