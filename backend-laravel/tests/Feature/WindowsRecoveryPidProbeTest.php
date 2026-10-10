<?php

namespace Tests\Feature;

use App\Console\Commands\RecoverLabReplayMutex;
use App\Services\HiddenProcessRunnerService;
use App\Services\LabLifecycleOrchestrator;
use App\Services\LabLifecycleWatchdogService;
use App\Services\LabPopulationService;
use Mockery;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

class WindowsRecoveryPidProbeTest extends TestCase
{
    public function test_windows_probe_output_matrix_preserves_each_existing_classification(): void
    {
        $this->requireWindows();
        // Columns: constructor, lifecycle, superseded absence, mutex existence.
        $cases = [
            'live' => [0, '"php.exe","23456","Console","1","100 K"', '', [true, true, false, true]],
            'dead' => [0, 'INFO: No tasks are running which match the specified criteria.', '', [false, false, true, false]],
            'empty' => [0, '', '', [false, false, false, false]],
            'nonzero' => [23, '"php.exe","23456","Console"', '', [null, null, false, false]],
            'legitimate exit 124' => [124, '', 'Hidden process: timed out.', [null, null, false, false]],
            'quoted PID outside PID column' => [0, '"other","99","23456","1"', '', [false, false, false, true]],
            'different PID' => [0, '"php.exe","234567","Console"', '', [false, false, true, false]],
        ];
        foreach ($cases as $name => [$code, $stdout, $stderr, $expected]) {
            foreach ($this->probes() as $index => [$class, $method, $entrypoint, $timeout]) {
                $runner = Mockery::mock(HiddenProcessRunnerService::class);
                $runner->shouldReceive($entrypoint)->once()->with($this->command(23456), $timeout)
                    ->andReturn(['exit_code' => $code, 'stdout' => $stdout, 'stderr' => $stderr]);
                $this->app->instance(HiddenProcessRunnerService::class, $runner);

                $this->assertSame($expected[$index], $this->probe($class, $method, 23456), $name.' '.$class);
            }
        }
    }

    public function test_timeout_and_start_exceptions_propagate_except_for_conservative_watchdog(): void
    {
        $this->requireWindows();
        foreach ($this->probes() as [$class, $method, $entrypoint, $timeout]) {
            foreach ([
                new ProcessTimedOutException(new Process($this->command(23456), null, null, null, $timeout), ProcessTimedOutException::TYPE_GENERAL),
                new RuntimeException('Unavailable internal helper'),
            ] as $failure) {
                $runner = Mockery::mock(HiddenProcessRunnerService::class);
                $runner->shouldReceive($entrypoint)->once()->with($this->command(23456), $timeout)->andThrow($failure);
                $this->app->instance(HiddenProcessRunnerService::class, $runner);
                try {
                    $result = $this->probe($class, $method, 23456);
                } catch (Throwable $exception) {
                    $this->assertNotSame(LabLifecycleWatchdogService::class, $class);
                    $this->assertSame($failure, $exception);

                    continue;
                }
                $this->assertSame(LabLifecycleWatchdogService::class, $class, 'Probe failure was swallowed.');
                $this->assertFalse($result);
            }
        }
    }

    public function test_invalid_pid_and_watchdog_current_pid_guards_do_not_launch_a_helper(): void
    {
        $this->requireWindows();
        $runner = Mockery::mock(HiddenProcessRunnerService::class);
        $runner->shouldNotReceive('run');
        $runner->shouldNotReceive('runWithTimeoutException');
        $this->app->instance(HiddenProcessRunnerService::class, $runner);
        foreach ($this->probes() as [$class, $method]) {
            $this->assertFalse($this->probe($class, $method, 0));
            $this->assertFalse($this->probe($class, $method, -1));
        }
        $this->assertFalse($this->probe(LabLifecycleWatchdogService::class, 'supersededWorkerIsAbsent', getmypid()));
    }

    public function test_actual_windows_tasklist_preserves_live_and_dead_process_results(): void
    {
        $this->requireWindows();
        foreach ($this->probes() as [$class, $method]) {
            $this->assertSame($class !== LabLifecycleWatchdogService::class, $this->probe($class, $method, getmypid()));
            $this->assertSame($class === LabLifecycleWatchdogService::class, $this->probe($class, $method, 99999999));
        }
    }

    private function probes(): array
    {
        return [
            [LabPopulationService::class, 'localConstructorProcessIsRunning', 'runWithTimeoutException', 60],
            [LabLifecycleOrchestrator::class, 'localProcessIsRunning', 'runWithTimeoutException', 60],
            [LabLifecycleWatchdogService::class, 'supersededWorkerIsAbsent', 'run', 5],
            [RecoverLabReplayMutex::class, 'workerProcessExists', 'runWithTimeoutException', 60],
        ];
    }

    private function command(int $pid): array
    {
        return ['tasklist', '/FI', 'PID eq '.$pid, '/FO', 'CSV', '/NH'];
    }

    private function probe(string $class, string $method, int $pid): ?bool
    {
        return (new ReflectionMethod($class, $method))->invoke((new ReflectionClass($class))->newInstanceWithoutConstructor(), $pid);
    }

    private function requireWindows(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Windows-only recovery PID transport.');
        }
    }
}
