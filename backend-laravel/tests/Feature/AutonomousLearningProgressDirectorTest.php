<?php

namespace Tests\Feature;

use App\Jobs\EvaluateLabAgentJob;
use App\Models\AiLaboratory;
use App\Services\AutonomousLearningProgressDirectorService;
use App\Services\ExecutionContractService;
use App\Services\LabDatasetExportService;
use App\Services\LabQueueJobInspector;
use App\Services\MultiTimeframeSnapshotService;
use App\Services\RuntimeMonitoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class AutonomousLearningProgressDirectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_opens_exactly_one_pre2026_edge_cohort_and_then_waits_idempotently(): void
    {
        Queue::fake();
        config()->set('services.edge_director.idle_stability_seconds', 0);
        AiLaboratory::create(['name' => 'Autonomous Mastery XAUUSD H1', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse']);

        $runtime = Mockery::mock(RuntimeMonitoringService::class);
        $runtime->shouldReceive('inspect')->andReturn(['checks' => [
            'redis' => ['status' => 'ok'], 'queue' => ['status' => 'ok'],
            'ai_service' => ['status' => 'ok', 'metrics' => [
                'active_requests' => 0, 'screening_active' => 0, 'full_active' => 0,
                'service_pid' => 1234, 'screening_capacity' => 1,
            ]],
            'scheduler' => ['status' => 'ok'],
        ]]);
        $this->app->instance(RuntimeMonitoringService::class, $runtime);
        $queues = Mockery::mock(LabQueueJobInspector::class);
        $queues->shouldReceive('queueSnapshot')->andReturn(['available' => true, 'rows' => []]);
        $queues->shouldReceive('runnableLabQueueBacklog')->andReturn(['total' => 0, 'queues' => [], 'delayed' => 0]);
        $queues->shouldReceive('labQueues')->andReturn(['lab-frontier', 'lab-replay', 'lab-learning']);
        $this->app->instance(LabQueueJobInspector::class, $queues);
        $mtf = Mockery::mock(MultiTimeframeSnapshotService::class);
        $mtf->shouldReceive('agentValidationReadiness')->once()->with('XAUUSD')->andReturn(['ready' => true]);
        $mtfHash = str_repeat('c', 64);
        $mtf->shouldReceive('forAgentOwnedConfirmationValidation')->once()->with('XAUUSD')->andReturn([
            'bundle_hash' => $mtfHash,
            'manifest' => [
                'bundle_hash' => $mtfHash, 'validation_bundle_protocol' => 'agent_owned_mtf_foundation_bundle_v1',
                'data_role' => 'pre_2026_foundation_training_only', 'promotion_evidence' => false,
            ],
        ]);
        $this->app->instance(MultiTimeframeSnapshotService::class, $mtf);
        $fixtureDirectory = storage_path('framework/testing/autonomous-edge-'.Str::uuid());
        File::ensureDirectoryExists($fixtureDirectory);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($fixtureDirectory));
        $foundationPath = $fixtureDirectory.'/foundation.csv';
        $paperPath = $fixtureDirectory.'/paper.csv';
        File::put($foundationPath, 'pre-2026-foundation');
        File::put($paperPath, '2026-paper-only');
        File::put($paperPath.'.manifest.json', json_encode([
            'data_role' => 'paper_only',
            'training_end_exclusive' => '2026-01-01T00:00:00+00:00',
            'promotion_evidence' => false,
        ]));
        $datasets = Mockery::mock(LabDatasetExportService::class);
        $datasets->shouldReceive('ensureFoundationDataset')->once()->with('XAUUSD', 'H1')->andReturn([
            'protocol' => 'foundation_training_archive_v1',
            'path' => $foundationPath,
            'sha256' => hash_file('sha256', $foundationPath),
            'manifest' => ['last_candle_at' => '2025-12-31T23:00:00Z', 'source_role' => 'foundation_training_only',
                'continuity' => ['status' => 'ready', 'unexpected_gap_count' => 0], 'promotion_evidence' => false],
        ]);
        $datasets->shouldReceive('exportPaper')->once()->with('XAUUSD', 'H1', false)->andReturn($paperPath);
        $this->app->instance(LabDatasetExportService::class, $datasets);
        $execution = Mockery::mock(ExecutionContractService::class);
        $execution->shouldReceive('for')->twice()->with('XAUUSD', 'M5')->andReturn([
            'protocol' => ExecutionContractService::PROTOCOL,
            'version' => ExecutionContractService::VERSION,
            'symbol' => 'XAUUSD',
            'timeframe' => 'M5',
            'parameters' => [],
            'execution_hash' => str_repeat('b', 64),
        ]);
        $this->app->instance(ExecutionContractService::class, $execution);

        $first = app(AutonomousLearningProgressDirectorService::class)->advance('XAUUSD', 'H1', true);
        $this->assertSame('advanced', $first['status']);
        $this->assertSame('EDGE_GENESIS', $first['action']);
        $this->assertSame(1, $first['expensive_cohorts_opened']);
        $this->assertDatabaseCount('lab_generations', 1);
        $this->assertDatabaseCount('lab_agents', 20);
        Queue::assertPushed(EvaluateLabAgentJob::class, 20);

        // The active agent boundary stops a second cohort before any data or
        // execution identity is requested again.
        $second = app(AutonomousLearningProgressDirectorService::class)->advance('XAUUSD', 'H1', true);
        $this->assertSame('blocked', $second['status']);
        $this->assertSame('ACTIVE_AGENT_WORK_EXISTS', $second['reason']);
        $this->assertDatabaseCount('lab_generations', 1);

        $generation = \App\Models\LabGeneration::query()->firstOrFail();
        $generation->update(['status' => 'technical_quarantine', 'completed_at' => now()]);
        $generation->agents()->update(['lifecycle_status' => 'draft']);
        $third = app(AutonomousLearningProgressDirectorService::class)->advance('XAUUSD', 'H1', true);
        $this->assertSame('blocked', $third['status']);
        $this->assertNotSame('ACTIVE_AGENT_WORK_EXISTS', $third['reason']);
        $this->assertSame('EDGE_GENESIS_SETTLEMENT_INCOMPLETE', $third['reason']);
    }
}
