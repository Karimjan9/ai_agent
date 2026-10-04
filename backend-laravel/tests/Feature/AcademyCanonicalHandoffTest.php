<?php

namespace Tests\Feature;

use App\Jobs\EvaluateLabScreeningBatchJob;
use App\Jobs\RunScheduledArtisanCommandJob;
use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\LabEvaluationRun;
use App\Models\ModelVersion;
use App\Models\ResearchLoopDecision;
use App\Services\AcademyExperimentContractCompilerService;
use App\Services\AcademyExperimentMaterializerService;
use App\Services\AutonomousModeService;
use App\Services\LabQueueJobInspector;
use App\Services\LabImmutableEvidenceService;
use App\Services\AcademyExperimentSettlementReconcilerService;
use App\Services\ResearchLoopArbiterService;
use App\Services\ScheduledCommandOutcomeClassifierService;
use App\Services\StrategyParameterSchemaService;
use App\Services\XauusdEdgeFormationAcademyService;
use App\Services\ResearchReleaseSealService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Real Academy owners; the deliberately unavailable queue dependency is external. */
class AcademyCanonicalHandoffTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_planner_arbiter_and_scheduled_command_prepare_once_then_use_canonical_dispatch(): void
    {
        Queue::fake();
        config()->set('services.xauusd_organism.historical_research_until_champion', false);
        [$trialId, $model] = $this->prospectiveTrial();
        app(AutonomousModeService::class)->start('XAUUSD', 'H1', 'test', 'canonical Academy integration');
        $arbiter = app(ResearchLoopArbiterService::class);

        $open = $arbiter->tick();
        $this->assertSame('OPEN_ACADEMY_EXPERIMENT', $open['action'], json_encode($open));
        $openJob = new RunScheduledArtisanCommandJob($open['command'], $open['arguments'], $open['queue'], $open['decision_id']);
        $openJob->handle(app(ScheduledCommandOutcomeClassifierService::class));
        (new UniqueLock(Cache::store()))->release($openJob);
        $this->assertSame('completed', ResearchLoopDecision::findOrFail($open['decision_id'])->status);
        $trial = DB::table('edge_academy_trials')->find($trialId);
        $outcome = json_decode($trial->outcome, true);
        $generation = LabGeneration::findOrFail($outcome['generation_id']);
        $this->assertSame('draft', $generation->status);
        $this->assertSame(20, $generation->agents()->count());
        $this->assertSame(3, $generation->agents()->where('origin', 'academy_experiment')->count());
        Queue::assertNotPushed(EvaluateLabScreeningBatchJob::class);

        $queueAvailable = false;
        $this->mock(LabQueueJobInspector::class, function ($mock) use (&$queueAvailable): void {
            $mock->shouldReceive('queueSnapshot')->andReturnUsing(function () use (&$queueAvailable): array {
                return ['available' => $queueAvailable, 'total' => $queueAvailable ? 0 : null];
            });
        });
        $dispatch = $arbiter->tick();
        $this->assertSame('DISPATCH_ACADEMY_EXPERIMENT', $dispatch['action']);
        $this->assertSame($generation->id, data_get($dispatch, 'evidence_snapshot.academy_proposal.generation_id'));
        // Execute the actual dispatcher, which must defer when its queue-state
        // dependency is unavailable. The materializer may never queue around it.
        $dispatchJob = new RunScheduledArtisanCommandJob($dispatch['command'], $dispatch['arguments'], $dispatch['queue'], $dispatch['decision_id']);
        $dispatchJob->handle(app(ScheduledCommandOutcomeClassifierService::class));
        (new UniqueLock(Cache::store()))->release($dispatchJob);
        $this->assertSame('deferred', ResearchLoopDecision::findOrFail($dispatch['decision_id'])->status);
        $this->assertSame('pending', data_get(json_decode(DB::table('edge_academy_trials')->find($trialId)->outcome, true), 'canonical_admission.status'));
        $this->assertSame(2, LabGeneration::count());
        $this->assertSame(20, $generation->agents()->where('lifecycle_status', 'draft')->count());
        $this->assertSame('pending_canonical_admission', app(AcademyExperimentMaterializerService::class)->proposal()['status']);
        $this->assertSame('duplicate_suppressed', $arbiter->tick()['status']);
        $queueAvailable = true;
        $reselected = $arbiter->tick();
        $this->assertSame('dispatched', $reselected['status']);
        $this->assertSame('DISPATCH_ACADEMY_EXPERIMENT', $reselected['action']);
        $this->assertNotSame($dispatch['decision_id'], $reselected['decision_id']);
        $this->assertSame($generation->id, data_get($reselected, 'evidence_snapshot.academy_proposal.generation_id'));
        $this->assertSame(2, LabGeneration::count());
        Queue::assertNotPushed(EvaluateLabScreeningBatchJob::class);
        $this->assertDatabaseCount('causal_stage_mastery_assessments', 0);
        $this->assertDatabaseCount('evolutionary_authority_ledgers', 0);
    }

    public function test_an_unrelated_arbiter_decision_cannot_materialize_another_trial(): void
    {
        [$trialId, $model] = $this->prospectiveTrial();
        $proposal = app(AcademyExperimentMaterializerService::class)->proposal();
        $wrongDecision = ResearchLoopDecision::create(['decision_key' => hash('sha256', 'wrong Academy trial'),
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'action' => 'OPEN_ACADEMY_EXPERIMENT', 'status' => 'running',
            'command' => 'trading:admit-academy-experiment', 'arguments' => [0 => $trialId + 1],
            'evidence_hash' => str_repeat('d', 64), 'evidence_snapshot' => ['academy_proposal' => $proposal],
            'reason_codes' => [], 'contract' => []]);
        $result = app(AcademyExperimentMaterializerService::class)->admit($trialId, $model->id, $proposal['identity'], $wrongDecision);
        $this->assertSame('ACADEMY_DURABLE_ARBITER_ADMISSION_REQUIRED', $result['reason']);
        $this->assertSame(1, LabGeneration::count());
        $this->assertSame('planned', DB::table('edge_academy_trials')->find($trialId)->status);
        $this->assertSame(0, Artisan::call('trading:admit-academy-experiment', ['trial' => $trialId]));
        $this->assertSame('blocked', json_decode(Artisan::output(), true)['status']);
    }

    public function test_real_python_semantic_receipts_settle_the_exact_cohort_into_a_distinct_candidate_scaffold(): void
    {
        Queue::fake();
        Storage::fake('academy_fixture_evidence');
        config()->set('services.lab_evidence.disk', 'academy_fixture_evidence');
        config()->set('services.xauusd_organism.historical_research_until_champion', false);
        $producerPath = base_path('../ai-service-python/tests/support/semantic_stage_receipt_fixture.py');
        $stagedPython = base_path('../.runtime/semantic-dual-source-staging-2026-10-03/ai-service-python');
        if (getenv('ACADEMY_DUAL_SOURCE_STAGE') === '1' && is_file($stagedPython.'/tests/support/semantic_stage_receipt_fixture.py')) {
            $producerPath = $stagedPython.'/tests/support/semantic_stage_receipt_fixture.py';
            // Use the real scanner against the actual staged Python bytes.
            app()->instance(ResearchReleaseSealService::class, new class(dirname($stagedPython).'/backend-laravel') extends ResearchReleaseSealService {
                public function __construct(private string $stagedBase) {}
                public function pythonHash(): string
                {
                    $original = app()->basePath();
                    app()->setBasePath($this->stagedBase);
                    try { return parent::pythonHash(); }
                    finally { app()->setBasePath($original); }
                }
            });
        }
        $producer = new Process(['python', $producerPath,
            '--full-runtime-source-hash', app(LabImmutableEvidenceService::class)->codeHash(),
            '--python-source-hash', app(ResearchReleaseSealService::class)->pythonHash()],
            base_path('../ai-service-python'), timeout: 90);
        $producer->mustRun();
        $fixture = json_decode($producer->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($fixture['synthetic_fixture']);
        $this->assertFalse($fixture['market_replay_proven']);
        $bindings = data_get($fixture, 'control.data_quality.decision_identity_receipt.bindings');
        $identity = ['pre_2026_only' => true, 'data_hash' => $fixture['control']['data_hash'], 'execution_hash' => $bindings['execution_hash'],
            'source_identity_protocol' => $bindings['source_identity_protocol'],
            'source_evaluator_hash' => $bindings['full_runtime_source_hash'], 'python_source_hash' => $bindings['python_source_hash'],
            'mtf_bundle_hash' => $fixture['control']['data_hash'], 'mtf_bundle_manifest' => ['protocol' => \App\Services\MultiTimeframeSnapshotService::PROTOCOL, 'streams' => collect(data_get($fixture, 'control.data_quality.decision_identity_receipt.dependency_identity.streams'))->map(
                fn (array $stream): array => ['sha256' => $stream['actual_source_sha256']])->all()],
            'canonical_dataset_snapshots' => ['foundation' => ['manifest' => ['sha256' => $bindings['actual_source_sha256']]]]];
        [$trialId, $baseline] = $this->prospectiveTrial(['location_tolerance_atr' => $fixture['control_parameters']['location_tolerance_atr'],
            'setup_topology_policy' => 'liquidity_sweep_reclaim'], $identity);
        $originalPassport = DB::table('edge_academy_trials')->where('id', $trialId)->value('edge_academy_passport_id');
        app(AutonomousModeService::class)->start('XAUUSD', 'H1', 'test', 'synthetic stage wiring');
        $open = app(ResearchLoopArbiterService::class)->tick();
        $this->assertSame('OPEN_ACADEMY_EXPERIMENT', $open['action']);
        $job = new RunScheduledArtisanCommandJob($open['command'], $open['arguments'], $open['queue'], $open['decision_id']);
        $job->handle(app(ScheduledCommandOutcomeClassifierService::class));
        (new UniqueLock(Cache::store()))->release($job);
        $outcome = json_decode(DB::table('edge_academy_trials')->find($trialId)->outcome, true);
        $generation = LabGeneration::findOrFail($outcome['generation_id']);
        $primary = $generation->agents()->where('origin', 'academy_experiment')->with('modelVersion')->get();
        $candidate = $primary->first(fn (LabAgent $agent): bool => data_get($agent->modelVersion->metadata, 'academy_experiment.arm_role') === 'candidate');
        $this->assertSame($fixture['candidate_parameters']['location_tolerance_atr'], $candidate->modelVersion->parameters['location_tolerance_atr']);
        foreach ($primary as $agent) {
            $arm = match (data_get($agent->modelVersion->metadata, 'academy_experiment.arm_role')) {
                'candidate' => 'candidate', 'blinded_control' => 'blinded', default => 'control',
            };
            $metrics = $fixture[$arm];
            // Raw JSON may contain a numeric-key object; decoding it into an
            // array changes re-encoded shape, not the immutable original bytes.
            $metrics['irrelevant_numeric_object'] = (object) ['0' => 'sealed fixture byte shape'];
            $evidence = app(LabImmutableEvidenceService::class);
            $hash = $evidence->hash($metrics);
            $run = LabEvaluationRun::create(['run_id' => (string) Str::uuid(), 'lab_generation_id' => $generation->id,
                'lab_agent_id' => $agent->id, 'model_version_id' => $agent->model_version_id, 'phase' => 'screening', 'mode' => 'screen',
                'status' => 'completed', 'response_hash' => $hash, 'data_hash' => $metrics['data_hash'],
                'code_hash' => $bindings['full_runtime_source_hash'],
                'parameter_hash' => $evidence->parameterHash($agent), 'attempt' => 1]);
            $evidence->recordArtifact($run, 'evaluation_response', $metrics);
            $agent->update(['lifecycle_status' => 'screened']);
        }
        $result = app(AcademyExperimentSettlementReconcilerService::class)->reconcile('XAUUSD', 'H1', true);
        $settled = $result['outcomes'][0]['result'];
        $this->assertSame('UNDERPOWERED', $settled['classification'], json_encode($settled));
        $this->assertSame('observed_stage_controllability', data_get($settled, 'stage_progress.status'));
        $successorId = data_get($settled, 'stage_progress.successor_passport_id');
        $this->assertNotSame((int) $originalPassport, $successorId);
        $successor = DB::table('edge_academy_passports')->find($successorId);
        $this->assertSame(1, $successor->stage_depth);
        $this->assertSame(0, DB::table('edge_academy_passports')->find($originalPassport)->stage_depth);
        $successorContract = json_decode($successor->frozen_upstream_contract, true);
        $this->assertSame($candidate->model_version_id, data_get($successorContract, 'baseline_model_version_id'));
        $this->assertSame($identity['data_hash'], data_get($successorContract, 'prospective_source_identity.data_hash'));
        $this->assertSame($identity['mtf_bundle_hash'], data_get($successorContract, 'prospective_source_identity.mtf_bundle_hash'));
        $this->assertSame('planned', data_get($settled, 'stage_progress.next_trial.status'));
        $next = app(AcademyExperimentMaterializerService::class)->proposal();
        $this->assertSame('would_materialize', $next['status'], json_encode($next));
        $this->assertSame($candidate->model_version_id, $next['baseline_model_version_id']);
        $this->assertSame(data_get($settled, 'stage_progress.next_trial.trial_id'), $next['trial_id']);
        $continuation = app(XauusdEdgeFormationAcademyService::class)->curriculumContinuationEvidence($next['trial_id']);
        $this->assertTrue($continuation['eligible'], json_encode($continuation));
        $this->assertSame($candidate->model_version_id, $next['baseline_model_version_id']);
        $this->assertSame($continuation, app(XauusdEdgeFormationAcademyService::class)->curriculumContinuationEvidence($next['trial_id']));
        $this->assertFalse(data_get($settled, 'stage_progress.independent_causal_skill'));
        $this->assertSame('idle', app(AcademyExperimentSettlementReconcilerService::class)->reconcile('XAUUSD', 'H1', true)['status']);
        $this->assertDatabaseCount('edge_academy_passports', 2);
        $this->assertDatabaseCount('research_experiment_receipts', 1);
        $this->assertDatabaseCount('evolutionary_authority_ledgers', 0);
        Queue::assertNotPushed(EvaluateLabScreeningBatchJob::class);
    }

    private function prospectiveTrial(array $parameterOverrides = [], ?array $sourceIdentity = null): array
    {
        $lab = AiLaboratory::create(['name' => 'canonical Academy fixture', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_families' => ['confirmation_entry_mtf'], 'lifecycle_mode' => 'lighthouse', 'is_active' => true]);
        $source = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'fixture', 'status' => 'completed']);
        $model = ModelVersion::create(['name' => 'canonical Academy source', 'strategy' => 'canonical_academy_source', 'version' => 'fixture-v1',
            'generation' => 1, 'status' => 'testing', 'parameters' => [...app(StrategyParameterSchemaService::class)->defaults('confirmation_entry_mtf'),
                'setup_topology_policy' => 'liquidity_sweep_reclaim', ...$parameterOverrides],
            'metadata' => ['base_strategy' => 'confirmation_entry_mtf_v1']]);
        LabAgent::create(['lab_generation_id' => $source->id, 'model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'confirmation_entry_mtf', 'origin' => 'fixture', 'lifecycle_status' => 'completed', 'parameter_diff' => []]);
        $model = $model->fresh();
        $identity = $sourceIdentity ?? ['pre_2026_only' => true, 'data_hash' => str_repeat('a', 64), 'execution_hash' => str_repeat('b', 64),
            'mtf_bundle_hash' => str_repeat('c', 64), 'mtf_bundle_manifest' => ['protocol' => \App\Services\MultiTimeframeSnapshotService::PROTOCOL,
                'streams' => array_fill_keys(['M5', 'M15', 'H1', 'H4'], ['sha256' => str_repeat('a', 64)])],
            'canonical_dataset_snapshots' => ['foundation' => ['manifest' => ['sha256' => str_repeat('a', 64)]]]];
        $academy = app(XauusdEdgeFormationAcademyService::class);
        $passport = $academy->passport('XAUUSD', 'H1', ['composition_key' => 'canonical-prospective-location',
            'strategy_family' => 'confirmation_entry_mtf', 'deepest_stage' => 'market_cartographer',
            'baseline_model_version_id' => $model->id, 'baseline_parameters' => (array) $model->parameters,
            'baseline_parameter_hash' => app(AcademyExperimentContractCompilerService::class)->parameterHash((array) $model->parameters),
            'prospective_source_identity' => $identity]);
        $planned = $academy->planContextLocationProbe($passport['passport_id']);
        $this->assertSame('planned', $planned['status'], json_encode($planned));
        return [(int) $planned['trial_id'], $model];
    }
}
