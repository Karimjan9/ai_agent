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

/** Software construction/transport only, not an observed useful instrument. */
class CuratorContextPracticeTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_is_read_only_exact_scopes_and_explicit_incomplete_market_evidence(): void
    {
        $owner = app(CuratedInstrumentMethodCatalogService::class);
        $catalog = $owner->contextPracticeCatalog();
        $this->assertSame('context_practice_catalog', $catalog['status']);
        $this->assertCount(24, $catalog['methods']);
        $this->assertCount(2, $catalog['sources']);
        $this->assertSame(0, DB::table('instrument_method_sources')->count());
        $scopes = [];
        foreach ($catalog['methods'] as $key => $row) {
            $input = $row['method_input'];
            $this->assertSame('confirmation', $input['implementation']['target']);
            $this->assertSame('regime_router', $input['instrument_key']);
            $this->assertSame($key, $input['method_key']);
            $this->assertSame(1, $input['revision']);
            $this->assertSame($row['style'] === 'strong', isset($input['implementation']['input_bindings']['strong']));
            $this->assertSame('method_dmi_available', $input['implementation']['input_bindings']['available']);
            $scopes[$row['style']][] = $input['context_predicate'];
        }
        $this->assertEqualsCanonicalizing($scopes['dmi'], $scopes['strong']);
        foreach (['own_agent_outcome', 'independent_validation', 'credit', 'qualification_granted',
            'economic_authority', 'paper_authority', 'live_authority'] as $flag) $this->assertFalse($catalog[$flag]);
        $this->assertFalse($catalog['design']['panel']['economic_ranking_or_autopromotion']);
    }

    public function test_append_only_install_is_idempotent_and_not_an_agent_policy_or_dispatch(): void
    {
        $owner = app(CuratedInstrumentMethodCatalogService::class);
        $one = $owner->installContextPractice();
        $this->assertSame('context_practice_installed', $one['status'], json_encode($one));
        $two = $owner->installContextPractice();
        $this->assertSame($one, $two);
        $this->assertSame(24, DB::table('instrument_method_revisions')->count());
        $this->assertSame(24, DB::table('instrument_recipe_revisions')->count());
        $this->assertSame(2, DB::table('instrument_method_sources')->count());
        $this->assertSame(0, DB::table('agent_method_policy_revisions')->count());
        $this->assertSame(0, DB::table('model_versions')->count());
        $this->assertSame(0, DB::table('lab_generations')->count());
    }

    public function test_existing_policy_compiler_handles_exact_multi_scope_pool_without_genome_change(): void
    {
        $installed = app(CuratedInstrumentMethodCatalogService::class)->installContextPractice();
        $this->assertSame('context_practice_installed', $installed['status'], json_encode($installed));
        $rows = array_values(array_filter($installed['methods'], fn ($row) => $row['style'] === 'strong'
            && $row['contract']['context_predicate']['session'] === 'new_york'));
        $this->assertCount(4, $rows);
        $recipes = array_column($rows, 'recipe_hash');
        $rules = array_map(fn ($row) => ['context_predicate' => $row['contract']['context_predicate'],
            'preferred_recipes' => [$row['recipe_hash']]], $rows);
        $key = 'synthetic-context-compiler';
        $policy = app(AgentMethodLearningService::class)->registerPolicy(['agent_key' => $key, 'revision' => 1,
            'recipe_hashes' => $recipes, 'rules' => $rules, 'default_action' => 'BASELINE']);
        $this->assertSame('registered_research_only', $policy['status'], json_encode($policy));
        $schema = app(StrategyParameterSchemaService::class);
        $parameters = array_intersect_key($schema->defaults('ema_rsi'), $schema->schema('ema_rsi'));
        $contract = app(InstrumentMethodResearchBindingService::class)->compilePolicy($policy['policy_hash'],
            ['strategy_family' => 'ema_rsi', 'parameters' => $parameters],
            ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'data_hash' => str_repeat('a', 64),
                'execution_hash' => str_repeat('b', 64), 'evaluated_start' => '2025-10-13T00:00:00Z',
                'evaluated_end' => '2025-10-17T20:55:00Z', 'context' => []], $key);
        $this->assertSame($parameters, $contract['runtime_parameters']);
        $this->assertCount(4, $contract['recipes']);
        foreach ($contract['recipes'] as $recipe) {
            $this->assertSame([], $recipe['parameter_adapters']);
            $this->assertSame('new_york', $recipe['context_predicate']['session']);
        }
        $this->assertSame('BASELINE', $contract['policy']['default_action']);
        $this->assertFalse($contract['economic_authority']);

        // Execute the actual PHP-compiled policy and typed AST in Python, with
        // labelled synthetic observations. This is not a market replay.
        $python = dirname(base_path()).DIRECTORY_SEPARATOR.'ai-service-python';
        $code = <<<'PY'
