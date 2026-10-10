<?php

namespace Tests\Feature;

use App\Services\HiddenProcessRunnerService;
use Composer\Autoload\ClassLoader;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

class HiddenProcessRunnerTest extends TestCase
{
    public function test_helper_preserves_argument_boundaries_output_and_exit_status(): void
    {
        $arguments = ['space and "quote"', '%PATH%', 'a&b|c^d!', '', 'Unicode: ўзбек', 'trailing\\'];
        $result = app(HiddenProcessRunnerService::class)->run([
            $this->python(), '-B', '-c',
            'import json,sys; print(json.dumps(sys.argv[1:], ensure_ascii=True)); sys.stderr.write("distinct error\\n"); sys.exit(7)',
            ...$arguments,
        ], 5);

        $this->assertSame(7, $result['exit_code']);
        $this->assertSame($arguments, json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame("distinct error\n", str_replace("\r\n", "\n", $result['stderr']));
    }

    public function test_successful_helper_json_is_returned_without_stderr_contamination(): void
    {
        $result = app(HiddenProcessRunnerService::class)->mustRun([
            $this->python(), '-B', '-c', 'import sys; print("{\\"ready\\":true}"); sys.stderr.write("diagnostic only\\n")',
        ], 5);

        $this->assertSame(['ready' => true], json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR));
        $this->assertStringContainsString('diagnostic only', $result['stderr']);
    }

    public function test_failed_helper_is_refused_before_the_caller_can_decode_json(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Internal helper failed with exit code 9.');
        app(HiddenProcessRunnerService::class)->mustRun([
            $this->python(), '-B', '-c', 'import sys; print("{\\"ready\\":true}"); sys.exit(9)',
        ], 5);
    }

    public function test_large_output_does_not_block_the_helper_on_windows_pipes(): void
    {
        $result = app(HiddenProcessRunnerService::class)->mustRun([
            $this->python(), '-B', '-c',
            'import sys; sys.stdout.buffer.write(b"x" * 1048576); sys.stderr.buffer.write(b"y" * 1048576)',
        ], 5);

        $this->assertSame(str_repeat('x', 1048576), $result['stdout']);
        $this->assertSame(str_repeat('y', 1048576), $result['stderr']);
    }

    public function test_windows_timeout_reaps_the_actual_child_and_remains_bounded(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Actual Windows broker timeout contract.');
        }
        $started = hrtime(true);
        $result = app(HiddenProcessRunnerService::class)->run([
            $this->python(), '-B', '-u', '-c',
            'import json,os,time; print(json.dumps({"pid":os.getpid()}), flush=True); time.sleep(8); print("survived")',
        ], 1);
        $elapsed = (hrtime(true) - $started) / 1_000_000_000;

        $this->assertSame(124, $result['exit_code']);
        $this->assertLessThan(3, $elapsed);
        $child = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
        $check = app(HiddenProcessRunnerService::class)->mustRun([
            $this->python(), '-B', '-c',
            'import ctypes,sys; k=ctypes.windll.kernel32; h=k.OpenProcess(0x1000,False,int(sys.argv[1])); print(bool(h)); h and k.CloseHandle(h)',
            (string) $child['pid'],
        ], 5);
        $this->assertSame('False', trim($check['stdout']), 'Timed-out helper child is still alive.');
    }

    public function test_windows_child_has_no_console_even_from_windowless_php_parent(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Actual Windows console allocation contract.');
        }
        $commandLine = [
            $this->python(), '-B', '-c', 'import ctypes; print(bool(ctypes.windll.kernel32.GetConsoleWindow()))',
        ];
        $autoload = dirname((new \ReflectionClass(ClassLoader::class))->getFileName(), 2).'/autoload.php';
        $script = '$loader = require '.var_export($autoload, true).';'
            .'$loader->setPsr4("App\\\\", '.var_export(app_path(), true).');'
            .'$app = new \\Illuminate\\Foundation\\Application('.var_export(base_path(), true).');'
            .'echo json_encode((new \\App\\Services\\HiddenProcessRunnerService)->mustRun('
            .var_export($commandLine, true).', 5));';
        $phpWindowless = dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'php-win.exe';
        $this->assertFileExists($phpWindowless);
        $parent = app(HiddenProcessRunnerService::class)->mustRun([$phpWindowless, '-r', $script], 10);
        $result = json_decode($parent['stdout'], true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('False', trim($result['stdout']));
    }

    public function test_legitimate_exit_124_is_not_classified_as_a_timeout(): void
    {
        $result = app(HiddenProcessRunnerService::class)->runWithTimeoutException([
            $this->python(), '-B', '-c',
            'import sys; print("valid nonzero exit"); sys.stderr.write("Hidden process: timed out.\\n"); sys.exit(124)',
        ], 5);

        $this->assertSame(['exit_code', 'stdout', 'stderr'], array_keys($result));
        $this->assertSame(124, $result['exit_code']);
        $this->assertSame('valid nonzero exit', trim($result['stdout']));
        $this->assertSame('Hidden process: timed out.', trim($result['stderr']));
    }

    public function test_windows_timeout_aware_entrypoint_preserves_symfony_timeout_type(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Actual Windows broker timeout provenance.');
        }
        $started = hrtime(true);
        try {
            app(HiddenProcessRunnerService::class)->runWithTimeoutException([
                $this->python(), '-B', '-c', 'import time; time.sleep(8)',
            ], 1);
            $this->fail('Timed-out probe did not throw.');
        } catch (ProcessTimedOutException $exception) {
            $this->assertTrue($exception->isGeneralTimeout());
            $this->assertFalse($exception->isIdleTimeout());
            $this->assertSame(1.0, $exception->getExceededTimeout());
            $this->assertLessThan(3, (hrtime(true) - $started) / 1_000_000_000);
        }
    }

    private function python(): string
    {
        $python = (new ExecutableFinder)->find('python');
        $this->assertNotNull($python, 'Python is required for the internal audit helper.');

        return $python;
    }
}
