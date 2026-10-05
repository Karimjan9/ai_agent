<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Services\LabImmutableEvidenceService;
use App\Services\LabLifecycleWatchdogService;
use App\Services\LabQueueJobInspector;
use App\Services\SystemLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SupersededLabRunReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private LabLifecycleWatchdogService $owner;
    private LabQueueJobInspector $queue;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.autonomous_mode.default_enabled' => false,
            'services.internal_api.token' => 'test-internal-token',
            'services.ai_service.url' => 'http://test-evaluator',
            'services.lab_evidence.disk' => 'reconciliation_test']);
        Storage::fake('reconciliation_test');
        $this->probe(['protocol' => 'replay_liveness_v2_bounded_worker', 'active_requests' => 0]);
        $this->queue = Mockery::mock(LabQueueJobInspector::class);
        $this->queue->shouldReceive('labQueueBacklog')->andReturn(['total' => 0, 'queues' => []])->byDefault();
        $this->app->instance(LabQueueJobInspector::class, $this->queue);
        $this->owner = Mockery::mock(LabLifecycleWatchdogService::class, [app(SystemLogService::class), $this->queue])
            ->makePartial()->shouldAllowMockingProtectedMethods();
        $this->owner->shouldReceive('supersededWorkerIsAbsent')->andReturn(true)->byDefault();
        $this->app->instance(LabLifecycleWatchdogService::class, $this->owner);
    }

    public function test_full_and_screen_originals_close_once_without_copying_later_response_or_changing_source_and_verdicts(): void
    {
        $fixtures = [$this->fixture('full_validation'), $this->fixture('screening')];
        $ids = array_map(fn ($facts) => $facts['original']->id, $fixtures);
        $laterBefore = array_map(fn ($facts) => $facts['later']->getAttributes(), $fixtures);
        $agentBefore = array_map(fn ($facts) => $facts['agent']->getAttributes(), $fixtures);
        $genBefore = array_map(fn ($facts) => $facts['generation']->getAttributes(), $fixtures);
        $originalFacts = array_map(fn ($facts) => $this->immutableFacts($facts['original']), $fixtures);
        $countBefore = LabEvidenceArtifact::count();

        $preview = $this->owner->reconcileSupersededRuns($ids);
        $this->assertSame('ready', $preview['status']);
        $this->assertSame(0, $preview['reconciled']);
        $this->assertSame($countBefore, LabEvidenceArtifact::count());
        $this->assertSame(['eligible', 'eligible'], array_column($preview['items'], 'status'));
        $result = $this->owner->reconcileSupersededRuns($ids, true);
        $this->assertSame('completed', $result['status']);
        $this->assertSame(2, $result['reconciled']);
        foreach ($fixtures as $index => $facts) {
            $original = $facts['original']->fresh();
            $this->assertSame('retry_released', $original->status);
            $this->assertSame($originalFacts[$index], $this->immutableFacts($original));
            $this->assertSame($laterBefore[$index], $facts['later']->fresh()->getAttributes());
            $this->assertSame($agentBefore[$index], $facts['agent']->fresh()->getAttributes());
            $this->assertSame($genBefore[$index], $facts['generation']->fresh()->getAttributes());
            $this->assertSame($facts['later']->id, data_get($original->metadata, 'superseding_completed_run_id'));
            $response = app(LabImmutableEvidenceService::class)->latestArtifactPayload($original);
            $this->assertFalse(data_get($response, 'terminal_replay_envelope.response_available'));
            $this->assertNull($response['total_trades']);
            $this->assertNull($original->trade_ledger_hash);
            $this->assertNotSame($facts['later']->response_hash, $original->response_hash);
            $this->assertFalse(data_get($original->metadata, 'promotion_evidence'));
        }
        $countClosed = LabEvidenceArtifact::count();
        $eventsClosed = DB::table('lab_lifecycle_events')->count();
        $again = $this->owner->reconcileSupersededRuns($ids, true);
        $this->assertSame(0, $again['reconciled']);
        $this->assertSame(['already_reconciled', 'already_reconciled'], array_column($again['items'], 'status'));
        $this->assertSame($countClosed, LabEvidenceArtifact::count());
        $this->assertSame($eventsClosed, DB::table('lab_lifecycle_events')->count());
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        $this->assertDatabaseCount('lab_mutation_credit_events', 0);
    }

    public function test_live_local_pid_and_foreign_host_are_not_absence_proofs(): void
    {
        $facts = $this->fixture();
        $facts['original']->update(['worker_pid' => (string) getmypid()]);
        $realOwner = new LabLifecycleWatchdogService(app(SystemLogService::class), $this->queue);
        $result = $realOwner->reconcileSupersededRuns([$facts['original']->id], true);
        $this->assertBlocked($result, 'ORIGINAL_WORKER_NOT_PROVEN_ABSENT_ON_THIS_HOST', $facts['original']);
        $facts['original']->update(['worker_name' => 'different-worker-host', 'worker_pid' => '999999']);
        $this->assertBlocked($this->owner->reconcileSupersededRuns([$facts['original']->id], true),
            'ORIGINAL_WORKER_NOT_PROVEN_ABSENT_ON_THIS_HOST', $facts['original']);
    }

    public function test_positive_and_unknown_queue_backlogs_fail_closed_without_consuming_any_job(): void
    {
        $facts = $this->fixture();
        $eventsBefore = DB::table('lab_lifecycle_events')->count();
        foreach ([1, null] as $total) {
            $this->queue->shouldReceive('labQueueBacklog')->andReturn(['total' => $total]);
            $this->assertBlocked($this->owner->reconcileSupersededRuns([$facts['original']->id], true),
                'SUPERSEDED_RUN_RECOVERY_QUEUE_NOT_PROVEN_IDLE', $facts['original']);
        }
        $this->assertDatabaseCount('lab_lifecycle_events', $eventsBefore);
    }

    public function test_replay_probe_active_unknown_unauthenticated_or_malformed_counts_fail_closed(): void
    {
        $facts = $this->fixture();
        foreach ([['active_requests' => 1], ['active_requests' => '0'], ['active_requests' => -1],
            ['active_requests' => 'garbage'], [], ['protocol' => '']] as $override) {
            $this->probe($override === [] ? ['protocol' => 'valid-protocol']
                : [...['protocol' => 'valid-protocol', 'active_requests' => 0], ...$override]);
            $this->assertBlocked($this->owner->reconcileSupersededRuns([$facts['original']->id], true),
                'SUPERSEDED_RUN_RECOVERY_REPLAY_NOT_PROVEN_IDLE', $facts['original']);
        }
        $this->probe(['protocol' => 'valid-protocol', 'active_requests' => 0], 403);
        $this->assertBlocked($this->owner->reconcileSupersededRuns([$facts['original']->id], true),
            'SUPERSEDED_RUN_RECOVERY_REPLAY_NOT_PROVEN_IDLE', $facts['original']);
        config(['services.internal_api.token' => '']);
        $this->assertBlocked($this->owner->reconcileSupersededRuns([$facts['original']->id], true),
            'SUPERSEDED_RUN_RECOVERY_REPLAY_NOT_PROVEN_IDLE', $facts['original']);
    }

    public function test_running_admission_and_nonterminal_original_agent_or_generation_refuse_recovery(): void
    {
        $facts = $this->fixture();
        config(['services.autonomous_mode.default_enabled' => true]);
        $this->assertBlocked($this->owner->reconcileSupersededRuns([$facts['original']->id], true),
            'SUPERSEDED_RUN_RECOVERY_REQUIRES_ADMISSION_STOP', $facts['original']);
        config(['services.autonomous_mode.default_enabled' => false]);
        $facts['generation']->update(['status' => 'full_validation']);
        $this->assertBlocked($this->owner->reconcileSupersededRuns([$facts['original']->id], true),
            'ORIGINAL_AGENT_OR_GENERATION_NOT_TERMINAL', $facts['original']);
        $facts['generation']->update(['status' => 'completed']);
        $facts['agent']->update(['lifecycle_status' => 'screening']);
        $this->assertBlocked($this->owner->reconcileSupersededRuns([$facts['original']->id], true),
            'ORIGINAL_AGENT_OR_GENERATION_NOT_TERMINAL', $facts['original']);
    }

    public function test_later_other_phase_and_technical_attempts_do_not_supersede_science(): void
    {
        $facts = $this->fixture();
        $facts['later']->update(['phase' => 'full_validation']);
        $this->assertBlocked($this->owner->reconcileSupersededRuns([$facts['original']->id], true),
            'LATER_SAME_PHASE_COMPLETED_RUN_MISSING', $facts['original']);
        $facts['later']->update(['phase' => 'screening', 'status' => 'technical_error']);
        $this->assertBlocked($this->owner->reconcileSupersededRuns([$facts['original']->id], true),
            'LATER_SAME_PHASE_COMPLETED_RUN_MISSING', $facts['original']);
    }

    public function test_missing_or_poisoned_later_artifact_refuses_response_hash_only_claim(): void
    {
        $facts = $this->fixture();
        Storage::disk('reconciliation_test')->put($facts['artifact']->storage_path, gzencode('{"total_trades":999}'));
        $this->assertBlocked($this->owner->reconcileSupersededRuns([$facts['original']->id], true),
            'LATER_SAME_PHASE_RESPONSE_ARTIFACT_INVALID', $facts['original']);
        $facts['artifact']->delete();
        $this->assertBlocked($this->owner->reconcileSupersededRuns([$facts['original']->id], true),
            'LATER_SAME_PHASE_RESPONSE_ARTIFACT_INVALID', $facts['original']);
    }

    public function test_legacy_inline_artifacts_are_hashed_and_operational_envelopes_are_not_actual_responses(): void
    {
        $facts = $this->fixture();
        $facts['artifact']->update(['storage_path' => null, 'payload' => ['total_trades' => 999]]);
        $this->assertBlocked($this->owner->reconcileSupersededRuns([$facts['original']->id], true),
            'LATER_SAME_PHASE_RESPONSE_ARTIFACT_INVALID', $facts['original']);
        $envelope = ['terminal_replay_envelope' => ['response_available' => false], 'total_trades' => null];
        $hash = app(LabImmutableEvidenceService::class)->hash($envelope);
        $facts['later']->update(['response_hash' => $hash]);
        $facts['artifact']->update(['sha256' => $hash, 'payload' => $envelope]);
        $this->assertBlocked($this->owner->reconcileSupersededRuns([$facts['original']->id], true),
            'LATER_SAME_PHASE_RESPONSE_ARTIFACT_INVALID', $facts['original']);
    }

    public function test_original_with_response_artifact_or_terminal_state_never_gets_rewritten(): void
    {
        $facts = $this->fixture();
        app(LabImmutableEvidenceService::class)->recordArtifact($facts['original'], 'evaluation_response', ['total_trades' => 1]);
        $this->assertBlocked($this->owner->reconcileSupersededRuns([$facts['original']->id], true),
            'ORIGINAL_RUN_NOT_OPEN_WITHOUT_RESPONSE', $facts['original']);
        $facts['original']->update(['status' => 'completed']);
        $before = $facts['original']->fresh()->getAttributes();
        $result = $this->owner->reconcileSupersededRuns([$facts['original']->id], true);
        $this->assertSame('blocked', $result['status']);
        $this->assertSame($before, $facts['original']->fresh()->getAttributes());
    }

    public function test_one_failed_proof_refuses_entire_target_scope_before_any_terminal_write(): void
    {
        $good = $this->fixture('full_validation');
        $bad = $this->fixture();
        $eventsBefore = DB::table('lab_lifecycle_events')->count();
        $bad['artifact']->delete();
        $result = $this->owner->reconcileSupersededRuns([$good['original']->id, $bad['original']->id], true);
        $this->assertSame('blocked', $result['status']);
        $this->assertSame(0, $result['reconciled']);
        $this->assertSame('started', $good['original']->fresh()->status);
        $this->assertSame('started', $bad['original']->fresh()->status);
        $this->assertDatabaseCount('lab_lifecycle_events', $eventsBefore);
    }

    public function test_late_queue_owner_change_rolls_back_the_requested_close(): void
    {
        $facts = $this->fixture();
        $eventsBefore = DB::table('lab_lifecycle_events')->count();
        $this->queue->shouldReceive('labQueueBacklog')->andReturn(['total' => 0], ['total' => 1]);
        try {
            $this->owner->reconcileSupersededRuns([$facts['original']->id], true);
            $this->fail('Late queue activity must refuse the terminal boundary.');
        } catch (RuntimeException $exception) {
            $this->assertSame('SUPERSEDED_RUN_RECOVERY_QUEUE_NOT_PROVEN_IDLE', $exception->getMessage());
        }
        $this->assertSame('started', $facts['original']->fresh()->status);
        $this->assertDatabaseCount('lab_lifecycle_events', $eventsBefore);
    }

    public function test_existing_cli_targets_redis_original_rows_without_mutating_mutex_and_requires_approval(): void
    {
        $facts = $this->fixture('full_validation');
        config(['queue.default' => 'redis']);
        DB::table('cache_locks')->insert(['key' => 'targeted-test-mutex', 'owner' => 'untouched', 'expiration' => now()->addHour()->timestamp]);
        $arguments = ['--reconcile-superseded' => true, '--superseded-run-id' => [$facts['original']->id]];
        $this->artisan('trading:recover-lab-replay-mutex', [...$arguments, '--dry-run' => true])->assertExitCode(0);
        $this->assertSame('started', $facts['original']->fresh()->status);
        $this->artisan('trading:recover-lab-replay-mutex', [...$arguments, '--apply' => true])->assertExitCode(1);
        $this->assertSame('started', $facts['original']->fresh()->status);
        $this->artisan('trading:recover-lab-replay-mutex', [...$arguments, '--apply' => true,
            '--approved-by' => 'test-operator', '--approval-reason' => 'Close exact abandoned originals, never science.'])->assertExitCode(0);
        $this->assertSame('retry_released', $facts['original']->fresh()->status);
        $this->assertDatabaseHas('cache_locks', ['key' => 'targeted-test-mutex', 'owner' => 'untouched']);
    }

    public function test_empty_missing_or_over_twenty_target_scope_is_rejected_without_broad_scan(): void
    {
        $this->artisan('trading:recover-lab-replay-mutex', ['--reconcile-superseded' => true])->assertExitCode(1);
        $this->artisan('trading:recover-lab-replay-mutex', ['--superseded-run-id' => ['42']])->assertExitCode(1);
        $this->artisan('trading:recover-lab-replay-mutex', ['--reconcile-superseded' => true,
            '--superseded-run-id' => range(1, 21)])->assertExitCode(1);
        $this->assertSame('ORIGINAL_RUN_MISSING', $this->owner->reconcileSupersededRuns([999])['items'][0]['reason_code']);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
    }

    private function fixture(string $phase = 'screening'): array
    {
        $lab = AiLaboratory::firstOrCreate(['symbol' => 'XAUUSD', 'timeframe' => 'H1'], [
            'name' => 'Targeted reconciliation', 'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse']);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id,
            'generation' => LabGeneration::count() + 1, 'trigger_type' => 'test', 'status' => $phase === 'screening' ? 'screened' : 'completed',
            'population_size' => 1, 'trigger_context' => [], 'completed_at' => now()->subHour()]);
        $model = ModelVersion::create(['name' => 'Superseded original '.$generation->generation, 'strategy' => 'hybrid',
            'version' => (string) Str::uuid(), 'generation' => $generation->generation,
            'status' => 'testing', 'parameters' => ['original' => 4], 'metadata' => []]);
        $agent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'origin' => 'test',
            'lifecycle_status' => $phase === 'screening' ? 'screened' : 'rejected', 'parameter_diff' => [], 'decision_reason' => 'Original scientific verdict']);
        $identity = ['lab_generation_id' => $generation->id, 'lab_agent_id' => $agent->id,
            'model_version_id' => $model->id, 'phase' => $phase, 'mode' => $phase === 'screening' ? 'screen' : 'full',
            'queue' => $phase === 'screening' ? 'lab-screening' : 'lab-full-validation',
            'worker_name' => (string) (gethostname() ?: php_uname('n')), 'worker_pid' => '999999',
            'data_hash' => hash('sha256', 'original-dataset'), 'parameter_hash' => hash('sha256', 'original-parameters')];
        $original = LabEvaluationRun::create([...$identity, 'run_id' => (string) Str::uuid(),
            'attempt' => 1, 'status' => 'started', 'started_at' => now()->subHours(3),
            'created_at' => now()->subHours(3), 'code_hash' => hash('sha256', 'old-source'),
            'request_hash' => hash('sha256', 'old-request'), 'metadata' => ['original' => true]]);
        $payload = ['total_trades' => 7, 'net_profit' => 123, 'trade_ledger_hash' => hash('sha256', 'later-trades')];
        $later = LabEvaluationRun::create([...$identity, 'run_id' => (string) Str::uuid(),
            'attempt' => 2, 'status' => 'completed', 'started_at' => now()->subHours(2), 'finished_at' => now()->subHour(),
            'code_hash' => hash('sha256', 'different-later-source'), 'request_hash' => hash('sha256', 'different-later-request'),
            'response_hash' => app(LabImmutableEvidenceService::class)->hash($payload), 'metrics' => ['net_profit' => 123]]);
        $artifact = app(LabImmutableEvidenceService::class)->recordArtifact($later, 'evaluation_response', $payload);
        $original = $original->fresh();
        $later = $later->fresh();
        $agent = $agent->fresh();
        $generation = $generation->fresh();
        return compact('original', 'later', 'artifact', 'agent', 'generation');
    }

    private function assertBlocked(array $result, string $reason, LabEvaluationRun $run): void
    {
        $this->assertSame('blocked', $result['status']);
        $this->assertSame($reason, $result['items'][0]['reason_code']);
        $this->assertSame(0, $result['reconciled']);
        $this->assertSame('started', $run->fresh()->status);
        $this->assertNull($run->fresh()->response_hash);
    }

    private function immutableFacts(LabEvaluationRun $run): array
    {
        return array_intersect_key($run->getAttributes(), array_flip(['id', 'run_id', 'lab_generation_id', 'lab_agent_id', 'model_version_id', 'phase', 'mode',
            'attempt', 'queue', 'worker_name', 'worker_pid', 'created_at', 'started_at', 'request_hash',
            'parameter_hash', 'data_hash', 'code_hash']));
    }

    private function probe(array $payload, int $status = 200): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['*' => Http::response($payload, $status)]);
    }
}