import json,sys
import pandas as pd
from app.services.instrument_method_learning import InstrumentMethodLearning
c=json.load(sys.stdin)
n=200
p=[100.0+i*.2 for i in range(n)]
f=pd.DataFrame({'time':pd.date_range('2025-10-13T00:00:00Z',periods=n,freq='5min'),
    'open':p,'high':[v+.3 for v in p],'low':[v-.1 for v in p],'close':p})
f['market_regime']='trend_up'; f['market_session']='new_york'; f['volatility_regime']='normal_volatility'
o=InstrumentMethodLearning(c,expected_agent_key=c['expected_agent_key'],dataset_hash='a'*64,execution_hash='b'*64)
o.validate_frame(f)
r=f.iloc[-1].to_dict(); t=(r['time']+pd.Timedelta(minutes=5)).isoformat()
buy=o.evaluate(r,decision_id='synthetic-buy',phase='entry',decision_at=t,observed_at=t,direction='BUY')
sell=o.evaluate(r,decision_id='synthetic-sell',phase='entry',decision_at=t,observed_at=t,direction='SELL')
r['market_session']='asia'
outside=o.evaluate(r,decision_id='synthetic-outside',phase='entry',decision_at=t,observed_at=t,direction='BUY')
print(json.dumps({'buy':buy['outputs'],'sell':sell['outputs'],'outside_reason':outside['reason'],
 'outside_used':outside['used'],'economic_authority':buy['economic_authority']}))
PY;
        $process = new Process(['python', '-c', $code], $python,
            ['PYTHONPATH' => $python, 'PYTHONDONTWRITEBYTECODE' => '1', 'OMP_NUM_THREADS' => '1'],
            json_encode($contract, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), 45);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
        $actual = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($actual['buy']['confirmation']);
        $this->assertFalse($actual['sell']['confirmation']);
        $this->assertSame('policy_default_baseline', $actual['outside_reason']);
        $this->assertFalse($actual['outside_used']);
        $this->assertFalse($actual['economic_authority']);
    }

    public function test_source_drift_is_refused_by_original_owner_and_cannot_be_copied_as_confirmation(): void
    {
        $owner = app(CuratedInstrumentMethodCatalogService::class);
        $installed = $owner->installContextPractice();
        $this->assertSame('context_practice_installed', $installed['status'], json_encode($installed));
        $key = $installed['catalog']['source_keys']['stockcharts_directional_movement'];
        DB::table('instrument_method_sources')->where('source_key', $key)->update(['content' => 'replacement']);
        $result = $owner->installContextPractice();
        $this->assertSame('blocked', $result['status']);
        $this->assertSame('CONTEXT_PRACTICE_ORIGINAL_SOURCE_REQUIRED', $result['reason']);
        $this->assertFalse($result['qualification_granted']);
        $this->assertFalse($result['credit']);
        $method = array_values($installed['methods'])[0];
        $this->assertSame('blocked', app(InstrumentMethodLibraryService::class)->inspectMethod($method['method_hash'])['status']);
    }
}
