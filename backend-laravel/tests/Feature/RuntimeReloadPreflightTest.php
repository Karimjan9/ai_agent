<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabGeneration;
use App\Services\LabQueueJobInspector;
use App\Services\AutonomousModeService;
use App\Services\ObservedCouncilEpisodeDispositionService;
use App\Services\RuntimeReloadPreflightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class RuntimeReloadPreflightTest extends TestCase
{
    use RefreshDatabase;

    public function test_delayed_research_is_visible_but_does_not_block_an_idle_reload(): void
    {
        $queues = Mockery::mock(LabQueueJobInspector::class);
        $queues->shouldReceive('reservedQueueBacklog')->once()->andReturn([
            'total' => 0, 'queues' => ['lab-frontier' => 0], 'pending' => 0, 'delayed' => 1,
        ]);

        $result = (new RuntimeReloadPreflightService($queues))->inspect();

        $this->assertTrue($result['safe']);
        $this->assertSame('IDLE', $result['reason']);
        $this->assertSame(1, data_get($result, 'reserved_queue.delayed'));
    }

    public function test_runnable_job_or_active_generation_refuses_reload(): void
    {
        $queues = Mockery::mock(LabQueueJobInspector::class);
        $queues->shouldReceive('reservedQueueBacklog')->twice()->andReturn(
            ['total' => 1, 'queues' => ['lab-frontier' => 1], 'pending' => 0, 'delayed' => 0],
            ['total' => 0, 'queues' => [], 'pending' => 0, 'delayed' => 0],
        );
        $service = new RuntimeReloadPreflightService($queues);
        $this->assertSame('RESERVED_WORKER_JOB_EXISTS', $service->inspect()['reason']);

        $lab = AiLaboratory::create([
            'name' => 'Reload safety lab', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse',
        ]);
        LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 1,
            'status' => 'queued', 'population_size' => 1, 'trigger_type' => 'edge_genesis',
        ]);

        $result = $service->inspect();
        $this->assertFalse($result['safe']);
        $this->assertSame('ACTIVE_GENERATION_EXISTS', $result['reason']);
    }

    public function test_explicit_cold_maintenance_requires_exact_proof_but_default_stays_strict(): void
    {
        $generation = $this->activeProjection();
        $service = $this->emptyQueueService(2);
        $mode = Mockery::mock(AutonomousModeService::class);
        $mode->shouldReceive('status')->once()->andReturn(['enabled' => false, 'state' => 'draining']);
        $this->app->instance(AutonomousModeService::class, $mode);
        $disposition = Mockery::mock(ObservedCouncilEpisodeDispositionService::class);
        $disposition->shouldReceive('inspectGeneration')->once()->andReturn([
            'allowed' => true, 'proof' => ['generation_id' => $generation->id, 'proof_hash' => str_repeat('a', 64)],
            'promotion_evidence' => false,
        ]);
        $this->app->instance(ObservedCouncilEpisodeDispositionService::class, $disposition);

        $this->assertFalse($service->inspect()['safe']);
        $result = $service->inspect($generation->id);
        $this->assertTrue($result['safe']);
        $this->assertSame('TERMINAL_PROJECTION_COLD_MAINTENANCE_READY', $result['reason']);
        $this->assertFalse($result['rolling_reload_allowed']);
        $this->assertTrue($result['replay_idle_probe_required']);
        $this->assertFalse($result['scientific_evidence']);
    }

    public function test_cold_maintenance_refuses_unknown_pending_delayed_or_reserved_queue(): void
    {
        foreach ([['pending' => null], ['total' => 1], ['pending' => 1], ['delayed' => 1], ['total' => '0']] as $delta) {
            $queues = Mockery::mock(LabQueueJobInspector::class);
            $queues->shouldReceive('reservedQueueBacklog')->once()->andReturn([
                'total' => 0, 'pending' => 0, 'delayed' => 0, 'queues' => [], ...$delta,
            ]);
            $result = (new RuntimeReloadPreflightService($queues))->inspect(355);
            $this->assertFalse($result['safe']);
            $this->assertContains($result['reason'], ['QUEUE_STATE_UNKNOWN', 'QUEUE_WORK_REMAINS']);
        }
    }

    public function test_cold_maintenance_refuses_other_active_generation_or_missing_target(): void
    {
        $generation = $this->activeProjection();
        $service = $this->emptyQueueService(2);
        $this->assertFalse($service->inspect($generation->id + 1)['safe']);
        LabGeneration::create(['ai_laboratory_id' => $generation->ai_laboratory_id, 'generation' => 2,
            'status' => 'queued', 'population_size' => 1, 'trigger_type' => 'edge_genesis']);
        $this->assertFalse($service->inspect($generation->id)['safe']);
    }

    public function test_cold_maintenance_never_overrides_running_pause_or_safety_halt(): void
    {
        $generation = $this->activeProjection();
        $service = $this->emptyQueueService(3);
        $mode = Mockery::mock(AutonomousModeService::class);
        $mode->shouldReceive('status')->times(3)->andReturn(
            ['enabled' => true, 'state' => 'running'], ['enabled' => false, 'state' => 'paused'],
            ['enabled' => false, 'state' => 'safety_halt']);
        $this->app->instance(AutonomousModeService::class, $mode);
        foreach (range(1, 3) as $_) {
            $this->assertSame('DRAIN_FIRST_STOP_REQUIRED', $service->inspect($generation->id)['reason']);
        }
    }

    public function test_cold_maintenance_refuses_missing_or_authority_granting_proof(): void
    {
        $generation = $this->activeProjection();
        $service = $this->emptyQueueService(3);
        $mode = Mockery::mock(AutonomousModeService::class);
        $mode->shouldReceive('status')->times(3)->andReturn(['enabled' => false, 'state' => 'stopped']);
        $this->app->instance(AutonomousModeService::class, $mode);
        $disposition = Mockery::mock(ObservedCouncilEpisodeDispositionService::class);
        $disposition->shouldReceive('inspectGeneration')->times(3)->andReturn(
            ['allowed' => false, 'promotion_evidence' => false, 'proof' => ['id' => 1]],
            ['allowed' => true, 'promotion_evidence' => false, 'proof' => []],
            ['allowed' => true, 'promotion_evidence' => true, 'proof' => ['id' => 1]]);
        $this->app->instance(ObservedCouncilEpisodeDispositionService::class, $disposition);
        foreach (range(1, 3) as $_) {
            $this->assertSame('TERMINAL_PROJECTION_RECOVERY_PROOF_REFUSED', $service->inspect($generation->id)['reason']);
        }
    }

    public function test_cli_refuses_invalid_explicit_target(): void
    {
        $this->artisan('system:runtime-reload-preflight', ['--json' => true, '--terminal-projection-recovery' => '0'])
            ->assertExitCode(1);
    }

    private function emptyQueueService(int $calls): RuntimeReloadPreflightService
    {
        $queues = Mockery::mock(LabQueueJobInspector::class);
        $queues->shouldReceive('reservedQueueBacklog')->times($calls)->andReturn([
            'total' => 0, 'queues' => [], 'pending' => 0, 'delayed' => 0,
        ]);
        return new RuntimeReloadPreflightService($queues);
    }

    private function activeProjection(): LabGeneration
    {
        $lab = AiLaboratory::create(['name' => 'Projection-only cold maintenance', 'symbol' => 'XAUUSD',
            'timeframe' => 'H1', 'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse']);
        return LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1,
            'status' => 'screening', 'population_size' => 6, 'trigger_type' => 'edge_genesis']);
    }
}
