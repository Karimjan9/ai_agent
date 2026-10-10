<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/** Runs internal helpers with preserved output and a bounded process lifetime. */
class HiddenProcessRunnerService
{
    /**
     * @param  array<int, string>  $commandLine
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    public function run(array $commandLine, int $timeoutSeconds, ?string $directory = null): array
    {
        if ($commandLine === []) {
            throw new RuntimeException('Internal helper command is empty.');
        }
        $timeoutSeconds = max(1, $timeoutSeconds);
        $directory ??= base_path();
        if (PHP_OS_FAMILY === 'Windows') {
            return $this->runWindows($commandLine, $timeoutSeconds, $directory);
        }

        $process = new Process($commandLine, $directory, null, null, $timeoutSeconds);
        $process->run();

        return [
            'exit_code' => (int) ($process->getExitCode() ?? 1),
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
        ];
    }

    /**
     * @param  array<int, string>  $commandLine
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    public function mustRun(array $commandLine, int $timeoutSeconds, ?string $directory = null): array
    {
        $result = $this->run($commandLine, $timeoutSeconds, $directory);
        if ($result['exit_code'] !== 0) {
            // Diagnostics may contain internal paths or inherited secrets.
            throw new RuntimeException('Internal helper failed with exit code '.$result['exit_code'].'.');
        }

        return $result;
    }

    /**
     * Symfony inserts cmd.exe on Windows even for array commands. PHP CLI
     * proc_open also lacks CREATE_NO_WINDOW, including when its parent uses
     * php-win.exe. Start a GUI-subsystem broker directly; it creates the
     * actual helper with CREATE_NO_WINDOW and owns its kill-and-reap timeout.
     *
     * @param  array<int, string>  $commandLine
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function runWindows(array $commandLine, int $timeoutSeconds, string $directory): array
    {
        $windowlessPython = (new ExecutableFinder)->find('pythonw');
        if ($windowlessPython === null) {
            throw new RuntimeException('Windowless Python helper interpreter is unavailable.');
        }
        $stdoutFile = tmpfile();
        $stderrFile = tmpfile();
        $process = null;
        try {
            if ($stdoutFile === false || $stderrFile === false) {
                throw new RuntimeException('Internal helper output files could not be created.');
            }
            $pipes = [];
            $process = @proc_open(
                [$windowlessPython, '-B', base_path('scripts/run-hidden-process.py'), '--timeout',
                    (string) $timeoutSeconds, '--', ...$commandLine],
                [0 => ['file', 'NUL', 'r'], 1 => $stdoutFile, 2 => $stderrFile],
                $pipes,
                $directory,
                null,
                ['bypass_shell' => true, 'create_new_console' => false, 'create_process_group' => false],
            );
            if (! is_resource($process)) {
                throw new RuntimeException('Windowless internal helper could not be started.');
            }

            // File handles avoid Windows pipe backpressure. The broker kills
            // and reaps the child at its original timeout; this monotonic
            // watchdog permits only two additional seconds for startup/cleanup.
            $deadline = hrtime(true) + ($timeoutSeconds + 2) * 1_000_000_000;
            $timedOut = false;
            do {
                $status = proc_get_status($process);
                if (! $status['running']) {
                    break;
                }
                if (hrtime(true) >= $deadline) {
                    $timedOut = true;
                    proc_terminate($process);
                    break;
                }
                usleep(20_000);
            } while (true);

            $closeCode = proc_close($process);
            $process = null;
            rewind($stdoutFile);
            rewind($stderrFile);
            $observedCode = (int) ($status['exitcode'] ?? -1);

            return [
                'exit_code' => $timedOut ? 124 : ($observedCode >= 0 ? $observedCode : ($closeCode >= 0 ? $closeCode : 1)),
                'stdout' => (string) stream_get_contents($stdoutFile),
                'stderr' => (string) stream_get_contents($stderrFile),
            ];
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            if (is_resource($stdoutFile)) {
                fclose($stdoutFile);
            }
            if (is_resource($stderrFile)) {
                fclose($stderrFile);
            }
        }
    }
}
