<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabGeneration;
use App\Services\LearningProtocolSafetyService;
use App\Services\LighthouseVerticalLoopMonitoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LighthouseVerticalLoopMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitor_is_fail_closed_outside_the_lighthouse_scope(): void
    {
        $report = app(LighthouseVerticalLoopMonitoringService::class)->inspect('EURUSD', 'H1');

        $this->assertSame('critical', $report['status']);
        $this->assertSame('scope_blocked', $report['current_stage']);
        $this->assertFalse($report['promotion_evidence']);
        $this->assertDatabaseHas('lighthouse_vertical_loop_monitor_runs', [
            'symbol' => 'EURUSD',
            'timeframe' => 'H1',
            'stage' => 'scope_blocked',
        ]);
    }

    public function test_monitor_records_the_first_missing_milestone_without_mutating_strategy_state(): void
    {
        Http::fake([
            '*' => Http::response([
                'active_requests' => 0,
                'protocol' => 'replay_liveness_v2_bounded_worker',
            ], 200),
        ]);

        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD',
            'name' => 'XAUUSD Lighthouse',
            'timeframe' => 'H1',
            'strategy_families' => [],
            'lifecycle_mode' => 'lighthouse',
            'is_active' => true,
        ]);
        LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 1,
            'trigger_type' => 'test',
            'population_size' => 20,
            'status' => 'screened',
        ]);
        app(LearningProtocolSafetyService::class)->pauseGenerationCreation('monitor_test');

        $report = app(LighthouseVerticalLoopMonitoringService::class)->inspect('XAUUSD', 'M5');

        $this->assertSame('H1', $report['timeframe']);
        $this->assertSame('M5', data_get($report, 'organism.execution_timeframe'));
        $this->assertSame('candidate', $report['current_stage']);
        $this->assertSame('passed', collect($report['checks'])->firstWhere('code', 'STOP_LINE')['status']);
        $this->assertSame('passed', collect($report['checks'])->firstWhere('code', 'MTF_ROLE_CONTRACT')['status']);
        $this->assertSame('attention', collect($report['checks'])->firstWhere('code', 'REPRODUCIBLE_CANDIDATE')['status']);
        $this->assertFalse($report['milestones']['full_replay']['ready']);
        $this->assertFalse($report['promotion_evidence']);
        $this->assertDatabaseCount('lighthouse_vertical_loop_monitor_runs', 1);
        $this->assertDatabaseHas('service_health_checks', [
            'service_key' => 'lighthouse_vertical_loop:XAUUSD:H1',
            'status' => 'warning',
        ]);
    }

    public function test_monitor_defers_disposable_persistence_while_population_constructor_owns_generation(): void
    {
        Http::fake([
            '*' => Http::response([
                'active_requests' => 0,
                'protocol' => 'replay_liveness_v2_bounded_worker',
            ], 200),
        ]);

        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD',
            'name' => 'XAUUSD Lighthouse',
            'timeframe' => 'H1',
            'strategy_families' => [],
            'lifecycle_mode' => 'lighthouse',
            'is_active' => true,
        ]);
        LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 1,
            'trigger_type' => 'test',
            'population_size' => 20,
            'status' => 'screened',
        ]);

        $lock = Cache::lock('lab-population-constructor:XAUUSD:H1:v1', 60);
        $this->assertTrue($lock->get());
        try {
            $report = app(LighthouseVerticalLoopMonitoringService::class)->inspect('XAUUSD', 'H1');
        } finally {
            $lock->release();
        }

        $this->assertSame('deferred', data_get($report, 'monitor_persistence.status'));
        $this->assertSame('POPULATION_CONSTRUCTOR_ACTIVE', data_get($report, 'monitor_persistence.reason_code'));
        $this->assertFalse(data_get($report, 'monitor_persistence.canonical_evidence_affected'));
        $this->assertNull($report['monitor_run_id']);
        $this->assertDatabaseCount('lighthouse_vertical_loop_monitor_runs', 0);
        $this->assertDatabaseMissing('service_health_checks', [
            'service_key' => 'lighthouse_vertical_loop:XAUUSD:H1',
        ]);
    }
}
