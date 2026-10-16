<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 2).'/app/Services/ResearchPaperEpochContractService.php';

/** Actual compiler/consumer admission only; no replay, market outcomes or disk DB. */
class BollingerRecipeOfflinePreparationTest extends TestCase
{
    private static array $draft;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 3);
        $process = new Process([PHP_BINARY, $root.'/backend-laravel/scripts/prepare-curator-bollinger-recipe-study.php',
            '--windows', $root.'/.runtime/recipe-october-data-20261016/provenance-manifest.json',
            '--windows-sha256', 'ed1b690d4556fc4524c4b9cedafde9693d9d0e4c677b1fb105f00755e477d23b'], $root,
            // Hostile inherited settings cannot override the single hardcoded
            // SQLite memory connection; no dotenv/bootstrap file is loaded.
            ['APP_ENV' => 'production', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'must_not_connect',
                'DB_URL' => 'mysql://invalid:invalid@127.0.0.1:1/must_not_connect'], null, 60);
        $process->run();
        if (! $process->isSuccessful()) throw new \RuntimeException($process->getErrorOutput().$process->getOutput());
        self::$draft = json_decode($process->getOutput(), true, 256, JSON_THROW_ON_ERROR);
    }

    public function test_actual_owners_compile_only_memory_records_and_fixed_finite_contexts(): void
    {
        $draft = self::$draft; $proof = $draft['recipe_declaration'];
        $this->assertSame('bollinger_recipe_v1', $draft['study_owner']);
        $this->assertSame('bollinger-complete-recipe-20261016-v1', $draft['question_id']);
        $this->assertSame(':memory:', $proof['database']);
        $this->assertFalse($proof['dotenv_loaded']);
        $this->assertFalse($proof['application_bootstrap_loaded']);
        $this->assertSame(['instrument_method_sources' => 1, 'instrument_method_revisions' => 18,
            'instrument_recipe_revisions' => 18, 'agent_method_policy_revisions' => 1,
            'research_instrument_programs' => 18, 'agent_method_experiences' => 0, 'instrument_method_studies' => 0],
            $proof['registry_counts']);
        $this->assertCount(18, $proof['contexts']);
        foreach ($proof['contexts'] as $context) {
            $this->assertSame(['regime', 'session', 'volatility'], array_keys($context));
            $this->assertContains($context['regime'], ['trend_up', 'trend_down', 'range']);
            $this->assertContains($context['session'], ['london', 'overlap']);
            $this->assertContains($context['volatility'], ['low', 'normal', 'high']);
        }
        foreach ($proof['loaded_owners'] as $class => $owner) {
            $this->assertStringContainsString('/backend-laravel/app/', str_replace('\\', '/', $owner['path']));
            $this->assertSame($owner['sha256'], hash_file('sha256', $owner['path']), $class);
        }
        foreach (['own_agent_outcome', 'independent_validation', 'credit', 'mastery',
            'economic_authority', 'paper_authority', 'live_authority'] as $authority) $this->assertFalse($draft[$authority]);
    }

    public function test_only_population_formula_and_canonical_exit_policy_differ(): void
    {
        $control = self::$draft['requests']['october2025__control'];
        $treated = self::$draft['requests']['october2025__population_midline'];
        $this->assertArrayNotHasKey('session_band_variance', $control['request']['parameters']);
        $this->assertSame('population', $treated['request']['parameters']['session_band_variance']);
        $restored = $treated['request'];
        unset($restored['parameters']['session_band_variance'], $restored['policy_context']);
        $this->assertSame($control['request'], $restored);
        $this->assertSame($control['cost_sha256'], $treated['cost_sha256']);
        $this->assertSame(6, $control['request']['parameters']['time_stop_candles']);
        $this->assertSame(2700, self::$draft['per_original_timeout_seconds']);
        $this->assertSame(6000, self::$draft['global_timeout_seconds']);
        foreach ([$control, $treated] as $case) {
            $this->assertSame($case['request'], json_decode($case['request_json'], true, 256, JSON_THROW_ON_ERROR));
            $this->assertSame($case['request_sha256'], hash('sha256', $case['request_json']));
        }
        $contract = $treated['request']['policy_context']['instrument_method_learning'];
        $this->assertSame('curator-bollinger-complete-recipe-v1', $contract['expected_agent_key']);
        $this->assertCount(18, $contract['recipes']); $this->assertCount(9, $contract['policy']['rules']);
        $this->assertSame('BASELINE', $contract['policy']['default_action']);
        foreach ($contract['recipes'] as $recipe) {
            $this->assertSame([], $recipe['parameter_adapters']);
            $this->assertCount(1, $recipe['operators']);
            $operator = $recipe['operators'][0];
            $this->assertSame('exit', $operator['target']);
            $this->assertSame('AND', $operator['ast']['op']);
            $this->assertSame(['available' => 'method_midline20_available', 'effect' => 'method_midline20_exit'],
                $operator['input_bindings']);
            $this->assertSame($operator['contract_hash'], (new \App\Services\ResearchPaperEpochContractService())->parameterHash(
                json_decode($operator['contract_json'], true, 256, JSON_THROW_ON_ERROR)));
        }
    }

    public function test_actual_python_owner_admits_canonical_recross_but_refuses_base_and_parameter_drift(): void
    {
        $code = <<<'PY'
import json,sys
from app.schemas import SimpleBacktestRequest
from app.services.instrument_method_learning import InstrumentMethodLearning
draft=json.load(sys.stdin)
body=draft['requests']['october2025__population_midline']['request']
request=SimpleBacktestRequest.model_validate(body)
contract=request.policy_context['instrument_method_learning']
owner=InstrumentMethodLearning(contract,request)
assert owner.body['strategy_family']=='session'
assert owner.body['runtime_parameters']['session_band_variance']=='population'
assert len(owner.recipes)==18 and len(owner.policy['rules'])==9
for change in ({'session_band_variance':'sample'},{'lookback':21}):
    changed=request.model_copy(deep=True)
    changed.parameters.update(change)
    try: InstrumentMethodLearning(contract,changed)
    except ValueError: pass
    else: raise AssertionError('actual physical parameter drift admitted')
for strategy,base in [('session_v1','session'),('session_mean_reversion_v1','session'),('trend_v1','session_mean_reversion')]:
    changed=request.model_copy(deep=True)
    changed.strategy,changed.base_strategy=strategy,base
    try: InstrumentMethodLearning(contract,changed)
    except ValueError: pass
    else: raise AssertionError('unowned base/strategy admitted')
# A separate old-format synthetic fixture, not a rewritten PHP original,
# verifies the optional runtime-identity branch did not alter legacy default.
sys.path.insert(0,'tests')
from test_instrument_method_learning import learning_contract
from test_source_grounded_method_runtime import owned_recipe,software_request,software_frame
legacy=software_request()
fixture=owned_recipe(legacy,'legacy-noop','exit',{'op':'CONST','type':'bool','value':False})
legacy_contract=learning_contract(legacy,software_frame(),[fixture])
assert 'runtime_parameters' not in legacy_contract
assert InstrumentMethodLearning(legacy_contract,legacy).policy['default_action']=='BASELINE'
print(json.dumps({'admitted':True,'base_spoof_refused':True,'parameter_drift_refused':True,'default_preserved':True}))
PY;
        $process = new Process([getenv('PYTHON_BINARY') ?: 'python', '-B', '-c', $code],
            dirname(__DIR__, 3).'/ai-service-python', ['PYTHONDONTWRITEBYTECODE' => '1'],
            json_encode(self::$draft, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), 60);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
        $this->assertSame(['admitted' => true, 'base_spoof_refused' => true,
            'parameter_drift_refused' => true, 'default_preserved' => true], json_decode($process->getOutput(), true));
    }
}
