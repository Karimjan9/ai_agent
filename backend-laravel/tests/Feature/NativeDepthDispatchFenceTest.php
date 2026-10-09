<?php

namespace Tests\Feature;

use App\Console\Commands\DispatchLabGeneration;
use App\Jobs\EvaluateLabScreeningBatchJob;
use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Services\CandidateHandoffService;
use App\Services\GenerationConstructionAdmissionService;
use App\Services\GenerationSnapshotAdmissionService;
use App\Services\LabAgentPreflightService;
use App\Services\LabDatasetExportService;
use App\Services\LabGenerationContextService;
use App\Services\LabImmutableEvidenceService;
use App\Services\LabPopulationService;
use App\Services\LabQueueJobInspector;
use App\Services\LearningEvidenceGate;
use App\Services\LearningProtocolSafetyService;
use App\Services\LearningTechnicalCircuitBreakerService;
use App\Services\MarketData\MarketDataContinuityService;
use App\Services\MultiTimeframeSnapshotService;
use App\Services\NativeReachabilityDepthAuditService;
use App\Services\ResearchReleaseSealService;
use App\Services\SpecialistCouncilPreparationService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Isolated routing/queue fixtures with mocked pure owners; no scientific receipt or market evidence. */
class NativeDepthDispatchFenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.market_data.provider', 'csv');
        config()->set('services.xauusd_organism.laboratory_storage_timeframe', 'H1');
        config()->set('services.xauusd_organism.symbol', 'XAUUSD');
        Http::preventStrayRequests();
        Bus::fake();
    }

    #[DataProvider('invalidIds')]
    public function test_invalid_expected_id_refuses_once_before_any_laboratory_creation(mixed $id): void
    {
        $this->installRoutingServices();
        $result = $this->dispatch(['--expected-generation-id' => $id]);
        $this->assertRefused($result, 'NATIVE_DEPTH_EXPECTED_GENERATION_ID_INVALID', 0);
        $this->assertDatabaseCount('ai_laboratories', 0);
        $this->assertDatabaseCount('lab_generations', 0);
        Bus::assertNothingBatched();
    }

    public static function invalidIds(): array
    {
        return [['0'], ['-1'], ['1.2'], [''], ['not-an-id'], ['999999999999999999999999']];
    }

    public function test_missing_or_changed_latest_never_builds_a_fallback_generation(): void
    {
        $this->installRoutingServices();
        $this->assertRefused($this->dispatch(['--expected-generation-id' => 99]), 'NATIVE_DEPTH_EXPECTED_GENERATION_NOT_LATEST', 99);
        [$generation] = LabAgent::withoutEvents(fn () => $this->generation());
        $newer = LabGeneration::create(['ai_laboratory_id' => $generation->ai_laboratory_id, 'generation' => 2,
            'trigger_type' => 'routing_fixture', 'population_size' => 6, 'status' => 'draft', 'trigger_context' => []]);
        $this->assertRefused($this->dispatch(['--expected-generation-id' => $generation->id]), 'NATIVE_DEPTH_EXPECTED_GENERATION_NOT_LATEST', $generation->id);
        $this->assertDatabaseCount('lab_generations', 2);
        $this->assertSame('draft', $newer->fresh()->status);
        Bus::assertNothingBatched();
    }

    #[DataProvider('incompatibleOptions')]
    public function test_scope_and_other_writer_flags_refuse_before_owner_or_snapshot(array $options, string $reason): void
    {
        [$generation] = $this->generation();
        $this->installRoutingServices();
        $this->assertRefused($this->dispatch([...$options, '--expected-generation-id' => $generation->id]), $reason, $generation->id);
        $this->assertSame('draft', $generation->fresh()->status);
        $this->assertDatabaseCount('lab_generations', 1);
        Bus::assertNothingBatched();
    }

    public static function incompatibleOptions(): array
    {
        $scope = 'NATIVE_DEPTH_EXACT_SCOPE_AND_RESUME_REQUIRED';
        $flags = 'NATIVE_DEPTH_ALTERNATIVE_CREATION_FLAG_FORBIDDEN';
        return [[['symbol' => 'EURUSD'], $scope], [['--timeframe' => 'M5'], $scope],
            [['--resume-draft-agents' => false], $scope], [['--force-generation' => true], $flags],
            [['--controlled-rescue' => true], $flags], [['--shadow-research' => true], $flags],
            [['--audited-data-edge' => true], $flags], [['--learning-confirmation' => true], $flags]];
    }

    public function test_an_ordinary_generation_cannot_acquire_the_depth_fence(): void
    {
        [$generation] = $this->generation();
        $generation->update(['trigger_context' => []]);
        $this->installRoutingServices();
        $this->assertRefused($this->dispatch(['--expected-generation-id' => $generation->id]), 'NATIVE_DEPTH_ORIGINAL_PURPOSE_REQUIRED', $generation->id);
        Bus::assertNothingBatched();
    }

    #[DataProvider('unreadyPhases')]
    public function test_completed_unprepared_or_in_flight_original_owner_cannot_requeue(string $phase): void
    {
        [$generation, $agents] = $this->generation();
        $this->installRoutingServices($generation, $agents[4]->id, $phase);
        $before = $generation->fresh()->toArray();
        $this->assertRefused($this->dispatch(['--expected-generation-id' => $generation->id]), 'NATIVE_DEPTH_ORIGINAL_PHASE_NOT_READY', $generation->id);
        $this->assertSame($before, $generation->fresh()->toArray());
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        Bus::assertNothingBatched();
    }

    public static function unreadyPhases(): array
    {
        return [['terminal'], ['unprepared'], ['not_applicable'], ['settle_only'], ['deeper_in_flight']];
    }

    public function test_missing_prepared_owner_refuses_without_queue_or_snapshot_writes(): void
    {
        [$generation, $agents] = $this->generation();
        $this->installRoutingServices($generation, $agents[4]->id, 'cheap_pending', prepared: false);
        $this->assertRefused($this->dispatch(['--expected-generation-id' => $generation->id]), 'NATIVE_DEPTH_PREPARED_OWNER_REQUIRED', $generation->id);
        Bus::assertNothingBatched();
    }

    public function test_already_queued_target_is_not_repaired_or_mutated(): void
    {
        [$generation, $agents] = $this->generation();
        $agents[4]->update(['lifecycle_status' => 'queued']);
        $this->installRoutingServices($generation, $agents[4]->id, 'cheap_pending');
        $before = $agents[4]->fresh()->toArray();
        $this->assertRefused($this->dispatch(['--expected-generation-id' => $generation->id]), 'NATIVE_DEPTH_ORIGINAL_PHASE_ALREADY_ADMITTED', $generation->id);
        $this->assertSame($before, $agents[4]->fresh()->toArray());
        Bus::assertNothingBatched();
    }

    #[DataProvider('queueRefusals')]
    public function test_canonical_exit_zero_queue_deferral_becomes_one_refused_nonzero_marker(array $snapshot, string $reason): void
    {
        [$generation, $agents] = $this->generation();
        $this->installRoutingServices($generation, $agents[4]->id, 'cheap_pending', snapshot: $snapshot);
        $this->assertRefused($this->dispatch(['--expected-generation-id' => $generation->id]), $reason, $generation->id);
        $this->assertSame('draft', $agents[4]->fresh()->lifecycle_status);
        Bus::assertNothingBatched();
    }

    public static function queueRefusals(): array
    {
        return [[['available' => false], 'NATIVE_DEPTH_QUEUE_STATE_UNAVAILABLE'],
            [['available' => true, 'total' => 100000], 'NATIVE_DEPTH_SCREENING_BACKLOG']];
    }

    #[DataProvider('originalPhases')]
    public function test_only_one_original_phase_gets_a_canonical_singleton_batch_and_marker(string $phase, int $index): void
    {
        [$generation, $agents] = $this->generation();
        if ($phase === 'deeper_ready') {
            $generation->update(['status' => 'screening']);
            $agents[4]->update(['lifecycle_status' => 'screened']);
        }
        $selectedId = $agents[$index]->id;
        $others = $agents->reject(fn ($agent) => $agent->id === $selectedId)->mapWithKeys(fn ($agent) => [$agent->id => $agent->fresh()->toArray()]);
        $this->installRoutingServices($generation, $selectedId, $phase, permitPublication: true);
        $result = $this->dispatch(['--expected-generation-id' => $generation->id]);
        $this->assertSame(0, $result['exit']);
        $this->assertSame(['protocol' => 'native_depth_audit_dispatch_v1', 'status' => 'admitted',
            'generation_id' => $generation->id, 'reason_code' => 'NATIVE_DEPTH_ORIGINAL_PHASE_ADMITTED',
            'agent_ids' => [$selectedId]], $result['marker']);
        Bus::assertBatched(fn ($batch): bool => count($batch->jobs) === 1
            && $batch->jobs[0] instanceof EvaluateLabScreeningBatchJob
            && $batch->jobs[0]->labAgentIds === [$selectedId]
            && $batch->jobs[0]->labGenerationId === $generation->id);
        $this->assertSame('queued', $agents[$index]->fresh()->lifecycle_status);
        foreach ($others as $id => $before) $this->assertSame($before, LabAgent::findOrFail($id)->toArray());
        $this->assertDatabaseCount('lab_generations', 1);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        Http::assertNothingSent();
    }

    public static function originalPhases(): array
    {
        return [['cheap_pending', 4], ['deeper_ready', 5]];
    }

    public function test_original_queued_deeper_publication_requires_empty_all_stage_queues_and_no_run(): void
    {
        [$generation, $agents] = $this->generation();
        $deeper = $agents[5]; $deeper->update(['lifecycle_status' => 'queued']);
        config(['services.lab_queue.screening_queue' => 'fixture-screen', 'services.lab_queue.full_queue' => 'fixture-full',
            'services.lab_queue.full_validation_queue' => 'fixture-other-full']);
        $queues = ['fixture-screen', 'fixture-full', 'fixture-other-full'];
        $inspector = Mockery::mock(LabQueueJobInspector::class);
        $inspector->shouldReceive('queueSnapshot')->with($queues)->once()->andReturn(['available' => true, 'total' => 0]);
        $inspector->shouldReceive('hasAgentJob')->with($deeper->id, $queues)->once()->andReturn(false);
        $this->instance(LabQueueJobInspector::class, $inspector);
        $before = $deeper->fresh()->toArray();
        $this->assertSame([$deeper->id], $this->queuedPublicationIds($this->routingRecoverySeal($generation, $deeper)));
        $this->assertSame($before, $deeper->fresh()->toArray());
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        Bus::assertNothingBatched(); Http::assertNothingSent();
    }

    #[DataProvider('unsafeQueuedPublicationCases')]
    public function test_queued_deeper_publication_never_repairs_unknown_active_or_changed_ownership(string $case): void
    {
        [$generation, $agents] = $this->generation();
        $deeper = $agents[5]; $deeper->update(['lifecycle_status' => 'queued']);
        $seal = $this->routingRecoverySeal($generation, $deeper);
        $snapshot = ['available' => true, 'total' => 0]; $hasJob = false;
        $beforeQueueGuard = false;
        switch ($case) {
            case 'generation_drift': $seal['generation_id']++; $beforeQueueGuard = true; break;
            case 'model_drift': $seal['members']['deeper']['model_id'] = $agents[4]->model_version_id; $beforeQueueGuard = true; break;
            case 'missing_owner': unset($seal['members']['deeper']); $beforeQueueGuard = true; break;
            case 'draft': case 'technical_quarantine':
                $deeper->update(['lifecycle_status' => $case]); $beforeQueueGuard = true; break;
            case 'existing_screening_run': case 'existing_full_run':
                LabEvaluationRun::create(['run_id' => (string) \Illuminate\Support\Str::uuid(), 'lab_generation_id' => $generation->id,
                    'lab_agent_id' => $deeper->id, 'model_version_id' => $deeper->model_version_id,
                    'phase' => $case === 'existing_full_run' ? 'full' : 'screening', 'status' => 'started']);
                $beforeQueueGuard = true; break;
            case 'unknown_queue': $snapshot = ['available' => false]; break;
            case 'missing_count': $snapshot = ['available' => true]; break;
            case 'string_count': $snapshot['total'] = '0'; break;
            case 'nonempty_full_or_screening_queue': $snapshot['total'] = 1; break;
            case 'owned_job_despite_zero_snapshot': $hasJob = true; break;
            case 'empty_queue_alias': config()->set('services.lab_queue.full_queue', ''); $beforeQueueGuard = true; break;
        }
        $inspector = Mockery::mock(LabQueueJobInspector::class);
        if ($beforeQueueGuard) $inspector->shouldNotReceive('queueSnapshot');
        else $inspector->shouldReceive('queueSnapshot')->once()->andReturn($snapshot);
        if (($snapshot['available'] ?? null) === true && ($snapshot['total'] ?? null) === 0 && ! $beforeQueueGuard) {
            $inspector->shouldReceive('hasAgentJob')->once()->andReturn($hasJob);
        } else $inspector->shouldNotReceive('hasAgentJob');
        $this->instance(LabQueueJobInspector::class, $inspector);
        $before = $deeper->fresh()->toArray(); $runCount = LabEvaluationRun::count();
        $this->assertSame([], $this->queuedPublicationIds($seal), $case);
        $this->assertSame($before, $deeper->fresh()->toArray());
        $this->assertDatabaseCount('lab_evaluation_runs', $runCount);
        Bus::assertNothingBatched(); Http::assertNothingSent();
    }

    public static function unsafeQueuedPublicationCases(): array
    {
        return array_map(fn ($case) => [$case], ['generation_drift', 'model_drift', 'missing_owner', 'draft', 'technical_quarantine',
            'existing_screening_run', 'existing_full_run', 'unknown_queue', 'missing_count', 'string_count',
            'nonempty_full_or_screening_queue', 'owned_job_despite_zero_snapshot', 'empty_queue_alias']);
    }

    public function test_existing_ordinary_cli_repairs_only_original_unused_queued_deeper_publication_without_reset(): void
    {
        [$generation, $agents] = $this->generation();
        $generation->update(['status' => 'screening']);
        $agents[4]->update(['lifecycle_status' => 'screened']);
        $deeper = $agents[5]; $deeper->update(['lifecycle_status' => 'queued']);
        $others = $agents->reject(fn ($agent) => $agent->id === $deeper->id)
            ->mapWithKeys(fn ($agent) => [$agent->id => $agent->fresh()->toArray()]);
        $seal = $this->routingRecoverySeal($generation, $deeper);
        $this->installRoutingServices($generation, $deeper->id, 'deeper_in_flight', permitPublication: true,
            queuedRecoverySeal: $seal);
        $result = $this->dispatch(['--expected-generation-id' => null], requireMarker: false);
        $this->assertSame(0, $result['exit'], $result['output']);
        $this->assertSame([], $result['markers']);
        Bus::assertBatched(fn ($batch): bool => count($batch->jobs) === 1
            && $batch->jobs[0] instanceof EvaluateLabScreeningBatchJob
            && $batch->jobs[0]->labAgentIds === [$deeper->id] && $batch->jobs[0]->labGenerationId === $generation->id);
        $this->assertSame('queued', $deeper->fresh()->lifecycle_status, 'Publication recovery never resets an original arm to draft.');
        foreach ($others as $id => $before) $this->assertSame($before, LabAgent::findOrFail($id)->toArray());
        $this->assertSame($seal, $this->routingRecoverySeal($generation->fresh(), $deeper->fresh()));
        $this->assertDatabaseCount('lab_generations', 1); $this->assertDatabaseCount('lab_evaluation_runs', 0);
        $this->assertDatabaseCount('agent_learning_settlements', 0); $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        Http::assertNothingSent();
    }

    /** Conditional routing proof only; the original source/selection owner is tested separately. */
    private function routingRecoverySeal(LabGeneration $generation, LabAgent $deeper): array
    {
        return ['generation_id' => (int) $generation->id,
            'members' => ['deeper' => ['agent_id' => (int) $deeper->id, 'model_id' => (int) $deeper->model_version_id]]];
    }

    private function queuedPublicationIds(array $seal): array
    {
        $reader = (new \ReflectionClass(NativeReachabilityDepthAuditService::class))->newInstanceWithoutConstructor();
        return (new \ReflectionMethod($reader, 'originalQueuedDeeperPublicationIds'))->invoke($reader, $seal);
    }

    public function test_default_command_keeps_ordinary_exit_zero_without_a_depth_marker(): void
    {
        $this->installRoutingServices();
        $this->instance(LabPopulationService::class, Mockery::mock(LabPopulationService::class)
            ->shouldReceive('ensureLaboratories')->once()->getMock());
        $result = $this->dispatch(['--expected-generation-id' => null], requireMarker: false);
        $this->assertSame(0, $result['exit']);
        $this->assertSame([], $result['markers']);
        $this->assertStringContainsString('Lab queue state unavailable', $result['output']);
    }

    private function dispatch(array $options, bool $requireMarker = true): array
    {
        $exit = Artisan::call('trading:dispatch-lab', ['symbol' => 'XAUUSD', '--timeframe' => 'H1',
            '--resume-draft-agents' => true, ...$options]);
        $output = Artisan::output();
        $markers = [];
        foreach (preg_split('/\R/', $output) as $line) {
            $value = json_decode($line, true);
            if (is_array($value) && ($value['protocol'] ?? null) === 'native_depth_audit_dispatch_v1') $markers[] = $value;
        }
        if ($requireMarker) $this->assertCount(1, $markers, $output);
        return ['exit' => $exit, 'marker' => $markers[0] ?? null, 'markers' => $markers, 'output' => $output];
    }

    private function assertRefused(array $result, string $reason, int $generationId): void
    {
        $this->assertSame(1, $result['exit'], $result['output']);
        $this->assertSame(['protocol' => 'native_depth_audit_dispatch_v1', 'status' => 'refused',
            'generation_id' => $generationId, 'reason_code' => $reason, 'agent_ids' => []], $result['marker']);
    }

    private function generation(): array
    {
        $lab = AiLaboratory::create(['symbol' => 'XAUUSD', 'name' => 'Pure native dispatch routing fixture',
            'timeframe' => 'H1', 'strategy_families' => ['ema_rsi'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse']);
        $manifest = ['protocol' => 'routing_fixture_only', 'bundle_hash' => str_repeat('a', 64)];
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1,
            'trigger_type' => 'historical_research', 'population_size' => 6, 'status' => 'draft',
            'trigger_context' => ['native_specialist_council_intent' => ['research_purpose' => NativeReachabilityDepthAuditService::PURPOSE,
                'routing_fixture_only' => true], 'mtf_bundle_hash' => $manifest['bundle_hash'], 'mtf_bundle_manifest' => $manifest,
                'constructor_audit' => ['planned_slots' => 6, 'created_agents' => 6]]]);
        $agents = collect();
        foreach (['scalp', 'hour', 'day', 'swing', 'cheap', 'deeper'] as $role) {
            $model = ModelVersion::create(['name' => 'Pure dispatch '.$role, 'strategy' => 'ema_rsi_v1', 'version' => 'routing-fixture-'.$role,
                'generation' => 1, 'status' => 'testing', 'parameters' => [], 'metadata' => ['routing_fixture_only' => true,
                    'recovery_protocol' => ['protocol' => 'bounded_root_recovery_v1']]]);
            $agents->push(LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'ema_rsi', 'origin' => 'routing_fixture',
                'lifecycle_status' => 'draft', 'parameter_diff' => []]));
        }
        return [$generation, $agents];
    }

    private function installRoutingServices(?LabGeneration $generation = null, ?int $selectedId = null,
        string $phase = 'cheap_pending', bool $prepared = true, ?array $snapshot = null, bool $permitPublication = false,
        ?array $queuedRecoverySeal = null): void
    {
        foreach ([LabPopulationService::class, LabDatasetExportService::class, MultiTimeframeSnapshotService::class,
            MarketDataContinuityService::class, LabImmutableEvidenceService::class, CandidateHandoffService::class,
            LabAgentPreflightService::class, LearningProtocolSafetyService::class, LearningTechnicalCircuitBreakerService::class,
            LearningEvidenceGate::class, LabQueueJobInspector::class, StrategyParameterSchemaService::class,
            GenerationSnapshotAdmissionService::class, GenerationConstructionAdmissionService::class,
            SpecialistCouncilPreparationService::class, NativeReachabilityDepthAuditService::class, ResearchReleaseSealService::class] as $class) {
            $this->instance($class, Mockery::mock($class));
        }
        if ($queuedRecoverySeal === null) app(LabPopulationService::class)->shouldNotReceive('ensureLaboratories');
        else app(LabPopulationService::class)->shouldReceive('ensureLaboratories')->once();
        app(LabPopulationService::class)->shouldNotReceive('build');
        app(LearningProtocolSafetyService::class)->shouldReceive('generationCreationPaused')->andReturn(false);
        app(LearningTechnicalCircuitBreakerService::class)->shouldReceive('blocked')->andReturn(false);
        app(LabQueueJobInspector::class)->shouldReceive('hasAgentJob')->andReturn(false);
        app(LabQueueJobInspector::class)->shouldReceive('queueSnapshot')->andReturn($permitPublication
            ? ['available' => true, 'total' => 0] : ($snapshot ?? ['available' => false]));
        app(SpecialistCouncilPreparationService::class)->shouldReceive('hasNativeConstructorIntent')->andReturn(true);
        app(SpecialistCouncilPreparationService::class)->shouldReceive('isResearchGeneration')->andReturn($prepared);
        if ($queuedRecoverySeal === null) {
            app(SpecialistCouncilPreparationService::class)->shouldReceive('nativeDiagnosticDispatchAgentIds')->andReturn($selectedId === null ? [] : [$selectedId]);
        } else {
            app(SpecialistCouncilPreparationService::class)->shouldReceive('nativeDiagnosticDispatchAgentIds')
                ->andReturnUsing(fn () => $this->queuedPublicationIds($queuedRecoverySeal));
        }
        app(NativeReachabilityDepthAuditService::class)->shouldReceive('inspectContinuation')->andReturn(['status' => $phase,
            'generation_id' => $generation?->id, 'same_original_question' => true]);
        if (! $permitPublication) return;
        app(GenerationConstructionAdmissionService::class)->shouldReceive('inspect')->andReturn(['allowed' => true]);
        app(LabAgentPreflightService::class)->shouldReceive('inspect')
            ->with(Mockery::on(fn (LabAgent $agent): bool => $agent->id === $selectedId), 'screening')->once()
            ->andReturn(['passed' => true, 'errors' => []]);
        app(StrategyParameterSchemaService::class)->shouldReceive('canonicalizeForIdentity')->andReturn([]);
        if ($queuedRecoverySeal === null) app(LabDatasetExportService::class)->shouldReceive('export')->once();
        else app(LabDatasetExportService::class)->shouldNotReceive('export');
        app(LabDatasetExportService::class)->shouldReceive('ensureGenerationFoundationSnapshot')->once()->andReturn([]);
        app(LabDatasetExportService::class)->shouldReceive('ensureGenerationSnapshot')->once()->andReturn([]);
        app(LabDatasetExportService::class)->shouldReceive('assertGenerationDataPartition')->once();
        app(MultiTimeframeSnapshotService::class)->shouldReceive('restoreAgentOwnedConfirmationValidationBundle')->once()
            ->andReturn(['bundle_hash' => str_repeat('a', 64), 'manifest' => data_get($generation->trigger_context, 'mtf_bundle_manifest')]);
        app(ResearchReleaseSealService::class)->shouldReceive('seal')->once()->andReturnUsing(fn ($model) => $model);
        app(GenerationSnapshotAdmissionService::class)->shouldReceive('inspect')->once()->andReturn(['allowed' => true, 'reasons' => []]);
        if ($queuedRecoverySeal === null) app(LabImmutableEvidenceService::class)->shouldReceive('recordAgentStatusChanged')->once();
        else app(LabImmutableEvidenceService::class)->shouldNotReceive('recordAgentStatusChanged');
        if ($queuedRecoverySeal !== null) app(LabImmutableEvidenceService::class)->shouldReceive('recordLifecycle')->once();
    }
}
