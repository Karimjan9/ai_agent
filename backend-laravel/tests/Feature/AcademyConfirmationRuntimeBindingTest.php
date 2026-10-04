<?php
namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Services\AcademyExperimentContractCompilerService;
use App\Services\CompositionAuthorityKernelService;
use App\Services\ExecutionContractService;
use App\Services\LabAgentEvaluationService;
use App\Services\LabInstrumentResearchService;
use App\Services\MultiTimeframeSnapshotService;
use App\Services\StrategyLibraryCompilerService;
use App\Services\StrategyParameterSchemaService;
use App\Services\TradeManagementLibraryService;
use App\Services\UniversalAgentCapabilityService;
use App\Console\Commands\DispatchLabGeneration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AcademyConfirmationRuntimeBindingTest extends TestCase
{
    use RefreshDatabase;

    private function archived(): array
    {
        return json_decode(file_get_contents(getenv('ACADEMY_CONFIRMATION_FIXTURE') ?: base_path('tests/Support/academy_confirmation_source.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    private function identity(): array
    {
        return ['data_hash' => str_repeat('b', 64),
            'execution_hash' => app(ExecutionContractService::class)->for('XAUUSD', 'M5')['execution_hash'],
            'mtf_bundle_manifest' => ['protocol' => MultiTimeframeSnapshotService::PROTOCOL,
                'bundle_hash' => str_repeat('b', 64), 'streams' => collect(['M5', 'M15', 'H1', 'H4'])
                    ->mapWithKeys(fn ($timeframe) => [$timeframe => ['sha256' => hash('sha256', $timeframe)]])->all()]];
    }

    public function test_actual_archived_parameters_get_new_exact_runtime_not_a_trend_alias(): void
    {
        $source = $this->archived();
        $metadata = app(CompositionAuthorityKernelService::class)->confirmationReplayMetadata($source['metadata'], $this->identity());
        $this->assertSame('confirmation_entry_mtf_v1', $metadata['base_strategy']);
        $this->assertSame('confirmation_entry_mtf', $metadata['strategy_architecture']);
        $this->assertSame('confirmation_entry_mtf', $metadata['tactic_contract']['architecture']);
        $this->assertSame('str_001_ema_adx_pullback', data_get($source, 'metadata.smart_composition.composition_passport.components.strategy_id'));
        $this->assertSame('str_042_confirmation_entry_mtf', data_get($metadata, 'smart_composition.composition_passport.components.strategy_id'));
        $this->assertFalse(data_get($metadata, 'prospective_confirmation_runtime.parameters_overridden'));
        $this->assertSame('confirmation_entry_mtf_v1', app(StrategyLibraryCompilerService::class)->runtimeBaseStrategy('str_042_confirmation_entry_mtf'));
        $this->assertTrue(data_get($metadata, 'smart_composition.composition_passport.temporal_policy.data_contract.execution_ready'));
    }

    public function test_real_four_compiled_vectors_bind_and_python_preserves_every_economic_parameter(): void
    {
        $source = $this->archived();
        $compiled = app(AcademyExperimentContractCompilerService::class)->compile(['axis' => 'setup_topology_policy', 'arms' => [
            ['role' => 'frozen_control', 'value' => 'frozen_current'],
            ['role' => 'candidate', 'value' => 'breakout_and_retest'],
            ['role' => 'candidate', 'value' => 'liquidity_sweep_reclaim'],
            ['role' => 'blinded_control', 'value' => 'setup_blinded_control'],
        ]], $source['parameters'], ['symbol' => 'XAUUSD', 'laboratory_timeframe' => 'H1', 'execution_timeframe' => 'M5']);
        $this->assertSame('compiled', $compiled['status'], json_encode($compiled));
        // A primary Academy arm is admissible only as part of the real sealed
        // materializer roster, never as a hand-made zero-diff fixture.
        $fixture = new AcademyPrimaryInstrumentSurfaceTest('test_real_twenty_seat_materialization_has_one_exact_common_primary_surface_and_python_preflight');
        $generation = (new \ReflectionMethod(AcademyPrimaryInstrumentSurfaceTest::class, 'cohort'))->invoke($fixture);
        $identity = data_get($generation->trigger_context, 'prospective_source_identity');
        $identity['data_hash'] = $identity['mtf_bundle_hash'];
        $identity['mtf_bundle_manifest']['bundle_hash'] = $identity['mtf_bundle_hash'];
        foreach ($generation->agents()->with('modelVersion')->where('origin', 'academy_experiment')->get() as $agent) {
            $index = (int) data_get($agent->modelVersion->metadata, 'academy_experiment.arm_index');
            $arm = $compiled['arms'][$index];
            $parameters = (array) $agent->modelVersion->parameters;
            $changed = array_keys(array_filter($parameters, fn ($value, $key) => $value !== $source['parameters'][$key], ARRAY_FILTER_USE_BOTH));
            $this->assertSame($arm['role'] === 'candidate' ? ['setup_topology_policy'] : [], $changed);
            $this->assertSame($arm['role'] === 'candidate' ? ['setup_topology_policy'] : [], array_keys($agent->parameter_diff));
            $draft = new \ReflectionMethod(DispatchLabGeneration::class, 'draftIntegrityViolations');
            $this->assertSame([], $draft->invoke(app(DispatchLabGeneration::class), $agent, app(StrategyParameterSchemaService::class)));
            $assignment = app(LabInstrumentResearchService::class)->assignment($agent);
            $builder = new \ReflectionMethod(LabAgentEvaluationService::class, 'compositionRuntimeContract');
            $contract = $builder->invoke(app(LabAgentEvaluationService::class), $agent, $assignment, 'M5', ['bundle_hash' => $identity['data_hash'], 'manifest' => $identity['mtf_bundle_manifest']], $identity['data_hash']);
            foreach (['strategy', 'tactic', 'risk', 'management'] as $component) $this->assertTrue(data_get($contract, 'runtime_bindings.'.$component.'.bound'), $component);
            $this->assertTrue(data_get($contract, 'execution_authority.instrument.bound'));
            $this->assertTrue(data_get($contract, 'execution_authority.mtf.bound'));
            $process = new Process([PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3', '-c', <<<'PYTHON'
import importlib.util, json, os, sys
stage = os.environ.get('ACADEMY_COMPOSITION_STAGE')
if stage:
    spec = importlib.util.spec_from_file_location('app.services.composition_runtime', stage)
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
from app.services.composition_runtime import validate_composition_runtime_contract, effective_management_parameters, CompositionRuntimeContractError
from app.strategies.registry import get_strategy
payload = json.load(sys.stdin)
assert callable(get_strategy('confirmation_entry_mtf_v1'))
validate_composition_runtime_contract(payload['contract'], base_strategy='confirmation_entry_mtf_v1', parameters=payload['parameters'], execution_timeframe='M5', runtime_authority=payload['authority'])
effective = effective_management_parameters(payload['contract'], payload['parameters'])
assert all(effective[key] == value for key, value in payload['parameters'].items())
assert 'composition_final_target_r' not in effective
print('validated-preserved')
PYTHON
            ], base_path('../ai-service-python'));
            $process->setInput(json_encode(['contract' => $contract, 'parameters' => $parameters, 'authority' => ['symbol' => 'XAUUSD', 'execution_timeframe' => 'M5', 'replay_dataset_hash' => $identity['data_hash'], 'execution_hash' => $identity['execution_hash'], 'instrument_assignment' => $assignment, 'mtf_snapshot_manifest' => $identity['mtf_bundle_manifest']]], JSON_THROW_ON_ERROR));
            $process->setTimeout(30)->run();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            $this->assertSame('validated-preserved', trim($process->getOutput()));
        }
    }

    public function test_sealed_mtf_dependency_is_required_before_replacing_any_hypothesis_metadata(): void
    {
        $identity = $this->identity();
        unset($identity['mtf_bundle_manifest']['streams']['H4']);
        $this->expectExceptionMessage('CONFIRMATION_REPLAY_SEALED_MTF_IDENTITY_REQUIRED');
        app(CompositionAuthorityKernelService::class)->confirmationReplayMetadata($this->archived()['metadata'], $identity);
    }

    public function test_existing_profile_remains_unchanged_and_research_adapter_has_no_overrides(): void
    {
        $management = app(TradeManagementLibraryService::class);
        $this->assertSame(.4, $management->runtimeAdapter('balanced_professional')['partial_close_fraction']);
        $this->assertSame(24, $management->runtimeAdapter('balanced_professional')['time_stop_replay_candles']);
        $preserving = $management->runtimeAdapter('parameter_preserving_research');
        $this->assertSame([], $preserving['overrides']);
        $this->assertSame('parameter_preserving_replay_v1', $preserving['engine']);
        $this->assertFalse($preserving['paper_execution_authority']);
        $this->assertNull($management->runtimeAdapter('arbitrary_custom_manager'));
    }

    public function test_explicit_confirmation_strategy_and_manager_do_not_expand_generic_population_search(): void
    {
        $library = app(StrategyLibraryCompilerService::class);
        $eligible = collect($library->library())->reject(fn ($spec) => isset($spec['materialization_owner']))
            ->filter(fn ($spec) => $library->runtime($spec['id']) !== null)->pluck('id')->sort()->values()->all();
        $legacy = ['str_001_ema_adx_pullback', 'str_003_donchian_breakout', 'str_010_bollinger_squeeze',
            'str_020_bb_rsi_reversion', 'str_022_zscore_reversion', 'str_031_bos_retest', 'str_032_choch_reversal',
            'str_037_fvg_retest', 'str_040_asia_london_breakout', 'mix_001_trend_beast', 'mix_002_breakout_beast',
            'mix_003_smc_trend_pullback', 'mix_006_range_killer', 'mix_010_regime_ensemble', 'mix_011_differential_router'];
        sort($legacy);
        $this->assertSame($legacy, $eligible);
        $plan = collect(range(1, 20))->map(fn ($slot) => ['family' => $slot % 2 ? 'hybrid' : 'differential_router',
            'target' => 'portfolio_router', 'niche' => $slot <= 3 ? ['structural_research' => true] : []])->all();
        $planner = app(\App\Services\StrategyTacticRiskCompositionPlannerService::class);
        for ($generation = 0; $generation < count($eligible); $generation++) {
            $result = $planner->materialize($plan, generation: $generation);
            $this->assertTrue(collect($result['plan'])->every(fn ($seat) =>
                data_get($seat, 'niche.composition_passport.components.strategy_id') !== 'str_042_confirmation_entry_mtf'
                && data_get($seat, 'niche.composition_passport.components.management_id') !== 'parameter_preserving_research'));
        }
    }
}
