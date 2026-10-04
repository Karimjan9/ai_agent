<?php

namespace Tests\Feature;

use App\Models\LabGeneration;
use App\Models\ResearchLoopDecision;
use App\Services\AcademyExperimentMaterializerService;
use App\Services\CandidateGateDecisionService;
use App\Services\GateContractService;
use App\Services\GenerationSnapshotAdmissionService;
use App\Services\LabAgentEvaluationService;
use App\Services\LabDatasetExportService;
use App\Services\LabGenerationContextService;
use App\Services\ProspectiveRepairProbeWindowService;
use App\Services\ResearchLoopArbiterService;
use App\Services\MultiTimeframeSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Real Academy owners; dependency fixtures never assert market/independent authority. */
class AcademyCleanDiscoveryHandoffTest extends TestCase
{
    use RefreshDatabase;

    private array $fixtureDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->fixtureDirectories as $directory) File::deleteDirectory($directory);
        parent::tearDown();
    }

    private function scope(): array
    {
        return ['protocol' => 'prospective_clean_discovery_scope_v1',
            'parent_dataset_key' => 'fixture_corrected_parent', 'parent_fork_price_sha256' => str_repeat('a', 64),
            'parent_economic_rows_sha256' => str_repeat('c', 64), 'selected_price_sha256' => str_repeat('d', 64),
            'calendar' => ['loaded_rows' => 15512, 'evaluated_rows' => 15000, 'warmup_rows' => 512,
                'loaded_start' => '2025-09-01T00:00:00Z', 'loaded_end' => '2025-12-30T00:00:00Z',
                'evaluated_start' => '2025-09-03T00:00:00Z', 'evaluated_end' => '2025-12-30T00:00:00Z',
                'selected_unexpected_gaps' => 0, 'full_source_unexpected_gaps' => 27],
            'independent_evidence' => false, 'full_validation_eligible' => false,
            'paper_eligible' => false, 'promotion_evidence' => false];
    }

    private function configured(array $scope = []): array
    {
        Queue::fake();
        $fixture = new AcademyColdStartHandoffTest('test_old_passport_and_parameters_create_only_a_bounded_fresh_prospective_question');
        [$source, $model] = (new \ReflectionMethod($fixture, 'sourceAndData'))->invoke($fixture);
        $hash = hash('sha256', 'clean-discovery-fixture-'.random_bytes(8));
        $directory = storage_path('app/lab-datasets/mtf/'.$hash);
        File::ensureDirectoryExists($directory); $this->fixtureDirectories[] = $directory;
        $manifest = ['protocol' => MultiTimeframeSnapshotService::PROTOCOL,
            'validation_bundle_protocol' => MultiTimeframeSnapshotService::DISCOVERY_BUNDLE_PROTOCOL,
            'bundle_hash' => $hash, 'data_role' => 'pre_2026_discovery_only', 'entry_last_candle_at' => '2025-12-30T00:00:00Z',
            'discovery_scope' => $scope ?: $this->scope(), 'independent_evidence' => false,
            'full_validation_eligible' => false, 'paper_eligible' => false, 'promotion_evidence' => false,
            'streams' => data_get($source->generation->trigger_context, 'mtf_bundle_manifest.streams')];
        File::put($directory.'/manifest.json', json_encode($manifest));
        config()->set('services.xauusd_organism.clean_discovery_bundle_hash', $hash);
        $owner = Mockery::mock(MultiTimeframeSnapshotService::class)->makePartial();
        $owner->shouldReceive('agentValidationReadiness')->never();
        $owner->shouldReceive('forAgentOwnedConfirmationValidation')->never();
        $owner->shouldReceive('discoveryBundleReadiness')->andReturnUsing(fn (array $candidate): array => [
            'allowed' => $candidate === $manifest, 'ready' => $candidate === $manifest,
            'reason' => 'fixture_selected_bytes_verified', 'discovery_scope' => $manifest['discovery_scope']]);
        $owner->shouldReceive('restoreAgentOwnedConfirmationValidationBundle')->with($manifest, true)->andReturn([
            'bundle_hash' => $hash, 'manifest' => $manifest, 'manifest_path' => $directory.'/manifest.json',
            'entry_dataset_path' => $manifest['streams']['M5']['path'],
            'context_dataset_paths' => collect($manifest['streams'])->except('M5')->map(fn ($s) => $s['path'])->all()]);
        app()->instance(MultiTimeframeSnapshotService::class, $owner);
        return [$source, $model, $manifest];
    }

    private function prepare(): array
    {
        $owner = app(AcademyExperimentMaterializerService::class);
        $proposal = $owner->coldStartProposal();
        $this->assertSame('would_prepare_cold_start', $proposal['status'], json_encode($proposal));
        $decision = ResearchLoopDecision::create(['decision_key' => 'clean-discovery-'.random_int(1, PHP_INT_MAX),
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'action' => 'OPEN_ACADEMY_EXPERIMENT',
            'command' => 'trading:admit-academy-experiment', 'arguments' => ['trial' => 0], 'status' => 'running',
            'evidence_hash' => str_repeat('f', 64), 'reason_codes' => [], 'contract' => [],
            'evidence_snapshot' => ['academy_proposal' => $proposal]]);
        $prepared = $owner->prepareColdStart($decision);
        $this->assertSame('pending_canonical_admission', $prepared['status'], json_encode($prepared));
        return [$proposal, LabGeneration::findOrFail($prepared['generation_id'])];
    }

    public function test_prepared_twenty_seat_discovery_reuses_exact_bundle_and_preserves_parent_budget(): void
    {
        [$source, $model, $manifest] = $this->configured();
        [$proposal, $generation] = $this->prepare();
        $this->assertSame($manifest, data_get($generation->trigger_context, 'mtf_bundle_manifest'));
        $this->assertSame('pre_2026_discovery_only', data_get($generation->trigger_context, 'prospective_source_identity.data_role'));
        $this->assertSame($proposal['cold_start']['budget_scope'], data_get($generation->trigger_context, 'prospective_source_identity.cold_start.sealed_budget_scope'));
        $this->assertSame(20, $generation->agents()->count());
        $this->assertSame(15000, data_get($generation->trigger_context, 'research_experiment_contract.discovery_scope.calendar.evaluated_rows'));
        $this->assertFalse(data_get($generation->trigger_context, 'prospective_source_identity.discovery_scope.independent_evidence'));
        $this->assertSame('ACADEMY_COLD_START_SCOPE_BUDGET_EXHAUSTED', app(AcademyExperimentMaterializerService::class)->coldStartProposal()['reason']);
        // A different slice/quote seal on the same physical parent is not a new allowance.
        $scope = $this->scope(); $scope['selected_price_sha256'] = str_repeat('9', 64);
        $scope['calendar']['loaded_start'] = '2025-08-01T00:00:00Z';
        $budget = new \ReflectionMethod(AcademyExperimentMaterializerService::class, 'dependencyBudgetScope');
        $deps = $proposal['cold_start']['dependencies']; $deps['discovery_scope'] = $scope;
        $this->assertSame($proposal['cold_start']['budget_scope'], $budget->invoke(app(AcademyExperimentMaterializerService::class), 'XAUUSD', 'H1', $deps));
        $deps['discovery_scope']['parent_fork_price_sha256'] = str_repeat('8', 64);
        $this->assertNotSame($proposal['cold_start']['budget_scope'], $budget->invoke(app(AcademyExperimentMaterializerService::class), 'XAUUSD', 'H1', $deps));
        $this->assertSame([], json_decode(DB::table('edge_academy_trials')->where('id', data_get($generation->trigger_context, 'academy_trial_id'))->value('outcome'), true)['arm_observations'] ?? []);
        $this->assertDatabaseCount('evolutionary_authority_ledgers', 0);
        Queue::assertNothingPushed();
    }

    public function test_discovery_restore_is_screening_only_and_full_evaluation_creates_no_run(): void
    {
        $this->configured(); [, $generation] = $this->prepare();
        $agent = $generation->agents()->with('modelVersion', 'generation')->first();
        $evaluation = app(LabAgentEvaluationService::class);
        $restore = new \ReflectionMethod($evaluation, 'replayMtfBundle');
        $bundle = $restore->invoke($evaluation, $agent, false, true);
        $this->assertSame(data_get($generation->trigger_context, 'mtf_bundle_hash'), $bundle['bundle_hash']);
        try { $restore->invoke($evaluation, $agent); $this->fail('full restore should refuse'); }
        catch (\RuntimeException $error) { $this->assertSame('DISCOVERY_ONLY_BUNDLE_REPLAY_SCOPE_FORBIDDEN', $error->getMessage()); }
        try { $evaluation->evaluate($agent); $this->fail('full evaluation should refuse'); }
        catch (\RuntimeException $error) { $this->assertSame('DISCOVERY_ONLY_BUNDLE_FULL_VALIDATION_FORBIDDEN', $error->getMessage()); }
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
    }

    public function test_admission_requires_typed_academy_owner_and_full_dispatch_refuses_the_slice(): void
    {
        [, , $manifest] = $this->configured(); [, $generation] = $this->prepare();
        $reasons = new \ReflectionMethod(GenerationSnapshotAdmissionService::class, 'mtfBundleReasons');
        $admission = app(GenerationSnapshotAdmissionService::class);
        $this->assertSame([], $reasons->invoke($admission, $manifest['bundle_hash'], $manifest, true));
        $this->assertContains('GENERATION_DISCOVERY_BUNDLE_ADMISSION_INVALID', $reasons->invoke($admission, $manifest['bundle_hash'], $manifest));
        $generation->update(['status' => 'screened']); $generation->agents()->update(['lifecycle_status' => 'screened']);
        $gates = Mockery::mock(GateContractService::class)->makePartial(); $gates->shouldReceive('health')->once()->andReturn(['healthy' => true]);
        app()->instance(GateContractService::class, $gates);
        $this->artisan('trading:dispatch-full-validation', ['symbol' => 'XAUUSD', '--timeframe' => 'H1'])
            ->expectsOutputToContain('DISCOVERY_ONLY_BUNDLE_FULL_VALIDATION_FORBIDDEN')->assertExitCode(0);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        $this->assertSame(20, $generation->agents()->where('lifecycle_status', 'screened')->count());
        Queue::assertNothingPushed();
    }

    public function test_invalid_explicit_hash_cannot_fall_back_to_latest_old_bundle(): void
    {
        $this->configured(); config()->set('services.xauusd_organism.clean_discovery_bundle_hash', '../old');
        $this->assertSame('ACADEMY_DISCOVERY_BUNDLE_HASH_INVALID', app(AcademyExperimentMaterializerService::class)->coldStartProposal()['reason']);
        $this->assertDatabaseCount('edge_academy_trials', 0);
    }

    public function test_promising_academy_screen_is_terminal_discovery_not_full_replay_selection(): void
    {
        $this->configured(); [, $generation] = $this->prepare();
        $agent = $generation->agents()->with('modelVersion', 'generation')->first();
        $result = ['total_trades' => 100, 'profit_factor' => 2, 'net_profit_percent' => 10,
            'max_drawdown_percent' => 1, 'monte_carlo' => ['risk_of_ruin_percent' => 0],
            'screening_survival' => ['status' => 'survivor', 'reason_codes' => []]];
        $decision = app(CandidateGateDecisionService::class)->recordScreening($agent, $result);
        $this->assertSame('passed', data_get($decision->metrics, 'scientific_screen_decision'));
        $this->assertSame('failed', $decision->decision);
        $this->assertContains('ACADEMY_RESEARCH_ONLY', $decision->reason_codes);
        $this->assertFalse(data_get($decision->metrics, 'promotion_evidence'));
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        $this->assertSame(0, $generation->agents()->where('lifecycle_status', 'full_queued')->count());
        Queue::assertNothingPushed();
    }

    public function test_single_discovery_job_transport_and_mutex_cover_the_existing_bounded_python_deadline(): void
    {
        $this->configured(); [, $generation] = $this->prepare();
        $agent = $generation->agents()->first();
        $job = new \App\Jobs\EvaluateLabAgentJob($agent->id, 'XAUUSD', 'screen');
        $this->assertTrue($job->prospectiveProbe);
        $this->assertSame(2100, $job->timeout);
        $lock = collect($job->middleware())->first(fn ($m) => $m instanceof \Illuminate\Queue\Middleware\WithoutOverlapping);
        $this->assertSame(2700, $lock->expiresAfter);
        $timeout = new \ReflectionMethod(LabAgentEvaluationService::class, 'screenTransportTimeout');
        $service = app(LabAgentEvaluationService::class);
        $this->assertSame(1800, $timeout->invoke($service, false, true));
        $this->assertSame(1800, $timeout->invoke($service, true, true));
        config()->set('services.lab_selection.screen_timeout_seconds', 300);
        $this->assertSame(300, $timeout->invoke($service, false, false));
        $context = $generation->trigger_context;
        unset($context['prospective_source_identity']['data_role']); $generation->update(['trigger_context' => $context]);
        $ordinary = new \App\Jobs\EvaluateLabAgentJob($agent->id, 'XAUUSD', 'screen');
        $this->assertFalse($ordinary->prospectiveProbe); $this->assertSame(1200, $ordinary->timeout);
    }

    public function test_arbiter_clean_scope_readiness_serves_only_ready_academy_and_earned_continuation(): void
    {
        [, , $manifest] = $this->configured();
        $owner = app(ResearchLoopArbiterService::class);
        $readiness = new \ReflectionMethod($owner, 'freshDatasetContinuityReadiness');
        $cold = app(AcademyExperimentMaterializerService::class)->coldStartProposal();
        $ready = $readiness->invoke($owner, null, $cold, 'XAUUSD');
        $this->assertTrue($ready['allowed']); $this->assertTrue($ready['discovery_only']);
        $this->assertFalse($ready['full_validation_eligible']); $this->assertFalse($ready['independent_evidence']);
        $continued = $readiness->invoke($owner, null, ['status' => 'would_materialize',
            'identity' => ['data_role' => 'pre_2026_discovery_only', 'mtf_bundle_manifest' => $manifest]], 'XAUUSD');
        $this->assertTrue($continued['allowed']); $this->assertTrue($continued['discovery_only']);
        $latest = new LabGeneration(['trigger_context' => ['mtf_bundle_manifest' => $manifest]]);
        $spent = $readiness->invoke($owner, $latest, ['status' => 'blocked', 'reason' => 'ACADEMY_COLD_START_SCOPE_BUDGET_EXHAUSTED'], 'XAUUSD');
        $this->assertFalse($spent['allowed']);
        $this->assertContains('DISCOVERY_SCOPE_DOES_NOT_AUTHORIZE_FULL_REPLAY', $spent['reasons']);
        $this->assertDatabaseCount('edge_academy_trials', 0);
        Queue::assertNothingPushed();
    }

    public function test_native_clean_bundle_enters_canonical_dispatch_and_real_python_exact_probe_without_full_readiness(): void
    {
        Queue::fake();
        $sourceFixture = new AcademyColdStartHandoffTest('test_old_passport_and_parameters_create_only_a_bounded_fresh_prospective_question');
        (new \ReflectionMethod($sourceFixture, 'sourceAndData'))->invoke($sourceFixture);
        app()->forgetInstance(MultiTimeframeSnapshotService::class);
        $native = (new ProspectiveCleanDiscoverySnapshotTest('test_clean_scope_freezes_real_selected_rows_but_full_validation_stays_blocked'))->cleanBundleFixture();
        $this->fixtureDirectories = [...$this->fixtureDirectories, ...$native['directories']];
        $bundle = $native['bundle'];
        config()->set('services.xauusd_organism.clean_discovery_bundle_hash', $bundle['bundle_hash']);
        config()->set('services.xauusd_organism.research_m5_dataset', $native['dataset']);
        $mtf = app(MultiTimeframeSnapshotService::class);
        $this->assertFalse($mtf->agentValidationReadiness('XAUUSD')['ready']);
        [$proposal, $generation] = $this->prepare();
        $seal = new \ReflectionMethod(\App\Console\Commands\DispatchLabGeneration::class, 'sealAutonomousMtfRuntime');
        $sealed = $seal->invoke(app(\App\Console\Commands\DispatchLabGeneration::class), $generation, 'XAUUSD', $mtf, app(LabGenerationContextService::class));
        $this->assertSame($bundle['bundle_hash'], data_get($sealed->trigger_context, 'mtf_bundle_hash'));
        $this->assertSame(json_decode(json_encode($bundle['manifest']), true), data_get($sealed->trigger_context, 'mtf_bundle_manifest'));
        $rows = app(LabDatasetExportService::class)->rowsFromSnapshot($bundle['entry_dataset_path'], 15512);
        $this->assertCount(15512, $rows);
        $evaluation = app(LabAgentEvaluationService::class);
        $request = ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'strategy' => 'ema_rsi_v1',
            'strategies' => [['strategy' => 'ema_rsi_v1', 'base_strategy' => 'ema_rsi_v1', 'version' => 'budget-fixture', 'parameters' => (object) []]],
            'evaluation_mode' => 'incremental', 'dataset_tail_rows' => 5000,
            'replay_dataset_hash' => $bundle['bundle_hash'], 'emit_decision_trace' => false,
            'execution_contract' => app(\App\Services\ExecutionContractService::class)->for('XAUUSD', 'M5'),
            'policy_context' => ['historical_stratified_windows' => ['window_rows' => 1500]]];
        $request = (new \ReflectionMethod($evaluation, 'applyMtfReplayBundle'))->invoke($evaluation, $request, $bundle);
        $request = (new \ReflectionMethod($evaluation, 'sealCleanDiscoveryWindow'))->invoke($evaluation, $request, $rows, $bundle);
        $this->assertNull($request['dataset_tail_rows']);
        $this->assertSame([], data_get($request, 'policy_context.historical_stratified_windows'));
        $this->assertSame(15000, data_get($request, 'policy_context.prospective_probe_window.evaluated_rows'));
        $script = <<<'PY'
