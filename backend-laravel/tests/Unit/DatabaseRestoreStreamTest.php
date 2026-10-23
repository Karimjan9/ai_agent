<?php

namespace Tests\Unit;

use App\Console\Commands\RestoreDatabase;
use Generator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Process\Process;

class DatabaseRestoreStreamTest extends TestCase
{
    private array $handles = [];
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->handles as [$handle, $compressed]) {
            if (is_resource($handle)) {
                if ($compressed) gzclose($handle); else fclose($handle);
            }
        }
        foreach ($this->paths as $path) unlink($path);
        parent::tearDown();
    }

    private function input(string $sql, bool $compressed = false, array $manifest = [], int $batchBytes = 1048576): mixed
    {
        if ($compressed) {
            $path = tempnam(sys_get_temp_dir(), 'restore-stream-');
            $this->paths[] = $path;
            file_put_contents($path, gzencode($sql));
            $handle = gzopen($path, 'rb');
        } else {
            $handle = fopen('php://temp', 'w+b');
            fwrite($handle, $sql);
            rewind($handle);
        }
        $this->handles[] = [$handle, $compressed];
        $manifest += ['verification_layout' => 'per_table_v1', 'uncompressed_bytes' => strlen($sql), 'uncompressed_sha256' => hash('sha256', $sql)];
        return (new ReflectionMethod(RestoreDatabase::class, 'restoreInput'))->invoke(new RestoreDatabase, $handle, $compressed, $manifest, $batchBytes);
    }

    public function test_gzip_and_plain_streams_group_exact_literal_values_and_preserve_directives(): void
    {
        $first = "INSERT INTO `sample` VALUES (1,'escaped\\n; DROP DATABASE text;');\n";
        $second = "INSERT INTO `sample` VALUES (-1,'escaped\\'quote',0x00FF,NULL,_binary 'bytes');\n";
        $header = "-- original header\n/*!40101 SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n";
        $trailer = "/*!40000 ALTER TABLE `sample` ENABLE KEYS */;\nUNLOCK TABLES;\n";
        foreach ([false, true] as $compressed) {
            $parts = iterator_to_array($this->input($header.$first.$second.$trailer, $compressed), false);
            $expected = "INSERT INTO `sample` VALUES (1,'escaped\\n; DROP DATABASE text;'),\n(-1,'escaped\\'quote',0x00FF,NULL,_binary 'bytes');\n";
            $this->assertSame("SET SESSION autocommit=0;\n", $parts[0]);
            $this->assertSame([$expected], array_values(array_filter($parts, fn ($part) => str_starts_with($part, 'INSERT INTO '))));
            $this->assertStringContainsString($header.$expected.$trailer, implode('', $parts));
            $this->assertSame("COMMIT;\n", $parts[array_key_last($parts)]);
        }
    }

    public function test_batches_never_cross_tables_and_flush_at_eof(): void
    {
        $first = "INSERT INTO `first` VALUES (1,'first');\n";
        $second = "INSERT INTO `second` VALUES (2,'second');";
        $parts = iterator_to_array($this->input($first.$second), false);
        $this->assertSame(["SET SESSION autocommit=0;\n", $first, $second."\n", "COMMIT;\n"], $parts);
    }

    public function test_total_statement_cap_includes_prefix_separators_and_terminator(): void
    {
        $line = "INSERT INTO `sample` VALUES (1,'small');\n";
        $parts = iterator_to_array($this->input(str_repeat($line, 20), false, [], 100), false);
        $inserts = array_filter($parts, fn ($part) => str_starts_with($part, 'INSERT INTO '));
        foreach ($inserts as $insert) $this->assertLessThanOrEqual(100, strlen($insert));
        $this->assertLessThan(20, count($inserts));
        $this->assertSame(20, substr_count(implode('', $inserts), "(1,'small')"));
    }

    public function test_oversized_original_row_is_unchanged_and_committed_alone(): void
    {
        $small = "INSERT INTO `sample` VALUES (1,'small');\n";
        $large = "INSERT INTO `sample` VALUES (2,'".str_repeat('x', 8388608)."');\r\n";
        $parts = iterator_to_array($this->input($small.$large.$small), false);
        $this->assertSame(["SET SESSION autocommit=0;\n", $small, "COMMIT;\nSET SESSION autocommit=0;\n", $large,
            "COMMIT;\nSET SESSION autocommit=0;\n", $small, "COMMIT;\n"], $parts);
    }

    public function test_transaction_bytes_never_exceed_eight_mib_for_bounded_batches(): void
    {
        $line = "INSERT INTO `sample` VALUES (1,'".str_repeat('x', 1000)."');\n";
        $parts = $this->input(str_repeat($line, 17000));
        $transaction = $commits = $rows = 0;
        foreach ($parts as $part) {
            if (str_starts_with($part, 'INSERT INTO ')) {
                $this->assertLessThanOrEqual(1048576, strlen($part));
                $transaction += strlen($part);
                $rows += substr_count($part, "(1,'");
                $this->assertLessThanOrEqual(8388608, $transaction);
            } elseif (str_starts_with($part, 'COMMIT;')) {
                $transaction = 0;
                ++$commits;
            }
        }
        $this->assertSame(17000, $rows);
        $this->assertGreaterThanOrEqual(3, $commits);
    }

    public function test_zero_batch_preserves_single_insert_bytes_with_transaction_bounds(): void
    {
        $line = "INSERT INTO `sample` VALUES (1,'original');\r\n";
        $parts = iterator_to_array($this->input($line.$line, false, [], 0), false);
        $this->assertSame(["SET SESSION autocommit=0;\n", $line, $line, "COMMIT;\n"], $parts);
    }

    public function test_legacy_manifests_keep_the_original_resource_and_symfony_accepts_generator_input(): void
    {
        $resource = $this->input("-- legacy bytes\n", false, ['verification_layout' => null]);
        $this->assertIsResource($resource);
        $this->assertSame($this->handles[array_key_last($this->handles)][0], $resource);
        $this->assertSame("-- legacy bytes\n", stream_get_contents($resource));
        $generator = $this->input("INSERT INTO `sample` VALUES (1,'literal');\n");
        $this->assertInstanceOf(Generator::class, $generator);
        $process = new Process(['not-launched-fixture-native-client']);
        $process->setInput($generator);
        $this->assertSame($generator, $process->getInput());
        $this->assertFalse($process->isStarted());
    }

    public function test_nonliteral_or_incomplete_inserts_refuse_streaming(): void
    {
        foreach (["INSERT INTO `sample` VALUES (1,NOW());\n", "INSERT INTO `sample` VALUES (1,'unterminated);\n",
            "INSERT INTO `sample` VALUES (1,'literal'); DROP DATABASE `outside`;\n"] as $sql) {
            try {
                iterator_to_array($this->input($sql), false);
                $this->fail('Unsupported native INSERT must fail.');
            } catch (RuntimeException $error) {
                $this->assertStringStartsWith('BACKUP_INSERT_', $error->getMessage());
            }
        }
    }

    public function test_empty_or_incorrect_raw_hash_never_completes_successfully(): void
    {
        foreach ([['', []], ["-- nonempty\n", ['uncompressed_sha256' => str_repeat('0', 64)]],
            ["-- nonempty\n", ['uncompressed_bytes' => 999]]] as [$sql, $manifest]) {
            try {
                iterator_to_array($this->input($sql, false, $manifest), false);
                $this->fail('Empty or mismatched stream must fail.');
            } catch (RuntimeException $error) {
                $this->assertContains($error->getMessage(), ['BACKUP_SQL_EMPTY', 'BACKUP_SQL_STREAM_HASH_MISMATCH']);
            }
        }
    }

    public function test_closed_input_is_a_read_failure_and_batch_limit_is_bounded(): void
    {
        $handle = fopen('php://temp', 'w+b');
        fclose($handle);
        $input = (new ReflectionMethod(RestoreDatabase::class, 'restoreInput'))->invoke(new RestoreDatabase, $handle, false, ['verification_layout' => 'per_table_v1']);
        try {
            iterator_to_array($input, false);
            $this->fail('Closed input must fail.');
        } catch (\TypeError $error) {
            $this->assertStringContainsString('valid stream resource', $error->getMessage());
        }
        $this->expectExceptionMessage('RESTORE_BATCH_LIMIT_INVALID');
        $this->input('-- bytes', false, [], 1048577);
    }

    public function test_stream_read_warning_is_a_failure_instead_of_an_empty_success(): void
    {
        stream_wrapper_register('restorefixture', DatabaseRestoreFailureStream::class);
        $handle = fopen('restorefixture://read-failure', 'rb');
        try {
            $input = (new ReflectionMethod(RestoreDatabase::class, 'restoreInput'))->invoke(new RestoreDatabase, $handle, false,
                ['verification_layout' => 'per_table_v1']);
            $this->expectExceptionMessage('BACKUP_SQL_READ_FAILED');
            iterator_to_array($input, false);
        } finally {
            fclose($handle);
            stream_wrapper_unregister('restorefixture');
        }
    }
}

class DatabaseRestoreFailureStream
{
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_read(int $count): string|false
    {
        trigger_error('Synthetic backup read failure.', E_USER_WARNING);
        return false;
    }

    public function stream_eof(): bool
    {
        return false;
    }

    public function stream_stat(): array
    {
        return [];
    }
}
