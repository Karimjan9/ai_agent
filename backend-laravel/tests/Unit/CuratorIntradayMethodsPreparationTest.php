<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 2).'/app/Services/ResearchPaperEpochContractService.php';

/** Actual prospective PHP compilation; no replay, market outcomes or production DB. */
class CuratorIntradayMethodsPreparationTest extends TestCase
{
    private static array $draft;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 3);
        $process = new Process([PHP_BINARY, $root.'/backend-laravel/scripts/prepare-curator-intraday-methods.php',
            '--windows', $root.'/.runtime/intraday-replication-final-windows-20261016/provenance-manifest.json',
            '--windows-sha256', 'd8aa2d60c3cc4fbcb0bb3f2768dd7cc6b66e54d03e16f41056c7fae174b7fd9a'], $root,
            ['APP_ENV' => 'production', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'must_not_connect',
                'DB_URL' => 'mysql://invalid:invalid@127.0.0.1:1/must_not_connect'], null, 120);
        $process->run();
        if (! $process->isSuccessful()) throw new \RuntimeException($process->getErrorOutput().$process->getOutput());
        self::$draft = json_decode($process->getOutput(), true, 256, JSON_THROW_ON_ERROR);
    }

    public function test_actual_memory_only_compile_and_finite_context_owner_proof(): void
    {
        $draft = self::$draft; $proof = $draft['recipe_declaration'];
        $this->assertSame('unfrozen_curator_instrument_panel_v1', $draft['protocol']);
        $this->assertSame('intraday_methods_v1', $draft['study_owner']);
        $this->assertSame('complete-intraday-methods-20261016-v1', $draft['question_id']);
        $this->assertSame(':memory:', $proof['database']);
        $this->assertFalse($proof['dotenv_loaded']); $this->assertFalse($proof['application_bootstrap_loaded']);
        $this->assertSame(['instrument_method_sources' => 1, 'instrument_method_revisions' => 18,
            'instrument_recipe_revisions' => 18, 'agent_method_policy_revisions' => 1,
            'research_instrument_programs' => 36, 'agent_method_experiences' => 0, 'instrument_method_studies' => 0],
            $proof['registry_counts']);
        $this->assertCount(18, $proof['contexts']);
        foreach ($proof['contexts'] as $context) {
            $this->assertSame(['regime', 'session', 'volatility'], array_keys($context));
            $this->assertContains($context['regime'], ['trend_up', 'trend_down', 'range']);
            $this->assertContains($context['session'], ['london', 'overlap']);
            $this->assertContains($context['volatility'], ['low', 'normal', 'high']);
        }
        foreach ($proof['loaded_owners'] as $class => $owner) {
            $this->assertStringStartsWith(str_replace('\\', '/', dirname(__DIR__, 2)).'/app/', str_replace('\\', '/', $owner['path']));
            $this->assertSame($owner['sha256'], hash_file('sha256', $owner['path']), $class);
        }
        foreach ($proof['migration_sources'] as $source) $this->assertSame($source['sha256'], hash_file('sha256', $source['path']));
        $this->assertSame('BASELINE', $proof['finite_unknown_action']);
        $this->assertSame(['london', 'overlap'], $proof['session_domain']);
        foreach (['own_agent_outcome', 'independent_validation', 'credit', 'mastery',
            'economic_authority', 'paper_authority', 'live_authority'] as $authority) $this->assertFalse($draft[$authority]);
    }

    public function test_exact_twelve_carrier_wire_identities_and_unchanged_account_costs(): void
    {
        $draft = self::$draft;
        $this->assertSame(['june2025', 'september2025'], array_keys($draft['windows']));
        $expected = []; $costs = [];
        foreach (['june2025', 'september2025'] as $window) {
            foreach (['bb_control', 'bb_recipe', 'ema_control', 'ema_structural', 'retest_control', 'retest_structural'] as $arm)
                $expected[] = $window.'__'.$arm;
        }
        $this->assertSame($expected, array_keys($draft['requests'])); $this->assertCount(6, $draft['baselines']);
        foreach ($draft['requests'] as $key => $case) {
            $request = $case['request']; $window = $draft['windows'][$case['window']];
            $this->assertSame($case['window'].'__'.$case['arm'], $key);
            $this->assertSame($case['window'].'__'.$case['method'].'_control', $case['baseline_key']);
            $this->assertSame($case['window'].'__'.$case['method'], $case['baseline_id']);
            $this->assertSame($request, json_decode($case['request_json'], true, 256, JSON_THROW_ON_ERROR));
            $this->assertSame($case['request_sha256'], hash('sha256', $case['request_json']));
            $this->assertSame($case['request_semantic_hash'], (new \App\Services\ResearchPaperEpochContractService())->parameterHash($request));
            $this->assertSame($window['path'], $request['dataset_path']);
            $this->assertSame($window['sha256'], $case['dataset_sha256']);
            $this->assertSame($window['sha256'], $request['replay_dataset_hash']);
            $this->assertSame($request['execution'], $request['execution_contract']['parameters']);
            $this->assertSame($case['cost_sha256'], $request['execution_contract']['execution_hash']);
            $this->assertSame(['7-14'], $request['execution']['allowed_sessions_utc']);
            $this->assertSame(10000.0, $request['initial_balance']); $this->assertSame(1.0, $request['risk_per_trade']);
            foreach (['time_stop_candles' => 6, 'minimum_signal_confidence' => 0.35, 'atr_stop_multiplier' => 1.5,
                'atr_target_multiplier' => 2.5, 'trailing_atr_multiplier' => 0.0, 'partial_take_profit_fraction' => 0.0] as $name => $value)
                $this->assertSame($value, $request['parameters'][$name]);
            $costs[] = $case['cost_sha256'];
        }
        $this->assertCount(1, array_unique($costs));
        $this->assertSame(12, $draft['predeclared_arms']);
        $this->assertSame(1500, $draft['per_original_timeout_seconds']);
        $this->assertSame(10800, $draft['global_timeout_seconds']);
        $this->assertSame(1, $draft['max_attempts_per_arm']); $this->assertTrue($draft['serial_execution']);
        $this->assertSame('below_normal_hidden', $draft['process_priority']);
        foreach ($draft['upstream'] as $source) $this->assertSame($source['sha256'], hash_file('sha256', $source['path']));
    }

    public function test_bollinger_retains_exact_control_and_actual_canonical_bundle_per_data_scope(): void
    {
        $hashes = [];
        foreach (['june2025', 'september2025'] as $window) {
            $control = self::$draft['requests'][$window.'__bb_control']['request'];
            $treated = self::$draft['requests'][$window.'__bb_recipe']['request'];
            $this->assertSame(self::$draft['baselines'][$window.'__bb'], $control);
            $this->assertSame('session_mean_reversion_v1', $control['strategy']);
            $this->assertSame('session_mean_reversion', $control['base_strategy']);
            $this->assertSame('7a9d42b6719845fa4acf0624e5443546049e1d3af663ec8bc264806c3fb35e49',
                (new \App\Services\ResearchPaperEpochContractService())->parameterHash($control['parameters']));
            $this->assertArrayNotHasKey('session_band_variance', $control['parameters']);
            $this->assertSame('population', $treated['parameters']['session_band_variance']);
            $restored = $treated; unset($restored['parameters']['session_band_variance'], $restored['policy_context']);
            $this->assertSame($control, $restored);
            $contract = $treated['policy_context']['instrument_method_learning'];
            $this->assertSame('curator-bollinger-complete-recipe-v1', $contract['expected_agent_key']);
            $this->assertSame($treated['replay_dataset_hash'], $contract['data_hash']);
            $this->assertCount(18, $contract['recipes']); $this->assertCount(9, $contract['policy']['rules']);
            $this->assertSame('BASELINE', $contract['policy']['default_action']);
            foreach ($contract['recipes'] as $recipe) {
                $this->assertSame([], $recipe['parameter_adapters']); $this->assertCount(1, $recipe['operators']);
                $operator = $recipe['operators'][0];
                $this->assertSame('exit', $operator['target']); $this->assertSame('AND', $operator['ast']['op']);
                $this->assertSame(['available' => 'method_midline20_available', 'effect' => 'method_midline20_exit'], $operator['input_bindings']);
                $this->assertSame($operator['contract_hash'], (new \App\Services\ResearchPaperEpochContractService())->parameterHash(
                    json_decode($operator['contract_json'], true, 256, JSON_THROW_ON_ERROR)));
            }
            $hashes[] = $contract['contract_hash'];
        }
        $this->assertCount(2, array_unique($hashes));
    }

    public function test_new_methods_have_exact_only_declared_programme_differences(): void
    {
        foreach (['june2025', 'september2025'] as $window) {
            foreach (['ema' => ['trend_v1', 'trend'], 'retest' => ['trend_retest_v1', 'trend_retest']] as $family => [$strategy, $base]) {
                $control = self::$draft['requests'][$window.'__'.$family.'_control']['request'];
                $treated = self::$draft['requests'][$window.'__'.$family.'_structural']['request'];
                $this->assertSame(self::$draft['baselines'][$window.'__'.$family], $control);
                $this->assertSame($strategy, $control['strategy']); $this->assertSame($base, $control['base_strategy']);
                $this->assertArrayNotHasKey('lookback', $control['parameters']);
                $this->assertArrayNotHasKey('policy_context', $treated);
                $this->assertArrayNotHasKey('intraday_exit_variant', $control['parameters']);
                $this->assertSame('closed_setup_2r_v1', $treated['parameters']['intraday_exit_variant']);
                $this->assertSame($family === 'ema' ? 'source_ema_pretrend_pullback_v1' : 'frozen', $control['parameters']['entry_topology_variant']);
                $this->assertSame($family === 'ema' ? 'source_ema_pretrend_pullback_v1' : 'source_frozen_retest_v1', $treated['parameters']['entry_topology_variant']);
                foreach (['ema_fast' => 20, 'ema_slow' => 50, 'rsi_period' => 14, 'trend_strength_min' => 20.0] as $key => $value)
                    $this->assertSame($value, $treated['parameters'][$key]);
                $restored = $treated; unset($restored['parameters']['intraday_exit_variant']);
                $restored['parameters']['entry_topology_variant'] = $control['parameters']['entry_topology_variant'];
                $this->assertSame($control, $restored);
            }
        }
    }

    public function test_actual_python_request_and_bollinger_contract_owners_accept_all_originals_without_replay(): void
    {
        $code = <<<'PY'
import json,sys
from app.schemas import SimpleBacktestRequest
from app.services.instrument_method_learning import InstrumentMethodLearning
from app.services.parameter_schema import validate_strategy_parameters
from app.strategies.registry import get_strategy
from app.strategies.laboratory import apply_session_mean_reversion_strategy,apply_trend_specialist,apply_trend_retest_strategy
draft=json.load(sys.stdin)
seen=[]
for key,case in draft['requests'].items():
    request=SimpleBacktestRequest.model_validate(case['request'])
    assert request.initial_balance==10000 and request.risk_per_trade==1
    assert validate_strategy_parameters(request.strategy,request.parameters,request.base_strategy)==request.parameters
    assert get_strategy(request.strategy,request.base_strategy) is {'bb':apply_session_mean_reversion_strategy,'ema':apply_trend_specialist,'retest':apply_trend_retest_strategy}[case['method']]
    if case['arm']=='bb_recipe':
        contract=request.policy_context['instrument_method_learning']
        owner=InstrumentMethodLearning(contract,request)
        assert owner.body['strategy_family']=='session'
        assert len(owner.recipes)==18 and len(owner.policy['rules'])==9
        changed=request.model_copy(deep=True)
        changed.parameters['session_band_variance']='sample'
        try: InstrumentMethodLearning(contract,changed)
        except ValueError: pass
        else: raise AssertionError('actual parameter drift admitted')
    seen.append(key)
assert len(seen)==12
print(json.dumps({'request_count':len(seen),'bollinger_owner_count':2,'replay_performed':False}))
PY;
        $process = new Process([getenv('PYTHON_BINARY') ?: 'python', '-B', '-c', $code],
            dirname(__DIR__, 3).'/ai-service-python', ['PYTHONDONTWRITEBYTECODE' => '1'],
            json_encode(self::$draft, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), 120);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
        $this->assertSame(['request_count' => 12, 'bollinger_owner_count' => 2, 'replay_performed' => false],
            json_decode($process->getOutput(), true));
    }

    public function test_unregistered_window_identity_refuses_before_bootstrap_or_memory_compilation(): void
    {
        $root = dirname(__DIR__, 3);
        $process = new Process([PHP_BINARY, $root.'/backend-laravel/scripts/prepare-curator-intraday-methods.php',
            '--windows', $root.'/.runtime/intraday-replication-final-windows-20261016/provenance-manifest.json',
            '--windows-sha256', str_repeat('0', 64)], $root,
            ['DB_CONNECTION' => 'mysql', 'DB_URL' => 'mysql://invalid:invalid@127.0.0.1:1/must_not_connect'], null, 30);
        $process->run();
        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('EXACT_FIXED_TWO_MONTH_MANIFEST_REQUIRED', $process->getErrorOutput().$process->getOutput());
        $this->assertStringNotContainsString('SQLSTATE', $process->getErrorOutput().$process->getOutput());
    }
}
