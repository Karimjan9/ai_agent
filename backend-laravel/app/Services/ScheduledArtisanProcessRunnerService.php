<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

/** Runs scheduled Artisan work behind a cross-platform hard timeout. */
class ScheduledArtisanProcessRunnerService
{
    /**
     * @param  array<string|int, mixed>  $arguments
     * @return array{exit_code: int, output: string}
     */
    public function run(string $command, array $arguments, int $timeoutSeconds): array
    {
        $commandLine = $this->commandLine($command, $arguments);
        $processTimeout = max(1, $timeoutSeconds);
        if (PHP_OS_FAMILY === 'Windows') {
            return $this->runWindows($commandLine, $processTimeout);
        }
        $process = new Process(
            $commandLine,
            base_path(),
            null,
            null,
            $processTimeout,
        );
        $process->setOptions($this->processOptions());
        $process->run();

        return [
            'exit_code' => (int) ($process->getExitCode() ?? 1),
            'output' => trim($process->getOutput().PHP_EOL.$process->getErrorOutput()),
        ];
    }

    /**
     * Symfony intentionally inserts cmd.exe on Windows to emulate selectable
     * output pipes. In a detached PM2 worker that cmd process owns a conhost
     * and can flash a visible terminal. Native array-form proc_open bypasses
     * the shell. commandLine() selects php-win.exe, so neither the supervisor
     * nor the Artisan child requires a Windows console host.
     *
     * @param  array<int, string>  $artisanCommandLine
     * @return array{exit_code: int, output: string}
     */
    private function runWindows(array $artisanCommandLine, int $timeoutSeconds): array
    {
        $stdoutFile = tmpfile();
        $stderrFile = tmpfile();
        if ($stdoutFile === false || $stderrFile === false) {
            throw new RuntimeException('Temporary output files for hidden Artisan process could not be created.');
        }
        // Anonymous Windows pipes can block in stream_get_contents() even
        // after stream_set_blocking(false), which makes a PHP-side timeout
        // ineffective. File handles never backpressure the child and let this
        // parent poll the real process handle without touching output first.
        $descriptors = [
            0 => ['file', 'NUL', 'r'],
            1 => $stdoutFile,
            2 => $stderrFile,
        ];
        $pipes = [];
        $process = @proc_open(
            $artisanCommandLine,
            $descriptors,
            $pipes,
            base_path(),
            null,
            $this->windowsProcessOptions(),
        );
        if (! is_resource($process)) {
            throw new RuntimeException('Hidden Windows Artisan supervisor could not be started.');
        }

        $lastStatus = null;
        $deadline = microtime(true) + $timeoutSeconds;
        $timedOut = false;

        do {
            $lastStatus = proc_get_status($process);
            if (! (bool) ($lastStatus['running'] ?? false)) {
                break;
            }
            if (microtime(true) >= $deadline) {
                $timedOut = true;
                proc_terminate($process);
                break;
            }
            usleep(20_000);
        } while (true);

        $closeCode = proc_close($process);
        rewind($stdoutFile);
        rewind($stderrFile);
        $stdout = (string) stream_get_contents($stdoutFile);
        $stderr = (string) stream_get_contents($stderrFile);
        fclose($stdoutFile);
        fclose($stderrFile);
        $observedCode = (int) ($lastStatus['exitcode'] ?? -1);
        $exitCode = $timedOut
            ? 124
            : ($observedCode >= 0 ? $observedCode : ($closeCode >= 0 ? $closeCode : 1));

        return [
            'exit_code' => $exitCode,
            'output' => trim($stdout.PHP_EOL.$stderr),
        ];
    }

    /** @param array<string|int, mixed> $arguments */
    public function commandLine(string $command, array $arguments): array
    {
        $line = [$this->artisanPhpBinary(), base_path('artisan'), $command];
        foreach ($arguments as $key => $value) {
            if (is_int($key) || ! str_starts_with((string) $key, '-')) {
                foreach (is_array($value) ? $value : [$value] as $position) {
                    if ($position !== null && $position !== false) {
                        $line[] = (string) $position;
                    }
                }

                continue;
            }
            if ($value === true) {
                $line[] = (string) $key;

                continue;
            }
            if ($value === false || $value === null) {
                continue;
            }
            foreach (is_array($value) ? $value : [$value] as $optionValue) {
                $line[] = (string) $key.'='.(string) $optionValue;
            }
        }
        $line[] = '--no-interaction';

        return $line;
    }

    public function artisanPhpBinary(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // php.exe is a console-subsystem binary. Starting it from a
            // headless PM2 worker makes Windows allocate a hidden conhost for
            // every scheduled command. php-win.exe executes the same CLI
            // script with the same php.ini and inherited pipes, but is a GUI-
            // subsystem binary and therefore needs no console host.
            $windowless = dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'php-win.exe';
            if (is_file($windowless)) {
                return $windowless;
            }
        }

        return PHP_BINARY;
    }

    /** @return array<string, bool> */
    public function processOptions(): array
    {
        // Non-Windows keeps Symfony's native direct process contract.
        return [];
    }

    /** @return array<string, bool> */
    public function windowsProcessOptions(): array
    {
        return [
            'bypass_shell' => true,
            'create_new_console' => false,
            'create_process_group' => false,
        ];
    }
}
