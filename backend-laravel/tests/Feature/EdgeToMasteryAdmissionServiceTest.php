<?php

namespace Tests\Feature;

use App\Services\EdgeToMasteryAdmissionService;
use App\Services\FailureDojoService;
use App\Services\LabQueueJobInspector;
use App\Services\RuntimeMonitoringService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class EdgeToMasteryAdmissionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_delayed_job_alone_is_not_a_retry_storm_and_idle_runtime_is_admitted(): void
    {
        config()->set('services.edge_director.idle_stability_seconds', 0);
        $this->mockRuntime('ok', ['active_requests' => 0, 'screening_active' => 0, 'full_active' => 0]);
        $this->mockQueues([
            ['queue' => 'lab-frontier', 'redis_state' => 'delayed', 'attempts' => 0,
                'payload' => json_encode(['displayName' => 'DeferredResearchJob'])],
        ]);
        $this->mockDojo();

        $result = app(EdgeToMasteryAdmissionService::class)->assess('XAUUSD', 'H1', true);

        $this->assertTrue($result['admitted'], json_encode($result));
        $this->assertFalse($result['retry_storm']['detected']);
        $this->assertTrue($result['retry_storm']['delayed_jobs_are_not_a_storm_by_themselves']);
        $this->assertTrue($result['failure_dojo']['healthy']);
        $this->assertFalse($result['failure_dojo']['actionable_pending_blocks_genesis']);
    }

    public function test_warning_runtime_busy_ai_and_repeated_reserved_job_fail_closed(): void
    {
        config()->set('services.edge_director.idle_stability_seconds', 0);
        $this->mockRuntime('warning', ['active_requests' => 1, 'screening_active' => 1, 'full_active' => 0]);
        $this->mockQueues([
            ['queue' => 'lab-frontier', 'redis_state' => 'reserved', 'attempts' => 5,
                'payload' => json_encode(['displayName' => 'EvaluateLabAgentJob'])],
        ]);
        $this->mockDojo();

        $result = app(EdgeToMasteryAdmissionService::class)->assess('XAUUSD', 'H1', true);

        $this->assertFalse($result['admitted']);
        $this->assertContains('RUNTIME_NOT_HEALTHY', $result['blockers']);
        $this->assertContains('QUEUE_RETRY_STORM', $result['blockers']);
        $this->assertContains('AI_REPLAY_LANE_NOT_IDLE', $result['blockers']);
        $this->assertTrue($result['retry_storm']['detected']);
    }

    public function test_two_idle_observations_separated_by_ten_seconds_are_stable(): void
    {
        config()->set('services.edge_director.idle_stability_seconds', 10);
        config()->set('services.edge_director.idle_stability_max_age_seconds', 180);
        Carbon::setTestNow('2026-09-01 12:00:00');
        $this->mockRuntime('ok', ['active_requests' => 0, 'screening_active' => 0, 'full_active' => 0], 2);
        $this->mockQueues([], 2);
        $this->mockDojo(2);

        $first = app(EdgeToMasteryAdmissionService::class)->assess('XAUUSD', 'H1', true);
        $this->assertFalse($first['admitted']);
        $this->assertSame(1, $first['idle_stability']['observations']);

        Carbon::setTestNow('2026-09-01 12:00:11');
        $second = app(EdgeToMasteryAdmissionService::class)->assess('XAUUSD', 'H1', true);

        $this->assertTrue($second['admitted'], json_encode($second));
        $this->assertTrue($second['idle_stability']['stable']);
        $this->assertSame(2, $second['idle_stability']['observations']);
        $this->assertSame(11, $second['idle_stability']['elapsed_seconds']);
    }

    public function test_rapid_matching_probe_does_not_move_the_idle_stability_window(): void
    {
        config()->set('services.edge_director.idle_stability_seconds', 10);
        config()->set('services.edge_director.idle_stability_max_age_seconds', 180);
        Carbon::setTestNow('2026-09-01 12:00:00');
        $this->mockRuntime('ok', ['active_requests' => 0, 'screening_active' => 0, 'full_active' => 0], 3);
        $this->mockQueues([], 3);
        $this->mockDojo(3);

        $first = app(EdgeToMasteryAdmissionService::class)->assess('XAUUSD', 'H1', true);
        $this->assertFalse($first['admitted']);

        Carbon::setTestNow('2026-09-01 12:00:08');
        $early = app(EdgeToMasteryAdmissionService::class)->assess('XAUUSD', 'H1', true);
        $this->assertFalse($early['admitted']);
        $this->assertSame(8, $early['idle_stability']['elapsed_seconds']);

        Carbon::setTestNow('2026-09-01 12:00:11');
        $stable = app(EdgeToMasteryAdmissionService::class)->assess('XAUUSD', 'H1', true);
        $this->assertTrue($stable['admitted'], json_encode($stable));
        $this->assertSame(11, $stable['idle_stability']['elapsed_seconds']);
    }

    private function mockRuntime(string $schedulerStatus, array $aiMetrics, int $times = 1): void
    {
        $runtime = Mockery::mock(RuntimeMonitoringService::class);
        $runtime->shouldReceive('inspect')->times($times)->with(false)->andReturn(['checks' => [
            'redis' => ['status' => 'ok'], 'queue' => ['status' => 'ok'],
            'ai_service' => ['status' => 'ok', 'metrics' => [...$aiMetrics,
                'service_pid' => 100, 'screening_capacity' => 1]],
            'scheduler' => ['status' => $schedulerStatus],
        ]]);
        $this->app->instance(RuntimeMonitoringService::class, $runtime);
    }

    private function mockQueues(array $rows, int $times = 1): void
    {
        $queues = Mockery::mock(LabQueueJobInspector::class);
        $queues->shouldReceive('queueSnapshot')->times($times)->andReturn(['available' => true, 'rows' => $rows]);
        $queues->shouldReceive('runnableLabQueueBacklog')->times($times)->andReturn([
            'total' => 0, 'pending' => 0, 'reserved' => 0, 'delayed' => count($rows), 'queues' => [],
        ]);
        $queues->shouldReceive('labQueues')->times($times)->andReturn(['lab-frontier', 'lab-learning']);
        $this->app->instance(LabQueueJobInspector::class, $queues);
    }

    private function mockDojo(int $times = 1): void
    {
        $dojo = Mockery::mock(FailureDojoService::class);
        $dojo->shouldReceive('summary')->times($times)->with('XAUUSD', 'H1')->andReturn([
            'available' => true, 'legacy_invalid_pending' => 0, 'actionable_pending' => 12,
        ]);
        $this->app->instance(FailureDojoService::class, $dojo);
    }
}
