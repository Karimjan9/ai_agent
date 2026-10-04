<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabGeneration;
use App\Services\GenerationAdmissionDecisionService;
use App\Services\LabLifecycleOrchestrator;
use App\Services\LabPopulationService;
use App\Services\ScheduledArtisanProcessRunnerService;
use App\Services\ScheduledCommandOutcomeClassifierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class ProspectiveLifecycleHandoffTest extends TestCase
{
    use RefreshDatabase;

    public function test_admitted_learning_request_keeps_its_constructor_trigger(): void
    {
        AiLaboratory::create([
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'name' => 'Prospective handoff',
            'strategy_families' => ['differential_router'], 'is_active' => true,
        ]);
        $admission = Mockery::mock(GenerationAdmissionDecisionService::class);
        $admission->shouldReceive('decide')->once()->withArgs(function ($lab, $latest, $input) {
            return $lab->symbol === 'XAUUSD' && $latest === null
                && $input['trigger'] === 'learning_confirmation'
                && $input['learning_confirmation'] === true;
        })->andReturn([
            'decision' => GenerationAdmissionDecisionService::OPEN_STRUCTURAL_ESCAPE,
            'allowed' => true,
        ]);
        $this->app->instance(GenerationAdmissionDecisionService::class, $admission);

        $method = new ReflectionMethod(LabLifecycleOrchestrator::class, 'strategyGateState');
        $result = $method->invoke(app(LabLifecycleOrchestrator::class), 'XAUUSD', 'H1', false, 'learning_confirmation');

        $this->assertSame('open', $result['state']);
        $this->assertSame('learning_confirmation', $result['generation_trigger']);
    }

    public function test_successful_process_is_not_a_completed_learning_handoff_without_matching_generation(): void
    {
        $classifier = app(ScheduledCommandOutcomeClassifierService::class);
        $args = ['--symbol' => 'XAUUSD', '--learning-confirmation' => true, '--json' => true];
        $output = fn (string $trigger): string => json_encode([
            'status' => 'completed',
            'data' => ['generation_id' => 336, 'generation_trigger_type' => $trigger],
        ], JSON_UNESCAPED_SLASHES);

        $this->assertSame('deferred', $classifier->classify('trading:run-lifecycle-cycle', $args, 0,
            $output('data_edge_audit'))['status']);
        $this->assertSame('completed', $classifier->classify('trading:run-lifecycle-cycle', $args, 0,
            $output('learning_confirmation'))['status']);
        $this->assertSame('deferred', $classifier->classify('trading:run-lifecycle-cycle', $args, 0,
            json_encode(['status' => 'completed', 'data' => ['generation_id' => 336]]))['status']);

        $exact = [...$args, '--prospective-source-pair-id' => 1558,
            '--prospective-source-hash' => str_repeat('a', 64)];
        $this->assertSame('deferred', $classifier->classify('trading:run-lifecycle-cycle', $exact, 0,
            json_encode(['status' => 'completed', 'data' => ['generation_id' => 336,
                'generation_trigger_type' => 'learning_confirmation',
                'prospective_source_pair_id' => 1559, 'prospective_source_hash' => str_repeat('a', 64)]]))['status']);
        $this->assertSame('completed', $classifier->classify('trading:run-lifecycle-cycle', $exact, 0,
            json_encode(['status' => 'completed', 'data' => ['generation_id' => 336,
                'generation_trigger_type' => 'learning_confirmation',
                'prospective_source_pair_id' => 1558, 'prospective_source_hash' => str_repeat('a', 64)]]))['status']);
        $this->assertSame('deferred', $classifier->classify('trading:run-lifecycle-cycle',
            [...$args, '--prospective-source-pair-id' => 1558], 0,
            json_encode(['status' => 'completed', 'data' => ['generation_id' => 336,
                'generation_trigger_type' => 'learning_confirmation',
                'prospective_source_pair_id' => 1558, 'prospective_source_hash' => '']]))['status']);
    }

    public function test_arbiter_source_identity_is_forwarded_through_the_lifecycle_command(): void
    {
        $expectation = ['source_pair_id' => 1558, 'source_hash' => str_repeat('a', 64)];
        $line = app(ScheduledArtisanProcessRunnerService::class)->commandLine('trading:run-lifecycle-cycle', [
            '--symbol' => 'XAUUSD', '--learning-confirmation' => true,
            '--prospective-source-pair-id' => 1558,
            '--prospective-source-hash' => str_repeat('a', 64), '--json' => true,
        ]);
        $this->assertContains('--prospective-source-pair-id=1558', $line);
        $this->assertContains('--prospective-source-hash='.str_repeat('a', 64), $line);
        $orchestrator = Mockery::mock(LabLifecycleOrchestrator::class);
        $orchestrator->shouldReceive('run')->once()->with('XAUUSD', 'H1', null, false, null, false, true,
            $expectation)->andReturn(['status' => 'paused', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
                'cycle_id' => 'test', 'stage' => 'generation', 'summary' => 'wait']);
        $this->app->instance(LabLifecycleOrchestrator::class, $orchestrator);

        $this->assertSame(0, Artisan::call('trading:run-lifecycle-cycle', [
            '--symbol' => 'XAUUSD', '--learning-confirmation' => true,
            '--prospective-source-pair-id' => 1558,
            '--prospective-source-hash' => str_repeat('a', 64), '--json' => true,
        ]));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('constructorAbortTypes')]
    public function test_terminal_constructor_abort_does_not_reenter_its_failed_slot(string $abortKey, string $abortReason): void
    {
        $lab = AiLaboratory::create(['symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'name' => 'Terminal constructor', 'strategy_families' => ['hybrid'], 'is_active' => true]);
        $old = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1,
            'population_size' => 1, 'status' => 'technical_quarantine', 'trigger_type' => 'learning_confirmation',
            'trigger_context' => ['generation_plan' => [[], []],
                $abortKey => ['reason_code' => $abortReason],
                'constructor_audit' => ['created_agents' => 1, 'skipped_zero_diff_slots' => [
                    ['slot' => 2, 'reason' => 'CONSTRUCTOR_MUTATION_INVARIANT_FAILED']]],
                'constructor_continuation' => ['complete' => false, 'planned_slots' => 2,
                    'completed_slots' => [1], 'created_slots_this_run' => [], 'failures' => [['slot' => 2,
                    'reason' => 'CONSTRUCTOR_MUTATION_INVARIANT_FAILED']]]]]);
        $model = \App\Models\ModelVersion::create(['name' => 'terminal-constructor-control',
            'strategy' => 'hybrid_v1', 'version' => 'v1', 'generation' => 1, 'status' => 'testing',
            'parameters' => [], 'metadata' => []]);
        \App\Models\LabAgent::create(['lab_generation_id' => $old->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'origin' => 'test', 'lifecycle_status' => 'technical_quarantine']);
        $population = Mockery::mock(LabPopulationService::class);
        $population->shouldNotReceive('continueInterruptedConstruction');
        $population->shouldReceive('build')->once()->andReturnUsing(function () use ($lab) {
            return LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 2,
                'population_size' => 3, 'status' => 'draft', 'trigger_type' => 'learning_confirmation']);
        });
        $population->shouldReceive('lastBuildOutcome')->once()->andReturn(['status' => 'created']);
        $this->app->instance(LabPopulationService::class, $population);

        $method = new ReflectionMethod(LabLifecycleOrchestrator::class, 'ensureGeneration');
        $next = $method->invoke(app(LabLifecycleOrchestrator::class), 'XAUUSD', 'H1',
            'cycle-test', 'generation', false, 'learning_confirmation',
            ['source_pair_id' => 11, 'source_hash' => str_repeat('a', 64)]);
        $this->assertSame(2, $next->generation);
        $this->assertSame('technical_quarantine', $old->fresh()->status);
        $this->assertDatabaseCount('lab_generations', 2);
    }

    public static function constructorAbortTypes(): array
    {
        return MutationObservabilityTest::constructorAbortTypes();
    }
}
