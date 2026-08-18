<?php

namespace Tests\Feature;

use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Services\LabIncrementalEvaluationService;
use App\Services\LabPopulationService;
use App\Services\LabQueueJobInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LabIncrementalEvaluationTest extends TestCase
{
    use RefreshDatabase;

    public function test_incremental_health_check_yields_to_waiting_full_validation(): void
    {
        $model = ModelVersion::create([
            'name' => 'incremental-guard-'.uniqid(),
            'status' => 'active',
            'strategy' => 'trend_following',
            'version' => 'guard-test',
            'parameters' => [],
            'metadata' => [],
        ]);

        ModelMarketPerformance::create([
            'model_version_id' => $model->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'trend',
            'status' => 'champion',
            'champion_slot' => 'primary',
            'fitness' => 10,
            'forward_score' => 10,
            'sample_count' => 250,
            'rolling_windows_count' => 3,
            'rolling_forward_wins' => 2,
            'metrics' => [],
        ]);

        $this->mock(LabQueueJobInspector::class, function ($mock): void {
            $mock->shouldReceive('fullValidationIsWaiting')->once()->andReturnTrue();
        });

        $summary = app(LabIncrementalEvaluationService::class)->evaluateChampions();

        $this->assertSame(0, $summary['checked']);
        $this->assertSame(0, $summary['degraded']);
        $this->assertSame(1, $summary['skipped']);
        $this->assertTrue($summary['deferred']);
        $this->assertSame('full_validation_lane_waiting', $summary['deferred_reason']);
    }

    public function test_deferred_incremental_command_does_not_build_degradation_generation(): void
    {
        $this->mock(LabIncrementalEvaluationService::class, function ($mock): void {
            $mock->shouldReceive('evaluateChampions')->once()->andReturn([
                'checked' => 0,
                'degraded' => 0,
                'skipped' => 1,
                'deferred' => true,
                'deferred_reason' => 'full_validation_lane_waiting',
            ]);
        });
        $this->mock(LabPopulationService::class, function ($mock): void {
            $mock->shouldNotReceive('build');
        });

        $this->artisan('trading:lab-incremental')
            ->expectsOutput('Incremental checks deferred: full_validation_lane_waiting.')
            ->expectsOutput('Incremental checks: 0 checked, 0 degraded, 1 skipped.')
            ->assertSuccessful();
    }
}
