<?php

namespace Tests\Feature;

use App\Jobs\RunScheduledArtisanCommandJob;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Models\MtfAblationRun;
use App\Services\AutonomousModeService;
use App\Services\MtfResearchCohortService;
use App\Services\MtfResearchSnapshotService;
use App\Services\MtfStrategyResearchReportService;
use App\Services\MtfStrategyResearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MtfAutonomousResearchDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_stop_mode_never_opens_mtf_research_work(): void
    {
        Queue::fake();
        app(AutonomousModeService::class)->stop('XAUUSD', 'H1', 'test', 'drain');
        $this->mock(MtfResearchCohortService::class)->shouldNotReceive('current');

        $this->artisan('trading:dispatch-mtf-research-cycle', ['--json' => true])
            ->expectsOutputToContain('AUTONOMOUS_MODE_STOPPED')
            ->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_current_cohort_seals_control_before_any_hypothesis(): void
    {
        Queue::fake();
        app(AutonomousModeService::class)->start('XAUUSD', 'H1', 'test', 'start');
        [$candidate] = $this->candidate();
        $this->mock(MtfResearchCohortService::class)
            ->shouldReceive('current')->once()->andReturn($this->cohort($candidate));

        $this->artisan('trading:dispatch-mtf-research-cycle', ['--json' => true])
            ->expectsOutputToContain('CURRENT_COHORT_CONTROL_MISSING')
            ->assertSuccessful();

        Queue::assertPushed(RunScheduledArtisanCommandJob::class, function (RunScheduledArtisanCommandJob $job) use ($candidate): bool {
            return $job->command === 'trading:mtf-ablation'
                && $job->lane === 'scheduler-research'
                && (int) $job->arguments['--candidate'] === (int) $candidate->id;
        });
        Queue::assertPushed(RunScheduledArtisanCommandJob::class, 1);
    }

    public function test_verified_control_dispatches_only_bounded_high_information_frontier(): void
    {
        Queue::fake();
        app(AutonomousModeService::class)->start('XAUUSD', 'H1', 'test', 'start');
        [$candidate] = $this->candidate();
        $cohort = $this->cohort($candidate);
        $control = MtfAblationRun::create([
            'model_market_performance_id' => $candidate->id,
            'pilot_id' => 'xauusd_h1_m15_v1', 'symbol' => 'XAUUSD',
            'regime_timeframe' => 'H1', 'entry_timeframe' => 'M15',
            'run_key' => hash('sha256', 'current-control'),
            'data_hash' => $cohort['data_hash'], 'execution_hash' => $cohort['execution_hash'],
            'status' => 'completed', 'variants' => ['m15_only' => ['profit_factor' => 1]],
            'snapshot_reference' => ['path' => 'fixture'], 'promotion_evidence' => false,
            'completed_at' => now(),
        ]);
        $this->mock(MtfResearchCohortService::class)
            ->shouldReceive('current')->once()->andReturn($cohort);
        $this->mock(MtfResearchSnapshotService::class)
            ->shouldReceive('load')->once()->withArgs(fn ($run): bool => (int) $run->id === (int) $control->id)
            ->andReturn(['data_hash' => $cohort['data_hash']]);
        $this->mock(MtfStrategyResearchReportService::class)
            ->shouldReceive('report')->once()->andReturn(['runs' => [], 'family_budget' => []]);
        $this->mock(MtfStrategyResearchService::class)
            ->shouldReceive('selectFrontier')->once()->andReturn([
                ['key' => 'profit', 'target_gate' => 'forward_profit_factor', 'volume_lane' => 'none'],
                ['key' => 'stress', 'target_gate' => 'stress_drawdown', 'volume_lane' => 'none'],
                ['key' => 'regime', 'target_gate' => 'regime_coverage_and_drawdown', 'volume_lane' => 'none'],
                // Volume is unavailable in this fixture and must be omitted,
                // rather than failing the whole price-only research batch.
                ['key' => 'volume', 'target_gate' => 'volume_cost_survival', 'volume_lane' => 'volume_confirmation'],
            ]);

        $this->artisan('trading:dispatch-mtf-research-cycle', ['--limit' => 2, '--json' => true])
            ->expectsOutputToContain('run_economic_information_batch')
            ->assertSuccessful();

        Queue::assertPushed(RunScheduledArtisanCommandJob::class, function (RunScheduledArtisanCommandJob $job) use ($control): bool {
            return $job->command === 'trading:mtf-strategy-research'
                && (int) $job->arguments['--control-run'] === (int) $control->id
                && $job->arguments['--hypotheses'] === 'stress,profit'
                && $job->arguments['--validate-forward'] === true;
        });
        Queue::assertPushed(RunScheduledArtisanCommandJob::class, 1);
    }

    /** @return array{ModelMarketPerformance,ModelVersion} */
    private function candidate(): array
    {
        $model = ModelVersion::create([
            'name' => 'unified-xauusd-organism', 'strategy' => 'regime_router',
            'version' => 'v1', 'generation' => 212, 'status' => 'testing',
            'parameters' => [], 'metadata' => [], 'evidence_status' => 'valid',
        ]);
        $candidate = ModelMarketPerformance::create([
            'model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'hybrid', 'status' => 'rejected', 'metrics' => [],
            'evidence_status' => 'valid',
        ]);

        return [$candidate, $model];
    }

    /** @return array<string,mixed> */
    private function cohort(ModelMarketPerformance $candidate): array
    {
        return [
            'status' => 'ready', 'candidate_id' => $candidate->id,
            'data_hash' => str_repeat('a', 64), 'execution_hash' => str_repeat('b', 64),
            'live_feed' => ['ready' => true],
            'volume_research_freshness' => ['ready' => false],
            'promotion_evidence' => false,
        ];
    }
}
