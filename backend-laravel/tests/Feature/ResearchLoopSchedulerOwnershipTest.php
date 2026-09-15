<?php

namespace Tests\Feature;

use Tests\TestCase;

class ResearchLoopSchedulerOwnershipTest extends TestCase
{
    public function test_only_research_loop_arbiter_is_scheduled_to_select_new_xauusd_work(): void
    {
        $routes = (string) file_get_contents(base_path('routes/console.php'));

        $this->assertSame(1, substr_count($routes, "\$scheduleArtisan('trading:run-research-loop'"));
        foreach ([
            'trading:advance-learning-progress',
            'trading:run-lifecycle-cycle',
            'trading:dispatch-mtf-research-cycle',
            'trading:mtf-ablation',
            'trading:dispatch-mtf-playbook-prior',
            'trading:dispatch-mtf-powered-prior-validation',
            'trading:process-targeted-generations',
            'trading:pump-learning-lane',
            'trading:dispatch-portfolio-member-replay',
            'trading:validate-elite-portfolios',
            'trading:dispatch-controlled-targeted-rescue',
        ] as $formerWriter) {
            $this->assertSame(0, substr_count($routes, "\$scheduleArtisan('{$formerWriter}'"), $formerWriter);
            $this->assertSame(0, substr_count($routes, "\$scheduleStaggeredFive('{$formerWriter}'"), $formerWriter);
        }
        $this->assertStringContainsString('generation selection delegated to Research Loop Arbiter',
            (string) file_get_contents(app_path('Console/Commands/DetectMarketDrift.php')));
        $this->assertStringContainsString('generation selection delegated to Research Loop Arbiter',
            (string) file_get_contents(app_path('Console/Commands/EvaluateLabIncrementally.php')));
    }

    public function test_shadow_population_work_cannot_block_the_xauusd_constructor_lane(): void
    {
        $this->assertSame('scheduler-constructor', \App\Jobs\RunScheduledArtisanCommandJob::scheduledLane(
            'trading:dispatch-full-validation', [0 => 'XAUUSD', '--timeframe' => 'H1']
        ));
        $this->assertSame('scheduler-research', \App\Jobs\RunScheduledArtisanCommandJob::scheduledLane(
            'trading:dispatch-full-validation', [0 => 'GBPUSD', '--timeframe' => 'M15']
        ));
        $this->assertSame('scheduler-research', \App\Jobs\RunScheduledArtisanCommandJob::scheduledLane(
            'trading:lab-generation', [0 => 'EURUSD']
        ));
        $this->assertSame('scheduler-research', \App\Jobs\RunScheduledArtisanCommandJob::scheduledLane(
            'trading:detect-drift'
        ));
        $this->assertSame('scheduler-research', \App\Jobs\RunScheduledArtisanCommandJob::scheduledLane(
            'trading:lab-incremental'
        ));
    }
}