import json, sys
from unittest.mock import patch
from app.schemas import SimpleBacktestRequest
from app.main import _run_all_backtests_sync, _run_prepared_simple_backtest
from app.services.backtester import _load_simple_candles
from app.services.prospective_probe_window import select_probe_window
r = json.load(sys.stdin)
p = SimpleBacktestRequest(**r)
loaded = _load_simple_candles(p)
evaluated, receipt = select_probe_window(loaded, p.policy_context['prospective_probe_window'], p.replay_dataset_hash, p.execution_contract['execution_hash'])
executed_rows = []
def observed_real_backtest(payload, frame, **kwargs):
    executed_rows.append(len(frame))
    sys.stderr.write('real_backtest_start:' + str(len(frame)) + '\n')
    result = _run_prepared_simple_backtest(payload, frame, **kwargs)
    sys.stderr.write('real_backtest_complete:' + str(len(frame)) + '\n')
    return result
def observed_checkpoint(key, stage, *args, **kwargs):
    sys.stderr.write('real_replay_stage:' + stage + '\n')
with patch('app.main._load_immutable_replay_cache', return_value=None), patch('app.main._store_immutable_replay_cache'), patch('app.main._write_replay_checkpoint', side_effect=observed_checkpoint), patch('app.main._run_prepared_simple_backtest', side_effect=observed_real_backtest):
    result = _run_all_backtests_sync(p)
actual = result['leaderboard'][0]['result']['prospective_probe_window_receipt']
print(json.dumps({'loaded':len(loaded),'evaluated':len(evaluated),'executed_rows':executed_rows,'receipt':actual,'synthetic_fixture':True,'market_replay_proven':False}))
PY;
        $process = new Process(['python', '-B', '-c', $script], dirname(base_path()).'/ai-service-python');
        $process->setInput(json_encode($request, JSON_UNESCAPED_SLASHES)); $process->setTimeout(360); $process->mustRun();
        $actual = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(15512, $actual['loaded']); $this->assertSame(15000, $actual['evaluated']);
        $this->assertSame([2000, 15000], $actual['executed_rows']); // real opportunity and stateful survival owners
        $this->assertTrue(app(ProspectiveRepairProbeWindowService::class)->attests($request['policy_context']['prospective_probe_window'], $actual['receipt']));
        $this->assertSame(512, $actual['receipt']['warmup_rows']);
        $this->assertFalse($actual['receipt']['independent_validation']);
        $this->assertFalse($actual['market_replay_proven']);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        Queue::assertNothingPushed();
    }
}
