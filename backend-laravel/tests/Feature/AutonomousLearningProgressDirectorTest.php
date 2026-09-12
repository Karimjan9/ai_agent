<?php

namespace Tests\Feature;

use App\Jobs\EvaluateLabAgentJob;
use App\Models\AiLaboratory;
use App\Models\LabGeneration;
use App\Models\LabSkillZooEntry;
use App\Services\AutonomousLearningProgressDirectorService;
use App\Services\ExecutionContractService;
use App\Services\LabDatasetExportService;
use App\Services\LabQueueJobInspector;
use App\Services\MultiTimeframeSnapshotService;
use App\Services\RuntimeMonitoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        config()->set('services.edge_director.autonomous_specialized_cohorts_enabled', true);
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

        $generation = LabGeneration::query()->firstOrFail();
        $generation->update(['status' => 'technical_quarantine', 'completed_at' => now()]);
        $generation->agents()->update(['lifecycle_status' => 'draft']);
        $third = app(AutonomousLearningProgressDirectorService::class)->advance('XAUUSD', 'H1', true);
        $this->assertSame('blocked', $third['status']);
        $this->assertNotSame('ACTIVE_AGENT_WORK_EXISTS', $third['reason']);
        $this->assertSame('EDGE_GENESIS_SETTLEMENT_INCOMPLETE', $third['reason']);

        // Production mode keeps the immutable Edge history but hands new
        // generation authority to the normal twenty-seat organism lifecycle.
        DB::table('edge_genesis_trials')->update([
            'status' => 'edge_not_found', 'settled_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('edge_genesis_passports')->update([
            'status' => 'edge_not_found', 'updated_at' => now(),
        ]);
        $generation->update(['status' => 'completed', 'completed_at' => now()]);
        $generation->agents()->update(['lifecycle_status' => 'rejected']);
        config()->set('services.edge_director.autonomous_specialized_cohorts_enabled', false);

        $baseline = $generation->agents()->firstOrFail();
        $provisional = LabSkillZooEntry::create([
            'skill_key' => hash('sha256', 'director-provisional-skill'),
            'cartridge_key' => hash('sha256', 'director-provisional-cartridge'),
            'revision' => 2, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'module_key' => 'entry', 'niche_key' => 'trend_up|normal|london', 'gene_key' => 'minimum_confidence',
            'lab_agent_id' => $baseline->id, 'model_version_id' => $baseline->model_version_id,
            'causal_baseline_agent_id' => $baseline->id, 'quality_score' => .2, 'confidence' => .8,
            'status' => 'provisional', 'component_status' => 'paired_observed', 'organism_viability' => 'not_viable',
            'evidence' => ['intervention' => ['old_value' => .6, 'tested_value' => .55]],
        ]);
        foreach ([1, 2] as $observation) {
            DB::table('skill_cartridge_observations')->insert([
                'observation_key' => hash('sha256', 'director-observation-'.$observation),
                'lab_skill_zoo_entry_id' => $provisional->id, 'outcome' => 'positive',
                'target_delta' => .1, 'evidence' => json_encode(['window' => $observation]),
                'observed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $confirmation = app(AutonomousLearningProgressDirectorService::class)->advance('XAUUSD', 'H1', false);
        $this->assertSame('dry_run', $confirmation['status']);
        $this->assertSame('PROVISIONAL_SKILL_CARTRIDGE_CONFIRMATION', $confirmation['action']);
        $this->assertSame(20, data_get($confirmation, 'result.population_size'));
        $provisional->delete();

        $handoff = app(AutonomousLearningProgressDirectorService::class)->advance('XAUUSD', 'M15', true);

        $this->assertSame('waiting', $handoff['status']);
        $this->assertSame('RUN_XAUUSD_ORGANISM_LIFECYCLE', $handoff['action']);
        $this->assertSame('NORMAL_TWENTY_GENERATION_HANDOFF', $handoff['reason']);
        $this->assertSame(20, data_get($handoff, 'generation_contract.population_size'));
        $this->assertSame('XAUUSD', data_get($handoff, 'organism.symbol'));
        $this->assertSame('H1', data_get($handoff, 'organism.laboratory_storage_timeframe'));
        $this->assertSame(1, LabGeneration::query()->count());
    }
}
