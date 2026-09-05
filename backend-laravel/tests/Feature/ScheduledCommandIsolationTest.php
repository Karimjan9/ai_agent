<?php

namespace Tests\Feature;

use App\Jobs\RunScheduledArtisanCommandJob;
use App\Services\ScheduledCommandOutcomeClassifierService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ScheduledCommandIsolationTest extends TestCase
{
    public function test_learning_dispatch_uses_a_short_private_unique_worker_contract(): void
    {
        $job = new RunScheduledArtisanCommandJob(
            'trading:pump-learning-lane',
            ['XAUUSD', '--limit' => 1],
            'scheduler-critical',
        );
        Artisan::shouldReceive('call')->once()->with('trading:pump-learning-lane', [
            'XAUUSD', '--limit' => 1,
        ])->andReturn(0);
        Artisan::shouldReceive('output')->once()->andReturn('learning idle');

        $job->handle(app(ScheduledCommandOutcomeClassifierService::class));

        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame('scheduler-critical', $job->queue);
        $this->assertSame(180, $job->timeout);
        $this->assertSame(480, $job->uniqueFor);
        $this->assertSame(1, $job->tries);
        $this->assertTrue($job->failOnTimeout);
        $this->assertSame(
            'completed',
            data_get(Cache::get('system:scheduled-command:'.$job->uniqueId()), 'status'),
        );
    }

    public function test_expected_lifecycle_block_is_not_recorded_as_a_failed_job(): void
    {
        $job = new RunScheduledArtisanCommandJob('trading:run-lifecycle-cycle', [], 'scheduler-research');
        Artisan::shouldReceive('call')->once()->andReturn(1);
        Artisan::shouldReceive('output')->once()->andReturn('[cycle] XAUUSD:H1 → status=blocked stage=preflight');

        $job->handle(app(ScheduledCommandOutcomeClassifierService::class));

        $this->assertSame('safety_blocked', data_get(Cache::get('system:scheduled-command:'.$job->uniqueId()), 'status'));
    }

    public function test_proposal_only_rescue_deferral_is_not_a_failed_job(): void
    {
        $job = new RunScheduledArtisanCommandJob('trading:dispatch-controlled-targeted-rescue', ['symbol' => 'XAUUSD']);
        Artisan::shouldReceive('call')->once()->andReturn(1);
        Artisan::shouldReceive('output')->once()->andReturn("Lab queue bo'sh emas; rescue cohort backlog ustiga qo'shilmadi.");

        $job->handle(app(ScheduledCommandOutcomeClassifierService::class));

        $this->assertSame('deferred', data_get(Cache::get('system:scheduled-command:'.$job->uniqueId()), 'status'));
    }

    public function test_unknown_nonzero_exit_remains_a_technical_failure(): void
    {
        $job = new RunScheduledArtisanCommandJob('trading:unknown', []);
        Artisan::shouldReceive('call')->once()->andReturn(1);
        Artisan::shouldReceive('output')->once()->andReturn('unexpected failure');

        $this->expectException(\RuntimeException::class);
        $job->handle(app(ScheduledCommandOutcomeClassifierService::class));
    }

    public function test_argument_order_does_not_change_the_overlap_identity(): void
    {
        $left = new RunScheduledArtisanCommandJob('trading:test', ['--b' => 2, '--a' => 1]);
        $right = new RunScheduledArtisanCommandJob('trading:test', ['--a' => 1, '--b' => 2]);

        $this->assertSame($left->uniqueId(), $right->uniqueId());
        $this->assertSame('scheduler-ops', $left->queue);
        $this->assertSame(900, $left->timeout);
        $this->assertSame(1200, $left->uniqueFor);
    }

    public function test_memory_heavy_research_has_a_separate_serial_lane(): void
    {
        $job = new RunScheduledArtisanCommandJob(
            'trading:lab-learn-from-history',
            [],
            'scheduler-research',
        );

        $this->assertSame('scheduler-research', $job->queue);
        $this->assertSame('scheduler-research', $job->lane);
        $this->assertSame(900, $job->timeout);
        $this->assertSame(1200, $job->uniqueFor);
    }
}
