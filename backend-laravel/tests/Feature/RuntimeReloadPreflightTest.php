<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabGeneration;
use App\Services\LabQueueJobInspector;
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
}
