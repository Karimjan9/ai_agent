<?php

namespace Tests\Feature;

use App\Jobs\RunScheduledArtisanCommandJob;
use App\Services\ScheduledArtisanProcessRunnerService;
use App\Services\ScheduledCommandOutcomeClassifierService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ScheduledCommandIsolationTest extends TestCase
{
    public function test_backup_has_a_private_hour_lease_below_redis_visibility(): void
    {
        config(['queue.default'=>'redis', 'queue.connections.redis.retry_after'=>4500]);
        $lane = RunScheduledArtisanCommandJob::scheduledLane('ops:backup-database');
        $job = new RunScheduledArtisanCommandJob('ops:backup-database', ['--retain'=>1], $lane);
        $this->assertSame('scheduler-backup', $lane);
        $this->assertSame('scheduler-backup', $job->queue);
        $this->assertSame(3600, $job->timeout);
        $this->assertLessThan(config('queue.connections.redis.retry_after'), $job->timeout);
        $this->assertSame(4800, $job->uniqueFor);
        $this->assertSame('scheduler-ops', RunScheduledArtisanCommandJob::scheduledLane('ops:cleanup-legacy-backups'));
    }

    public function test_all_native_pending_symbol_lines_defer_the_original_nonzero_exit(): void
    {
        $result = app(ScheduledCommandOutcomeClassifierService::class)->classify('market-data:update', [], 1,
            "XAUUSD: DUKASCOPY_NATIVE_HTTP_202_UNRESOLVED: archive pending\nEURUSD: DUKASCOPY_NATIVE_HTTP_202_UNRESOLVED: archive pending");
        $this->assertSame('deferred_external_dependency', $result['status']);
        $this->assertFalse($result['throw']);
        $this->assertSame(1, $result['exit_code']);
    }

    public function test_native_pending_cannot_hide_an_authentication_failure_or_ambiguous_marker(): void
    {
        foreach ([
            ['market-data:update', "XAUUSD: DUKASCOPY_NATIVE_HTTP_202_UNRESOLVED: archive pending\nEURUSD: HTTP403 authentication refused"],
            ['market-data:update', "XAUUSD: DUKASCOPY_NATIVE_HTTP_202_UNRESOLVED: service unavailable\nEURUSD: HTTP403 authentication refused"],
            ['market-data:update', 'Unhandled wrapper: DUKASCOPY_NATIVE_HTTP_202_UNRESOLVED: archive pending'],
            ['market-data:sync-volume', 'XAUUSD: DUKASCOPY_NATIVE_HTTP_202_UNRESOLVED: archive pending'],
            ['market-data:sync-volume', 'DUKASCOPY_NATIVE_HTTP_202_UNRESOLVED:'],
        ] as [$command, $output]) {
            $result = app(ScheduledCommandOutcomeClassifierService::class)->classify($command, [], 1, $output);
            $this->assertSame('technical_failure', $result['status'], $output);
            $this->assertTrue($result['throw'], $output);
        }
    }

    public function test_native_archive_pending_is_deferred_without_poisoning_queue_health(): void
    {
        $arguments = ['symbol' => 'XAUUSD', '--timeframe' => 'M15', '--tail-hours' => 72];
        $message = 'DUKASCOPY_NATIVE_HTTP_202_UNRESOLVED: no completed native price/tick evidence';
        Artisan::shouldReceive('call')->once()->with('market-data:sync-volume', $arguments)->andReturn(1);
        Artisan::shouldReceive('output')->once()->andReturn($message);
        $job = new RunScheduledArtisanCommandJob('market-data:sync-volume', $arguments, 'scheduler-market');

        $job->handle(app(ScheduledCommandOutcomeClassifierService::class));

        $status = Cache::get($job->statusCacheKey());
        $this->assertSame('deferred_external_dependency', $status['status']);
        $this->assertSame(1, $status['outcome']['exit_code']);
        $this->assertFalse($status['outcome']['throw']);
    }

    public function test_scheduled_mixed_market_failure_remains_a_real_failed_job(): void
    {
        $message = "XAUUSD: DUKASCOPY_NATIVE_HTTP_202_UNRESOLVED: archive pending\nEURUSD: HTTP403 authentication refused";
        Artisan::shouldReceive('call')->once()->with('market-data:update', [])->andReturn(1);
        Artisan::shouldReceive('output')->once()->andReturn($message);
        $job = new RunScheduledArtisanCommandJob('market-data:update', [], 'scheduler-market');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Scheduled command market-data:update returned exit code 1');
        try {
            $job->handle(app(ScheduledCommandOutcomeClassifierService::class));
        } finally {
            $this->assertSame('failed', Cache::get($job->statusCacheKey())['status']);
        }
    }

    public function test_native_pending_marker_does_not_hide_unrelated_or_schema_failures(): void
    {
        $classifier = app(ScheduledCommandOutcomeClassifierService::class);
        foreach ([
            ['trading:dispatch-full-validation', 'DUKASCOPY_NATIVE_HTTP_202_UNRESOLVED: unavailable'],
            ['market-data:sync-volume', 'HTTP 202'],
            ['market-data:sync-volume', 'DUKASCOPY_NATIVE_SCHEMA_INVALID'],
            ['market-data:sync-volume', 'HTTP 403 authentication refused'],
        ] as [$command, $message]) {
            $outcome = $classifier->classify($command, [], 1, $message);
            $this->assertTrue($outcome['throw']);
            $this->assertSame('technical_failure', $outcome['status']);
        }
    }

    public function test_cross_platform_runner_builds_a_shell_free_artisan_command_line(): void
    {
        $runner = app(ScheduledArtisanProcessRunnerService::class);
        $line = $runner->commandLine(
            'trading:dispatch-lab',
            [
                'symbol' => 'XAUUSD',
                '--timeframe' => 'H1',
                '--learning-confirmation' => true,
                '--disabled' => false,
                '--lesson' => [2387, 2388],
            ],
        );

        $this->assertSame([
            $runner->artisanPhpBinary(),
            base_path('artisan'),
            'trading:dispatch-lab',
            'XAUUSD',
            '--timeframe=H1',
            '--learning-confirmation',
            '--lesson=2387',
            '--lesson=2388',
            '--no-interaction',
        ], $line);
        if (PHP_OS_FAMILY === 'Windows' && is_file(dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'php-win.exe')) {
            $this->assertSame('php-win.exe', strtolower(basename($line[0])));
        }
    }

    public function test_windows_console_process_group_is_not_created_by_php(): void
    {
        $runner = app(ScheduledArtisanProcessRunnerService::class);
        $this->assertSame([], $runner->processOptions());
        $this->assertSame([
            'bypass_shell' => true,
            'create_new_console' => false,
            'create_process_group' => false,
        ], $runner->windowsProcessOptions());
    }

    public function test_scheduled_process_runner_executes_artisan_without_a_shell_contract(): void
    {
        $result = app(ScheduledArtisanProcessRunnerService::class)->run('list', ['--raw' => true], 20);

        $this->assertSame(0, $result['exit_code'], $result['output']);
        $this->assertStringContainsString('ai:status', $result['output']);
    }

    public function test_windows_native_runner_enforces_its_hard_timeout_without_blocking_on_output(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows native process contract only.');
        }

        $started = microtime(true);
        $result = app(ScheduledArtisanProcessRunnerService::class)->run(
            'tinker',
            ['--execute' => 'sleep(3);'],
            1,
        );

        $this->assertSame(124, $result['exit_code']);
        $this->assertLessThan(2.5, microtime(true) - $started);
    }

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
        $this->assertSame(2, $job->tries);
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

    public function test_transient_external_feed_outage_is_deferred_without_hiding_application_errors(): void
    {
        $classifier = app(ScheduledCommandOutcomeClassifierService::class);

        $transient = $classifier->classify(
            'market-data:sync-volume',
            ['symbol' => 'XAUUSD'],
            1,
            'cURL error 6: Could not resolve host: upstream.example',
        );
        $this->assertSame('deferred_external_dependency', $transient['status']);
        $this->assertFalse($transient['throw']);

        $applicationFailure = $classifier->classify(
            'market-data:sync-volume',
            ['symbol' => 'XAUUSD'],
            1,
            'SQLSTATE[42S22]: Column not found',
        );
        $this->assertSame('technical_failure', $applicationFailure['status']);
        $this->assertTrue($applicationFailure['throw']);

        $unrelatedTimeout = $classifier->classify(
            'trading:run-research-loop',
            ['--symbol' => 'XAUUSD'],
            124,
            'Operation timed out',
        );
        $this->assertSame('technical_failure', $unrelatedTimeout['status']);
        $this->assertTrue($unrelatedTimeout['throw']);
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
        $this->assertSame(2400, $job->timeout);
        $this->assertSame(2700, $job->uniqueFor);
    }

    public function test_lifecycle_scheduler_job_has_a_full_population_constructor_budget(): void
    {
        Config::set('queue.default', 'redis');
        Config::set('queue.connections.redis.retry_after', 4500);

        $job = new RunScheduledArtisanCommandJob(
            'trading:run-lifecycle-cycle',
            ['--symbol' => 'XAUUSD'],
            'scheduler-constructor',
        );

        $this->assertSame(2, $job->tries);
        $this->assertSame(2400, $job->timeout);
        $this->assertSame(4800, $job->uniqueFor);
        $this->assertSame('scheduler-constructor', $job->queue);

        $targeted = new RunScheduledArtisanCommandJob(
            'trading:process-targeted-generations',
            [],
            'scheduler-constructor',
        );
        $this->assertSame(2400, $targeted->timeout);
        $this->assertSame(4800, $targeted->uniqueFor);

        foreach (['trading:detect-drift', 'trading:lab-generation', 'trading:advance-learning-progress'] as $command) {
            $constructor = new RunScheduledArtisanCommandJob($command, [], 'scheduler-constructor');
            $this->assertSame(2400, $constructor->timeout);
            $this->assertSame(4800, $constructor->uniqueFor);
            $this->assertSame('scheduler-constructor', $constructor->queue);
        }

        $learningConfirmation = new RunScheduledArtisanCommandJob(
            'trading:dispatch-lab',
            ['symbol' => 'XAUUSD', '--timeframe' => 'H1', '--learning-confirmation' => true],
            'scheduler-constructor',
        );
        $this->assertSame(2400, $learningConfirmation->timeout);
        $this->assertSame(4800, $learningConfirmation->uniqueFor);
        $this->assertSame('scheduler-constructor', $learningConfirmation->queue);

        $fullValidation = new RunScheduledArtisanCommandJob(
            'trading:dispatch-full-validation',
            ['--timeframe' => 'H1'],
            'scheduler-constructor',
        );
        $this->assertSame(2400, $fullValidation->timeout);
        $this->assertSame(4800, $fullValidation->uniqueFor);
        $this->assertSame('scheduler-constructor', $fullValidation->queue);
    }

    public function test_redis_uniqueness_outlives_visibility_timeout_and_allows_one_transport_retry(): void
    {
        Config::set('queue.default', 'redis');
        Config::set('queue.connections.redis.retry_after', 4500);

        $job = new RunScheduledArtisanCommandJob(
            'trading:lab-learn-from-history',
            ['symbol' => 'XAUUSD'],
            'scheduler-research',
        );

        $this->assertSame(2, $job->tries);
        $this->assertSame(4800, $job->uniqueFor);
        $this->assertSame('redis', $job->connection);
    }
}
