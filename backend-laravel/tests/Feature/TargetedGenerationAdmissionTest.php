<?php

namespace Tests\Feature;

use App\Console\Commands\ProcessTargetedGenerationRequests;
use App\Models\AiLaboratory;
use App\Models\CandidateHandoffEvent;
use App\Models\LabGeneration;
use App\Services\AutonomousModeService;
use App\Services\CandidateHandoffService;
use App\Services\LabPopulationService;
use App\Services\LabQueueJobInspector;
use App\Services\LearningProtocolSafetyService;
use App\Services\TargetedRescueProfileService;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery as m;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class TargetedGenerationAdmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_stopped_autonomy_defers_targeted_builder_before_any_constructor_work(): void
    {
        app(AutonomousModeService::class)->stop('XAUUSD', 'H1', 'test', 'targeted_stop_regression');
        $population = m::mock(LabPopulationService::class);
        $population->shouldReceive('build')->never();
        $command = app(ProcessTargetedGenerationRequests::class);
        $output = new BufferedOutput;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output));

        $result = $command->handle(
            $population,
            m::mock(CandidateHandoffService::class),
            m::mock(TargetedRescueProfileService::class),
        );

        $this->assertSame(ProcessTargetedGenerationRequests::SUCCESS, $result);
        $this->assertStringContainsString('autonomous mode is stopped', $output->fetch());
    }

    public function test_inactive_archive_handoff_cannot_open_a_unified_population(): void
    {
        $archive = AiLaboratory::create([
            'symbol' => 'XAUUSD',
            'name' => 'XAUUSD M15 evidence archive',
            'timeframe' => 'M15',
            'strategy_families' => ['hybrid'],
            'is_active' => false,
            'lifecycle_mode' => 'shadow',
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $archive->id,
            'generation' => 1,
            'trigger_type' => 'candidate_handoff',
            'population_size' => 20,
            'status' => 'screened',
            'trigger_context' => [],
        ]);
        $event = CandidateHandoffEvent::create([
            'lab_generation_id' => $generation->id,
            'stage' => 'waiting_for_targeted_generation',
            'status' => 'waiting',
            'terminal_reason' => 'NO_ELIGIBLE_CANDIDATE',
            'payload' => ['handoff_profile_hash' => 'archive-profile'],
            'recorded_at' => now(),
        ]);
        $population = m::mock(LabPopulationService::class);
        $population->shouldReceive('build')->never();
        $safety = m::mock(LearningProtocolSafetyService::class);
        $safety->shouldReceive('generationCreationPaused')->once()->andReturnFalse();
        $this->app->instance(LearningProtocolSafetyService::class, $safety);

        $command = app(ProcessTargetedGenerationRequests::class);
        $result = $command->handle(
            $population,
            app(CandidateHandoffService::class),
            m::mock(TargetedRescueProfileService::class),
        );

        $this->assertSame(ProcessTargetedGenerationRequests::SUCCESS, $result);
        $this->assertSame('superseded', $event->fresh()->status);
        $this->assertSame('SOURCE_LAB_ARCHIVED', $event->fresh()->terminal_reason);
        $this->assertSame('retain_as_historical_evidence_only', data_get($event->fresh()->payload, 'next_action'));
    }

    public function test_only_the_newest_waiting_handoff_per_live_laboratory_is_considered(): void
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD',
            'name' => 'XAUUSD Unified MTF Organism',
            'timeframe' => 'H1',
            'strategy_families' => ['hybrid'],
            'is_active' => true,
            'lifecycle_mode' => 'lighthouse',
        ]);
        $older = LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 1,
            'trigger_type' => 'new_data',
            'population_size' => 20,
            'status' => 'screened',
            'trigger_context' => [],
        ]);
        $newer = LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 2,
            'trigger_type' => 'new_data',
            'population_size' => 20,
            'status' => 'screened',
            'trigger_context' => [],
        ]);
        foreach ([[$older, now()->subMinute()], [$newer, now()]] as [$generation, $recordedAt]) {
            CandidateHandoffEvent::create([
                'lab_generation_id' => $generation->id,
                'stage' => 'waiting_for_targeted_generation',
                'status' => 'waiting',
                'terminal_reason' => 'NO_ELIGIBLE_CANDIDATE',
                'payload' => ['handoff_profile_hash' => 'profile-'.$generation->id],
                'recorded_at' => $recordedAt,
            ]);
        }
        $population = m::mock(LabPopulationService::class);
        $population->shouldReceive('build')->once()->withArgs(
            fn (...$arguments): bool => data_get($arguments, '8.source_generation_id') === $newer->id,
        )->andReturnNull();
        $profiles = m::mock(TargetedRescueProfileService::class);
        $profiles->shouldReceive('forGeneration')->once()->withArgs(
            fn (LabGeneration $generation): bool => $generation->is($newer),
        )->andReturn([]);
        $queues = m::mock(LabQueueJobInspector::class);
        $queues->shouldReceive('queueSnapshot')->once()->andReturn(['available' => true, 'total' => 0]);
        $this->app->instance(LabQueueJobInspector::class, $queues);
        $safety = m::mock(LearningProtocolSafetyService::class);
        $safety->shouldReceive('generationCreationPaused')->once()->andReturnFalse();
        $this->app->instance(LearningProtocolSafetyService::class, $safety);

        $command = app(ProcessTargetedGenerationRequests::class);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        $result = $command->handle(
            $population,
            app(CandidateHandoffService::class),
            $profiles,
        );

        $this->assertSame(ProcessTargetedGenerationRequests::SUCCESS, $result);
    }

    public function test_latest_incomplete_technical_quarantine_keeps_generation_ownership(): void
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD',
            'name' => 'XAUUSD Unified MTF Organism',
            'timeframe' => 'H1',
            'strategy_families' => ['hybrid'],
            'is_active' => true,
            'lifecycle_mode' => 'lighthouse',
        ]);
        $source = LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 1,
            'trigger_type' => 'new_data',
            'population_size' => 20,
            'status' => 'screened',
            'trigger_context' => [],
        ]);
        $waiting = CandidateHandoffEvent::create([
            'lab_generation_id' => $source->id,
            'stage' => 'waiting_for_targeted_generation',
            'status' => 'waiting',
            'terminal_reason' => 'NO_ELIGIBLE_CANDIDATE',
            'payload' => ['handoff_profile_hash' => 'current-profile'],
            'recorded_at' => now(),
        ]);
        LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 2,
            'trigger_type' => 'shadow_research',
            'population_size' => 17,
            'status' => 'technical_quarantine',
            'trigger_context' => [
                'generation_plan' => array_fill(0, 20, ['family' => 'hybrid']),
                'constructor_continuation' => ['complete' => false],
            ],
        ]);

        $population = m::mock(LabPopulationService::class);
        $population->shouldReceive('build')->never();
        $profiles = m::mock(TargetedRescueProfileService::class);
        $profiles->shouldReceive('forGeneration')->never();
        $queues = m::mock(LabQueueJobInspector::class);
        $queues->shouldReceive('queueSnapshot')->once()->andReturn(['available' => true, 'total' => 0]);
        $this->app->instance(LabQueueJobInspector::class, $queues);
        $safety = m::mock(LearningProtocolSafetyService::class);
        $safety->shouldReceive('generationCreationPaused')->once()->andReturnFalse();
        $this->app->instance(LearningProtocolSafetyService::class, $safety);

        $command = app(ProcessTargetedGenerationRequests::class);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        $result = $command->handle(
            $population,
            app(CandidateHandoffService::class),
            $profiles,
        );

        $this->assertSame(ProcessTargetedGenerationRequests::SUCCESS, $result);
        $this->assertSame('waiting', $waiting->fresh()->status);
        $this->assertCount(2, $lab->generations()->get());
    }

    public function test_population_builder_cannot_supersede_an_incomplete_lineage_head(): void
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD',
            'name' => 'XAUUSD Unified MTF Organism',
            'timeframe' => 'H1',
            'strategy_families' => ['hybrid'],
            'is_active' => true,
            'lifecycle_mode' => 'lighthouse',
        ]);
        $partial = LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 7,
            'trigger_type' => 'shadow_research',
            'population_size' => 17,
            'status' => 'technical_quarantine',
            'trigger_context' => [
                'generation_plan' => array_fill(0, 20, ['family' => 'hybrid']),
                'constructor_continuation' => ['complete' => false],
            ],
        ]);

        $service = app(LabPopulationService::class);
        $created = $service->build('XAUUSD', 'candidate_handoff', true, 'H1');

        $this->assertNull($created);
        $this->assertSame('LATEST_GENERATION_CONSTRUCTION_INCOMPLETE', $service->lastBuildOutcome()['reason_code']);
        $this->assertSame($partial->id, data_get($service->lastBuildOutcome(), 'context.generation_id'));
        $this->assertCount(1, $lab->generations()->get());
    }
}
