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
        $population->shouldReceive('lastBuildOutcome')->once()->andReturn([
            'status' => 'blocked',
            'reason_code' => 'INSUFFICIENT_FRESH_CANDLES',
            'retryable' => true,
            'context' => [],
        ]);
        $profiles = m::mock(TargetedRescueProfileService::class);
        $profiles->shouldReceive('forGeneration')->once()->withArgs(
            fn (LabGeneration $generation): bool => $generation->is($newer),
        )->andReturn([
            'actionable_failure_count' => 1,
            'target_counts' => ['profit_factor' => 1],
            'targets' => ['profit_factor'],
            'selected_near_miss' => ['agent_id' => 1],
        ]);
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
        $olderRequest = CandidateHandoffEvent::query()->where('lab_generation_id', $older->id)->sole();
        $newerRequest = CandidateHandoffEvent::query()->where('lab_generation_id', $newer->id)->sole();
        $this->assertSame('superseded', $olderRequest->status);
        $this->assertSame('NEWER_TARGETED_HANDOFF_SUPERSEDES_REQUEST', $olderRequest->terminal_reason);
        $this->assertSame('waiting', $newerRequest->status);
        $this->assertSame('TARGETED_GENERATION_RETRY_DEFERRED', $newerRequest->terminal_reason);
        $this->assertFalse($newerRequest->targetedGenerationRetryDue());
    }

    public function test_created_targeted_generation_consumes_the_waiting_request_once(): void
    {
        $lab = $this->liveLab();
        $source = $this->terminalGeneration($lab, 1);
        $waiting = $this->waitingHandoff($source);
        $population = m::mock(LabPopulationService::class);
        $population->shouldReceive('build')->once()->andReturnUsing(function () use ($lab): LabGeneration {
            return LabGeneration::create([
                'ai_laboratory_id' => $lab->id,
                'generation' => 2,
                'trigger_type' => 'candidate_handoff',
                'population_size' => 20,
                'status' => 'screening',
                'trigger_context' => ['generation_protocol' => LabPopulationService::GENERATION_PROTOCOL],
            ]);
        });
        $profiles = $this->actionableProfiles($source);
        $this->bindHealthyQueueAndSafety();

        $command = app(ProcessTargetedGenerationRequests::class);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        $result = $command->handle($population, app(CandidateHandoffService::class), $profiles);

        $this->assertSame(ProcessTargetedGenerationRequests::SUCCESS, $result);
        $this->assertSame('completed', $waiting->fresh()->status);
        $this->assertSame('TARGETED_GENERATION_REQUEST_CONSUMED', $waiting->fresh()->terminal_reason);
        $this->assertNotEmpty(data_get($waiting->fresh()->payload, 'consumption_receipt'));
        $this->assertSame(2, data_get($waiting->fresh()->payload, 'target_generation'));
        $this->assertDatabaseHas('candidate_handoff_events', [
            'lab_generation_id' => $source->id,
            'stage' => 'targeted_generation_created',
            'status' => 'completed',
        ]);
    }

    public function test_non_retryable_constructor_block_terminally_closes_request(): void
    {
        $lab = $this->liveLab();
        $source = $this->terminalGeneration($lab, 1);
        $waiting = $this->waitingHandoff($source);
        $population = m::mock(LabPopulationService::class);
        $population->shouldReceive('build')->once()->andReturnNull();
        $population->shouldReceive('lastBuildOutcome')->once()->andReturn([
            'status' => 'blocked',
            'reason_code' => 'DATA_EDGE_AUDIT_REQUIRED',
            'retryable' => false,
            'context' => ['fixture' => true],
        ]);
        $this->bindHealthyQueueAndSafety();

        $command = app(ProcessTargetedGenerationRequests::class);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        $command->handle($population, app(CandidateHandoffService::class), $this->actionableProfiles($source));

        $this->assertSame('blocked', $waiting->fresh()->status);
        $this->assertSame('DATA_EDGE_AUDIT_REQUIRED', $waiting->fresh()->terminal_reason);
        $this->assertSame(true, data_get($waiting->fresh()->payload, 'build_outcome.context.fixture'));
    }

    public function test_evidence_free_failure_profile_is_closed_without_random_mutation(): void
    {
        $lab = $this->liveLab();
        $source = $this->terminalGeneration($lab, 1);
        $waiting = $this->waitingHandoff($source);
        $population = m::mock(LabPopulationService::class);
        $population->shouldReceive('build')->never();
        $profiles = m::mock(TargetedRescueProfileService::class);
        $profiles->shouldReceive('forGeneration')->once()->withArgs(
            fn (LabGeneration $generation): bool => $generation->is($source),
        )->andReturn([
            'actionable_failure_count' => 0,
            'target_counts' => [],
            'targets' => ['profit_factor', 'stress_cost'],
            'selected_near_miss' => null,
            'technical_excluded_agent_ids' => [1, 2],
        ]);
        $this->bindHealthyQueueAndSafety();

        $command = app(ProcessTargetedGenerationRequests::class);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));
        $command->handle($population, app(CandidateHandoffService::class), $profiles);

        $this->assertSame('blocked', $waiting->fresh()->status);
        $this->assertSame('TARGETED_FAILURE_PROFILE_NOT_ACTIONABLE', $waiting->fresh()->terminal_reason);
        $this->assertSame('continue_causal_director_or_data_edge_audit', data_get($waiting->fresh()->payload, 'next_action'));
    }

    public function test_terminal_targeted_request_cannot_be_reopened_by_a_repeated_selector_observation(): void
    {
        $lab = $this->liveLab();
        $source = $this->terminalGeneration($lab, 1);
        $handoffs = app(CandidateHandoffService::class);
        $waiting = $handoffs->record($source, null, 'waiting_for_targeted_generation', 'waiting', 'NO_ELIGIBLE_CANDIDATE', [
            'handoff_profile_hash' => 'same-failure',
        ]);
        $handoffs->record($source, null, 'waiting_for_targeted_generation', 'completed', 'TARGETED_GENERATION_REQUEST_CONSUMED', [
            'handoff_profile_hash' => 'same-failure',
            'consumption_receipt' => 'receipt',
        ]);

        $observedAgain = $handoffs->record($source, null, 'waiting_for_targeted_generation', 'waiting', 'NO_ELIGIBLE_CANDIDATE', [
            'handoff_profile_hash' => 'same-failure',
        ]);

        $this->assertSame($waiting->id, $observedAgain->id);
        $this->assertSame('completed', $observedAgain->fresh()->status);
        $this->assertSame('TARGETED_GENERATION_REQUEST_CONSUMED', $observedAgain->fresh()->terminal_reason);
        $this->assertSame('receipt', data_get($observedAgain->fresh()->payload, 'consumption_receipt'));
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

    private function liveLab(): AiLaboratory
    {
        return AiLaboratory::create([
            'symbol' => 'XAUUSD',
            'name' => 'XAUUSD Unified MTF Organism',
            'timeframe' => 'H1',
            'strategy_families' => ['hybrid'],
            'is_active' => true,
            'lifecycle_mode' => 'lighthouse',
        ]);
    }

    private function terminalGeneration(AiLaboratory $lab, int $generation): LabGeneration
    {
        return LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => $generation,
            'trigger_type' => 'new_data',
            'population_size' => 20,
            'status' => 'screened',
            'trigger_context' => [],
        ]);
    }

    private function waitingHandoff(LabGeneration $generation): CandidateHandoffEvent
    {
        return CandidateHandoffEvent::create([
            'lab_generation_id' => $generation->id,
            'stage' => 'waiting_for_targeted_generation',
            'status' => 'waiting',
            'terminal_reason' => 'NO_ELIGIBLE_CANDIDATE',
            'payload' => ['handoff_profile_hash' => 'profile-'.$generation->id],
            'recorded_at' => now(),
        ]);
    }

    private function actionableProfiles(LabGeneration $source): TargetedRescueProfileService
    {
        $profiles = m::mock(TargetedRescueProfileService::class);
        $profiles->shouldReceive('forGeneration')->once()->withArgs(
            fn (LabGeneration $generation): bool => $generation->is($source),
        )->andReturn([
            'actionable_failure_count' => 1,
            'target_counts' => ['profit_factor' => 1],
            'targets' => ['profit_factor'],
            'selected_near_miss' => ['agent_id' => 1],
            'profile_hash' => 'actionable-profile',
        ]);

        return $profiles;
    }

    private function bindHealthyQueueAndSafety(): void
    {
        $queues = m::mock(LabQueueJobInspector::class);
        $queues->shouldReceive('queueSnapshot')->once()->andReturn(['available' => true, 'total' => 0]);
        $this->app->instance(LabQueueJobInspector::class, $queues);
        $safety = m::mock(LearningProtocolSafetyService::class);
        $safety->shouldReceive('generationCreationPaused')->once()->andReturnFalse();
        $this->app->instance(LearningProtocolSafetyService::class, $safety);
    }
}
