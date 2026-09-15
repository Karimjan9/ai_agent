<?php

namespace Tests\Feature;

use App\Console\Commands\RepairLabIntegrity;
use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Services\LabImmutableEvidenceService;
use App\Services\LabQueueJobInspector;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class RepairArchitectureEscapeAdmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_false_zero_diff_quarantine_is_returned_to_same_generation_recovery(): void
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD',
            'name' => 'XAUUSD Laboratory',
            'timeframe' => 'H1',
            'strategy_families' => ['session'],
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 220,
            'trigger_type' => 'learning_confirmation',
            'population_size' => 20,
            'status' => 'screening',
        ]);
        $parameters = app(StrategyParameterSchemaService::class)->defaults('session');
        $model = ModelVersion::create([
            'name' => 'architecture-repair-test',
            'strategy' => 'architecture_repair_test',
            'version' => 'v220',
            'generation' => 220,
            'status' => 'testing',
            'parameters' => $parameters,
            'metadata' => [
                'strategy_architecture' => 'session_mean_reversion',
                'mutation_constructor_invariant' => [
                    'architecture_changed' => true,
                    'architecture_variant' => 'session_mean_reversion',
                ],
                'portfolio_council_lane' => [
                    'architecture_experiment' => true,
                    // The stale request is what the constructor repair corrects.
                    'architecture_variant' => 'session_breakout',
                ],
                'hypothesis_contract' => [
                    'changed_gene' => null,
                    'planner_declared_gene' => '__architecture',
                    'architecture_changed' => true,
                    'architecture_variant' => 'session_mean_reversion',
                ],
            ],
        ]);
        $agent = LabAgent::create([
            'lab_generation_id' => $generation->id,
            'model_version_id' => $model->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'session',
            'origin' => 'g98_council',
            'lifecycle_status' => 'technical_quarantine',
            'parameter_diff' => [],
        ]);
        app(LabImmutableEvidenceService::class)->recordLifecycle($agent, 'draft_integrity_quarantine', [
            'violations' => ['ISOLATED_ZERO_PARAMETER_DIFF'],
            'promotion_evidence' => false,
        ], 'screening');

        $queue = Mockery::mock(LabQueueJobInspector::class);
        $queue->shouldReceive('labQueues')->once()->andReturn(['lab-screening']);
        $queue->shouldReceive('hasAgentJob')->once()->with($agent->id, ['lab-screening'])->andReturnFalse();
        $method = new \ReflectionMethod(app(RepairLabIntegrity::class), 'repairArchitectureEscapeContract');
        $method->setAccessible(true);
        $repair = $method->invoke(
            app(RepairLabIntegrity::class),
            $agent->fresh(['modelVersion']),
            app(StrategyParameterSchemaService::class),
            $queue,
        );

        $this->assertSame('ARCHITECTURE_ONLY_FALSE_ZERO_DIFF_QUARANTINE_REVERSED', $repair['reason_code']);
        $this->assertTrue($repair['parameter_vector_unchanged']);
        $this->assertSame(
            'session_mean_reversion',
            data_get($model->fresh()->metadata, 'portfolio_council_lane.architecture_variant'),
        );
    }
}
