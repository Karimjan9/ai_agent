<?php

namespace Tests\Feature;

use App\Console\Commands\DispatchLabGeneration;
use App\Jobs\EvaluateLabScreeningBatchJob;
use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Services\CooperativeContextualEvolutionCouncilService;
use App\Services\LabAgentEvaluationService;
use App\Services\MultiTimeframeSnapshotService;
use App\Services\ProspectiveRepairExperimentService;
use App\Services\ProspectiveRepairProbeWindowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/** Scheduling fixtures assert source/queue boundaries, never market or promotion evidence. */
class BoundedProbeScreeningDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_clean_discovery_dispatch_creates_one_queue_job_per_unchanged_agent_with_controls_first(): void
    {
        $generation = $this->generation('clean');
        $agents = collect([
            $this->agent($generation),
            $this->agent($generation, ['repair_anchor' => ['control_only' => true]]),
            $this->agent($generation),
            $this->agent($generation, ['repair_anchor' => ['control_only' => true]]),
        ]);
        $contextBefore = $generation->trigger_context;
        $jobIds = array_map(fn ($job): array => $job->labAgentIds, $this->jobs($generation, $agents, 4));
        $this->assertSame([[$agents[1]->id], [$agents[3]->id], [$agents[0]->id], [$agents[2]->id]], $jobIds);
        $this->assertSame($contextBefore, $generation->fresh()->trigger_context);
        $this->assertSame(15000, data_get($contextBefore, 'mtf_bundle_manifest.discovery_scope.calendar.evaluated_rows'));
        $this->assertSame(15512, data_get($contextBefore, 'mtf_bundle_manifest.discovery_scope.calendar.loaded_rows'));
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
    }

    public function test_mixed_price_volume_and_typed_budget_groups_keep_ordinary_batch_sizes(): void
    {
        $generation = $this->generation('ordinary');
        $prospective = ['causal_learning_cohort' => ['experiment_kind' => ProspectiveRepairExperimentService::KIND]];
        $agents = collect([
            $this->agent($generation),
            $this->agent($generation, $prospective),
            $this->agent($generation),
            $this->agent($generation, $prospective, ['volume_lane' => 'tick']),
            $this->agent($generation, [], ['volume_lane' => 'tick']),
            $this->agent($generation, ['repair_anchor' => ['control_only' => true]]),
            $this->agent($generation),
            $this->agent($generation, $prospective),
            $this->agent($generation, [], ['volume_lane' => 'tick']),
        ]);
        $jobs = $this->jobs($generation, $agents, 4);
        $this->assertSame([
            [$agents[5]->id], [$agents[0]->id, $agents[2]->id, $agents[6]->id],
            [$agents[1]->id], [$agents[7]->id], [$agents[3]->id], [$agents[4]->id, $agents[8]->id],
        ], array_map(fn ($job): array => $job->labAgentIds, $jobs));
        $this->assertSame(range(0, 5), array_keys($jobs));
        foreach ($jobs as $index => $job) {
            $this->assertInstanceOf(EvaluateLabScreeningBatchJob::class, $job);
            $this->assertSame($generation->id, $job->labGenerationId);
            $this->assertSame('XAUUSD', $job->symbol);
            $this->assertSame('H1', $job->timeframe);
            $this->assertSame($index % 2, $job->screeningSlot);
            $this->assertSame(2400, $job->timeout);
        }
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
    }

    #[DataProvider('ordinaryBatchSizes')]
    public function test_ordinary_dispatch_keeps_its_configured_or_heavy_batch_size(int $size): void
    {
        $generation = $this->generation('ordinary');
        $agents = collect();
        for ($index = 0; $index < 5; $index++) $agents->push($this->agent($generation));
        $jobs = $this->jobs($generation, $agents, $size);
        $this->assertSame(array_chunk($agents->pluck('id')->all(), $size), array_map(fn ($job): array => $job->labAgentIds, $jobs));
    }

    public static function ordinaryBatchSizes(): array
    {
        return [[4], [2], [6]];
    }

    #[DataProvider('oversizedTypedCases')]
    public function test_oversized_typed_or_mixed_payload_is_rejected_before_local_wait_run_snapshot_or_http(string $case): void
    {
        $generation = $this->generation($case === 'clean' ? 'clean' : 'ordinary');
        $prospective = ['causal_learning_cohort' => ['experiment_kind' => ProspectiveRepairExperimentService::KIND]];
        $wait = ['generation_target' => 'uncertainty_abstain', 'uncertainty_abstain_contract' => [
            'protocol' => CooperativeContextualEvolutionCouncilService::UNCERTAINTY_ABSTAIN_PROTOCOL,
            'status' => 'sealed', 'action' => 'WAIT', 'replay_required' => false,
            'promotion_evidence' => false]];
        $first = $this->agent($generation, in_array($case, ['prospective', 'typed_first'], true) ? $prospective : ($case === 'typed_wait' ? $wait : []));
        $second = $this->agent($generation, $case === 'clean' ? [] : $prospective);
        $before = [$first->fresh()->toArray(), $second->fresh()->toArray()];
        Http::fake();
        $ids = $case === 'reversed' ? [$second->id, $first->id] : [$first->id, $second->id];
        try {
            app(LabAgentEvaluationService::class)->screenBatch($ids, 'XAUUSD');
            $this->fail('A multi-candidate typed lease was accepted.');
        } catch (RuntimeException $error) {
            $this->assertSame('PROSPECTIVE_SCREEN_REQUIRES_SINGLE_CANDIDATE_JOB', $error->getMessage());
        }
        $this->assertSame($before, [$first->fresh()->toArray(), $second->fresh()->toArray()]);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        Http::assertNothingSent();
    }

    public static function oversizedTypedCases(): array
    {
        return [['clean'], ['prospective'], ['typed_first'], ['typed_last'], ['reversed'], ['typed_wait']];
    }

    public function test_terminal_typed_history_is_filtered_before_ordinary_singleton_budget_guard(): void
    {
        $generation = $this->generation('ordinary');
        $completed = $this->agent($generation, ['causal_learning_cohort' => ['experiment_kind' => ProspectiveRepairExperimentService::KIND]], [], 'screened');
        $queued = $this->agent($generation);
        $run = LabEvaluationRun::create(['run_id' => 'immutable-completed-probe', 'lab_generation_id' => $generation->id,
            'lab_agent_id' => $completed->id, 'model_version_id' => $completed->model_version_id,
            'phase' => 'screening', 'mode' => 'incremental', 'attempt' => 1, 'status' => 'completed',
            'request_hash' => str_repeat('a', 64), 'finished_at' => now()]);
        $before = [$completed->fresh()->toArray(), $run->fresh()->toArray()];
        Http::fake();
        try {
            app(LabAgentEvaluationService::class)->screenBatch([$queued->id, $completed->id], 'XAUUSD');
            $this->fail('The fixture intentionally lacks its existing MTF authority.');
        } catch (RuntimeException $error) {
            // It passed the singleton scheduler guard and reached the unchanged
            // source/MTF owner; completed typed history was never replayed.
            $this->assertSame('AUTONOMOUS_MTF_BUNDLE_MISSING', $error->getMessage());
        }
        $this->assertSame($before, [$completed->fresh()->toArray(), $run->fresh()->toArray()]);
        $this->assertSame('queued', $queued->fresh()->lifecycle_status);
        $this->assertDatabaseCount('lab_evaluation_runs', 1);
        Http::assertNothingSent();
    }

    #[DataProvider('singletonKinds')]
    public function test_typed_singleton_lock_covers_queue_projection_without_larger_replay_budget(string $kind): void
    {
        config()->set('services.lab_queue.screening_batch_timeout_seconds', 1800);
        $generation = $this->generation($kind === 'clean' ? 'clean' : 'ordinary');
        $agent = $this->agent($generation, $kind === 'prospective'
            ? ['causal_learning_cohort' => ['experiment_kind' => ProspectiveRepairExperimentService::KIND]] : []);
        $job = new EvaluateLabScreeningBatchJob([$agent->id], 'XAUUSD', 0, $generation->id, 'H1');
        $lock = collect($job->middleware())->first(fn ($middleware) => $middleware instanceof WithoutOverlapping);
        $this->assertIsInt($lock->expiresAfter);
        $this->assertSame($kind === 'ordinary' ? 1920 : 2520, $lock->expiresAfter);
        $this->assertSame(2400, $job->timeout);
        if ($kind !== 'ordinary') $this->assertGreaterThan($job->timeout, $lock->expiresAfter);
        $this->assertSame('lab-screening-batch:'.$agent->id, $job->uniqueId());
        $this->assertSame(3, $job->maxExceptions);
        $this->assertGreaterThan($job->timeout, (int) config('queue.connections.redis.retry_after', 4500));
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
    }

    public static function singletonKinds(): array
    {
        return [['clean'], ['prospective'], ['ordinary']];
    }

    #[DataProvider('stalePayloadKinds')]
    public function test_stale_payload_lock_uses_actual_live_typed_owner_not_raw_member_count(bool $terminalTyped, bool $liveTyped): void
    {
        config()->set('services.lab_queue.screening_batch_timeout_seconds', 1800);
        $generation = $this->generation('ordinary');
        $typed = ['causal_learning_cohort' => ['experiment_kind' => ProspectiveRepairExperimentService::KIND]];
        $completed = $this->agent($generation, $terminalTyped ? $typed : [], [], 'screened');
        $live = $this->agent($generation, $liveTyped ? $typed : []);
        $job = new EvaluateLabScreeningBatchJob([$completed->id, $live->id], 'XAUUSD', 0, $generation->id, 'H1');
        $before = [$completed->fresh()->toArray(), $live->fresh()->toArray()];
        $lock = collect($job->middleware())->first(fn ($middleware) => $middleware instanceof WithoutOverlapping);
        $this->assertSame($liveTyped ? 2520 : 1920, $lock->expiresAfter);
        $this->assertSame(2400, $job->timeout);
        $this->assertSame($before, [$completed->fresh()->toArray(), $live->fresh()->toArray()]);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
    }

    public static function stalePayloadKinds(): array
    {
        return [[false, true], [true, true], [true, false]];
    }

    public function test_scheduling_marker_never_grants_a_discovery_or_window_receipt(): void
    {
        $owner = app(ProspectiveRepairProbeWindowService::class);
        $context = $this->cleanContext();
        $this->assertTrue($owner->requiresSingleCandidateScreening([], 'academy_experiment', $context));
        $this->assertFalse($owner->requiresSingleCandidateScreening([], 'ordinary', $context));
        unset($context['prospective_source_identity']['data_role']);
        $this->assertFalse($owner->requiresSingleCandidateScreening([], 'academy_experiment', $context));
        $this->assertFalse($owner->attests([], ['complete' => true]));
    }

    private function jobs(LabGeneration $generation, \Illuminate\Support\Collection $agents, int $ordinaryBatchSize): array
    {
        return (new \ReflectionMethod(DispatchLabGeneration::class, 'screeningJobs'))->invoke(
            app(DispatchLabGeneration::class), $generation, $agents, $agents->pluck('id')->all(), $ordinaryBatchSize, 'XAUUSD', 'H1');
    }

    private function generation(string $kind): LabGeneration
    {
        $lab = AiLaboratory::create(['symbol' => 'XAUUSD', 'name' => 'Bounded probe scheduling fixture', 'timeframe' => 'H1',
            'strategy_families' => ['confirmation_entry_mtf'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse']);
        return LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1,
            'trigger_type' => $kind === 'clean' ? 'academy_experiment' : 'test',
            'trigger_context' => $kind === 'clean' ? $this->cleanContext() : [], 'population_size' => 20, 'status' => 'screening']);
    }

    private function cleanContext(): array
    {
        return ['prospective_source_identity' => ['data_role' => 'pre_2026_discovery_only'],
            'mtf_bundle_manifest' => ['validation_bundle_protocol' => MultiTimeframeSnapshotService::DISCOVERY_BUNDLE_PROTOCOL,
                'discovery_scope' => ['calendar' => ['loaded_rows' => 15512, 'evaluated_rows' => 15000, 'warmup_rows' => 512]]]];
    }

    private function agent(LabGeneration $generation, array $metadata = [], array $parameters = [], string $status = 'queued'): LabAgent
    {
        $fixtureIndex = ModelVersion::query()->count();
        $model = ModelVersion::create(['name' => 'bounded-probe-fixture-'.$fixtureIndex, 'strategy' => 'confirmation_entry_mtf_v1',
            'version' => 'fixture-'.$fixtureIndex, 'generation' => 1, 'status' => 'testing',
            'metadata' => $metadata, 'parameters' => $parameters]);
        return LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'confirmation_entry_mtf',
            'origin' => 'test', 'lifecycle_status' => $status, 'parameter_diff' => []]);
    }
}
