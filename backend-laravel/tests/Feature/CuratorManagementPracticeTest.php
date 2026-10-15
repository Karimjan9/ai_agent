<?php

namespace Tests\Feature;

use App\Services\AgentMethodLearningService;
use App\Services\CuratedInstrumentMethodCatalogService;
use App\Services\InstrumentMethodLibraryService;
use App\Services\InstrumentMethodResearchBindingService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Construction and source/transport checks, not market or earned skill evidence. */
class CuratorManagementPracticeTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_is_read_only_and_contains_exactly_thirty_two_full_exit_scopes(): void
    {
        $catalog = app(CuratedInstrumentMethodCatalogService::class)->managementPracticeCatalog();
        $this->assertSame('management_practice_catalog', $catalog['status'], json_encode($catalog));
        $this->assertSame('curator_management_lifecycle_20261015_v1', $catalog['key']);
        $this->assertCount(32, $catalog['methods']);
        $this->assertCount(2, $catalog['sources']);
        $this->assertSame(0, DB::table('instrument_method_sources')->count());
        $scopes = [];
        foreach ($catalog['methods'] as $key => $row) {
            $input = $row['method_input']; $implementation = $input['implementation'];
            $scope = $input['context_predicate'];
            $this->assertSame("curator_chandelier22x3_{$scope['regime']}_{$scope['session']}_{$scope['volatility']}_v1", $key);
            $this->assertSame($key, $input['method_key']);
            $this->assertSame('cost_aware_exit', $input['instrument_key']);
            $this->assertSame('hour', $input['horizon']);
            $this->assertSame(1, $input['revision']);
            $this->assertSame('typed_ast', $implementation['kind']);
            $this->assertSame('exit', $implementation['target']);
            $this->assertSame(['available' => 'method_chandelier_available', 'effect' => 'method_chandelier_exit'], $implementation['input_bindings']);
            $this->assertSame('AND', $implementation['ast']['op']);
            $this->assertSame(['available', 'effect'], array_column($implementation['ast']['args'], 'input_key'));
            $this->assertSame(['BOOL', 'BOOL'], array_column($implementation['ast']['args'], 'op'));
            $this->assertSame([$catalog['source_keys']['stockcharts_chandelier_exit']], $input['source_keys']);
            foreach ($scope as $value) $this->assertIsString($value);
            $scopes[] = implode('|', $scope);
        }
        $expected = [];
        foreach (['trend_up', 'trend_down', 'range', 'transition'] as $regime)
            foreach (['asia', 'london', 'overlap', 'new_york'] as $session)
                foreach (['normal', 'high'] as $volatility) $expected[] = "$regime|$session|$volatility";
        $this->assertEqualsCanonicalizing($expected, $scopes);
        $this->assertCount(32, array_unique($scopes));
        foreach (['own_agent_outcome', 'independent_validation', 'credit', 'qualification_granted',
            'economic_authority', 'paper_authority', 'live_authority'] as $flag) $this->assertFalse($catalog[$flag]);
        foreach ($catalog['sources'] as $source) $this->assertLessThanOrEqual(120, str_word_count($source['content']));
        $panel = $catalog['design']['panel'];
        $this->assertCount(4, $panel['windows']);
        $this->assertSame(['control', 'native_atr_ratchet', 'chandelier_close', 'hybrid'], $panel['arms_per_window']);
        $this->assertSame(16, $panel['max_originals']);
        $this->assertSame(600, $panel['per_original_timeout_seconds']);
        $this->assertSame(10400, $panel['global_timeout_seconds']);
        $this->assertSame(1, $panel['max_attempts_per_arm']);
        $this->assertFalse($panel['automatic_retry']);
        $this->assertFalse($panel['economic_ranking_or_autopromotion']);
        $this->assertSame(['control' => 0.0, 'native_atr_ratchet' => 2.0, 'chandelier_close' => 0.0, 'hybrid' => 2.0], $panel['trailing_atr_multiplier_by_arm']);
    }

    public function test_append_only_install_is_idempotent_without_agents_dispatch_or_old_resource_changes(): void
    {
        $oldFiles = array_merge(glob(resource_path('research/instrument-methods/curated-v*.json')),
            glob(resource_path('research/instrument-operating-skills/*-v[1-5].json')));
        $before = array_map(fn ($path) => hash_file('sha256', $path), $oldFiles);
        $owner = app(CuratedInstrumentMethodCatalogService::class);
        $one = $owner->installManagementPractice();
        $this->assertSame('management_practice_installed', $one['status'], json_encode($one));
        $this->assertSame($one, $owner->installManagementPractice());
        $this->assertSame(32, DB::table('instrument_method_revisions')->count());
        $this->assertSame(32, DB::table('instrument_recipe_revisions')->count());
        $this->assertSame(2, DB::table('instrument_method_sources')->count());
        foreach (['agent_method_policy_revisions', 'model_versions', 'lab_generations',
            'research_experiment_work_items', 'lab_evolution_credit_events', 'jobs'] as $table)
            $this->assertSame(0, DB::table($table)->count(), $table);
        $this->assertSame($before, array_map(fn ($path) => hash_file('sha256', $path), $oldFiles));
    }

    public function test_all_thirty_two_recipes_compile_without_physical_change_including_transition(): void
    {
        $installed = app(CuratedInstrumentMethodCatalogService::class)->installManagementPractice();
        $this->assertSame('management_practice_installed', $installed['status'], json_encode($installed));
        $schema = app(StrategyParameterSchemaService::class);
        $parameters = array_intersect_key($schema->defaults('ema_rsi'), $schema->schema('ema_rsi'));
        $library = app(InstrumentMethodLibraryService::class);
        foreach ($installed['methods'] as $row) {
            $compiled = $library->compileRecipe($row['recipe_hash'], ['strategy_family' => 'ema_rsi', 'parameters' => $parameters],
                ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'pre_2026_only' => true, 'data_hash' => str_repeat('a', 64),
                    'execution_hash' => str_repeat('b', 64), 'context_predicate' => $row['contract']['context_predicate']]);
            $this->assertSame('compiled_research_recipe', $compiled['status'], json_encode($compiled));
            $this->assertSame($parameters, $compiled['parameters']);
            $this->assertSame([], $compiled['runtime_recipe']['parameter_adapters']);
            $this->assertCount(1, $compiled['runtime_recipe']['operators']);
            $this->assertSame('exit', $compiled['runtime_recipe']['operators'][0]['target']);
            $this->assertFalse($compiled['qualification_granted']);
        }
        $rows = array_values($installed['methods']);
        // The existing policy owner admits sixteen rules. Each rule retains
        // both exact scalar volatility alternatives; the recipes match one.
        $rules = [];
        foreach ($rows as $row) {
            $scope = $row['contract']['context_predicate'];
            $ruleKey = $scope['regime'].'|'.$scope['session'];
            $rules[$ruleKey] ??= ['context_predicate' => ['regime' => $scope['regime'],
                'session' => $scope['session'], 'volatility' => ['normal', 'high']], 'preferred_recipes' => []];
            $rules[$ruleKey]['preferred_recipes'][] = $row['recipe_hash'];
        }
        $this->assertCount(16, $rules);
        $key = 'synthetic-management-compiler';
        $policy = app(AgentMethodLearningService::class)->registerPolicy(['agent_key' => $key, 'revision' => 1,
            'recipe_hashes' => array_column($rows, 'recipe_hash'), 'rules' => array_values($rules),
            'default_action' => 'BASELINE']);
        $this->assertSame('registered_research_only', $policy['status'], json_encode($policy));
        $contract = app(InstrumentMethodResearchBindingService::class)->compilePolicy($policy['policy_hash'],
            ['strategy_family' => 'ema_rsi', 'parameters' => $parameters],
            ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'data_hash' => str_repeat('a', 64),
                'execution_hash' => str_repeat('b', 64), 'evaluated_start' => '2025-10-13T00:00:00Z',
                'evaluated_end' => '2025-10-17T20:55:00Z', 'context' => []], $key);
        $this->assertCount(32, $contract['recipes']);
        $this->assertSame($parameters, $contract['runtime_parameters']);
        $this->assertSame('BASELINE', $contract['policy']['default_action']);
        $this->assertFalse($contract['economic_authority']);
        $this->assertSyntheticRuntime($contract);
    }

    public function test_original_source_drift_is_refused_without_authority(): void
    {
        $owner = app(CuratedInstrumentMethodCatalogService::class);
        $installed = $owner->installManagementPractice();
        $this->assertSame('management_practice_installed', $installed['status'], json_encode($installed));
        $key = $installed['catalog']['source_keys']['stockcharts_chandelier_exit'];
        DB::table('instrument_method_sources')->where('source_key', $key)->update(['content' => 'replacement']);
        $result = $owner->installManagementPractice();
        $this->assertSame('blocked', $result['status']);
        $this->assertSame('MANAGEMENT_PRACTICE_ORIGINAL_SOURCE_REQUIRED', $result['reason']);
        $this->assertFalse($result['qualification_granted']);
        $this->assertFalse($result['credit']);
        $this->assertSame('blocked', app(InstrumentMethodLibraryService::class)
            ->inspectMethod(array_values($installed['methods'])[0]['method_hash'])['status']);
    }

    private function assertSyntheticRuntime(array $contract): void
    {
        $python = dirname(base_path()).DIRECTORY_SEPARATOR.'ai-service-python';
        $code = <<<'PY'
import json,sys
import pandas as pd
from app.services.instrument_method_learning import InstrumentMethodLearning
c=json.load(sys.stdin)
p=[110.0-i*.5 for i in range(22)]
f=pd.DataFrame({'time':pd.date_range('2025-10-13T00:00:00Z',periods=22,freq='5min'),
    'open':p,'high':[v+.3 for v in p],'low':[v-.1 for v in p],'close':p})
f['market_regime']='transition';f['market_session']='asia';f['volatility_regime']='normal_volatility'
o=InstrumentMethodLearning(c,expected_agent_key=c['expected_agent_key'],dataset_hash='a'*64,execution_hash='b'*64)
o.validate_frame(f)
r=f.iloc[-1].to_dict();t=(r['time']+pd.Timedelta(minutes=5)).isoformat()
entry=o.evaluate(r,decision_id='entry',phase='entry',decision_at=t,observed_at=t,direction='BUY')
buy=o.evaluate(r,decision_id='buy',phase='exit',decision_at=t,observed_at=t,direction='BUY')
sell=o.evaluate(r,decision_id='sell',phase='exit',decision_at=t,observed_at=t,direction='SELL')
r['volatility_regime']='low_volatility'
low=o.evaluate(r,decision_id='low',phase='exit',decision_at=t,observed_at=t,direction='BUY')
r['volatility_regime']=None
missing=o.evaluate(r,decision_id='missing',phase='exit',decision_at=t,observed_at=t,direction='BUY')
print(json.dumps({'entry':entry['outputs'],'buy':buy['outputs'],'sell':sell['outputs'],
    'low_used':low['used'],'low_reason':low['reason'],'missing_used':missing['used'],
    'missing_reason':missing['reason'],'economic_authority':buy['economic_authority']}))
PY;
        $process = new Process(['python', '-B', '-c', $code], $python,
            ['PYTHONPATH' => $python, 'PYTHONDONTWRITEBYTECODE' => '1', 'OMP_NUM_THREADS' => '1'],
            json_encode($contract, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), 45);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
        $actual = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([], $actual['entry']);
        $this->assertTrue($actual['buy']['exit']);
        $this->assertFalse($actual['sell']['exit']);
        foreach (['low', 'missing'] as $context) {
            $this->assertFalse($actual[$context.'_used']);
            $this->assertSame('policy_default_baseline', $actual[$context.'_reason']);
        }
        $this->assertFalse($actual['economic_authority']);
    }
}
