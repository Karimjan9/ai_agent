<?php

namespace Tests\Feature;

use App\Console\Commands\DispatchMtfPlaybookPrior;
use App\Jobs\EvaluateMtfPlaybookPriorJob;
use App\Services\CanonicalResearchLanePriorityService;
use App\Services\LabQueueJobInspector;
use App\Services\MtfPlaybookFrozenControlService;
use App\Services\MtfPlaybookLearningDirectorService;
use App\Services\ReplayLivenessProbeService;
use App\Services\StrategyResearchCatalogueService;
use Illuminate\Support\Facades\Bus;
use Mockery as m;
use Tests\TestCase;

class DispatchMtfPlaybookPriorTest extends TestCase
{
    public function test_command_queues_one_confirmation_first_job_on_the_frontier_lane(): void
    {
        Bus::fake();

        $this->artisan('trading:dispatch-mtf-playbook-prior', [
            'symbol' => 'XAUUSD',
            '--confirmation-first' => true,
        ])->assertSuccessful();

        Bus::assertDispatched(EvaluateMtfPlaybookPriorJob::class, function (EvaluateMtfPlaybookPriorJob $job): bool {
            return $job->symbol === 'XAUUSD'
                && $job->preferredModelIds === DispatchMtfPlaybookPrior::CONFIRMATION_MODELS
                && $job->queue === config('services.lab_queue.frontier_queue', 'lab-frontier')
                && $job->uniqueId() === 'mtf-playbook-prior:XAUUSD';
        });
    }

    public function test_job_reselects_at_execution_and_runs_only_one_prior(): void
    {
        $director = m::mock(MtfPlaybookLearningDirectorService::class);
        $director->shouldReceive('nextTrial')->once()
            ->with('XAUUSD', DispatchMtfPlaybookPrior::CONFIRMATION_MODELS, null)
            ->andReturn([
                'status' => 'ready',
                'trial_type' => 'bounded_confirmation_activity_repair',
                'model_id' => 'confirmation_trend_continuation',
                'candidate_overrides' => ['minimum_independent_confirmations' => 2],
                'trial_context' => ['source_run_id' => 70],
            ]);
        $runner = m::mock(MtfPlaybookFrozenControlService::class);
        $runner->shouldReceive('run')->once()
            ->with(
                'XAUUSD', 'confirmation_trend_continuation', null,
                ['minimum_independent_confirmations' => 2], ['source_run_id' => 70],
            )
            ->andReturn([
                'run_id' => 71,
                'status' => 'completed',
                'comparison' => ['interpretation' => 'candidate_not_dominant'],
                'promotion_evidence' => false,
            ]);
        $queues = m::mock(LabQueueJobInspector::class);
        $queues->shouldReceive('evolutionReplayIsWaiting')->once()->andReturnFalse();
        $liveness = m::mock(ReplayLivenessProbeService::class);
        $liveness->shouldReceive('probe')->once()->andReturn([
            'status' => 'ok', 'active_requests' => 0,
        ]);

        $job = new EvaluateMtfPlaybookPriorJob('XAUUSD', DispatchMtfPlaybookPrior::CONFIRMATION_MODELS);
        $priority = m::mock(CanonicalResearchLanePriorityService::class);
        $priority->shouldReceive('edgeGenesisOwnership')->once()->with('XAUUSD', 'H1')->andReturn(['owned' => false]);
        $job->handle($director, $runner, $queues, $liveness, app(StrategyResearchCatalogueService::class), $priority);

        $this->assertSame(60, $job->tries);
        $this->assertSame(3, $job->maxExceptions);
        $this->assertSame(1200, $job->timeout);
        $this->assertSame(21600, $job->uniqueFor);
        $this->assertGreaterThan(now(), $job->retryUntil());
    }

    public function test_autonomous_dispatch_is_fail_closed_outside_xauusd(): void
    {
        Bus::fake();

        $this->artisan('trading:dispatch-mtf-playbook-prior', ['symbol' => 'EURUSD'])
            ->assertFailed();
        Bus::assertNotDispatched(EvaluateMtfPlaybookPriorJob::class);
    }

    public function test_pending_evolution_waits_for_the_next_scheduler_tick_without_releasing_the_same_job(): void
    {
        $director = m::mock(MtfPlaybookLearningDirectorService::class);
        $director->shouldNotReceive('nextTrial');
        $runner = m::mock(MtfPlaybookFrozenControlService::class);
        $runner->shouldNotReceive('run');
        $queues = m::mock(LabQueueJobInspector::class);
        $queues->shouldReceive('evolutionReplayIsWaiting')->once()->andReturnTrue();
        $liveness = m::mock(ReplayLivenessProbeService::class);
        $liveness->shouldNotReceive('probe');
        $priority = m::mock(CanonicalResearchLanePriorityService::class);
        $priority->shouldReceive('edgeGenesisOwnership')->once()->with('XAUUSD', 'H1')
            ->andReturn(['owned' => false]);

        $job = new EvaluateMtfPlaybookPriorJob('XAUUSD');
        $job->handle($director, $runner, $queues, $liveness,
            app(StrategyResearchCatalogueService::class), $priority);

        $this->assertSame(0, $job->replayLaneDeferrals);
    }
}
