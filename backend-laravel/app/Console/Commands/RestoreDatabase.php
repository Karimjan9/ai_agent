<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Generator;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class RestoreDatabase extends Command
{
    private const INPUT_CHUNK_BYTES = 1048576;
    private const TRANSACTION_BYTES = 8388608;
    private const MAX_SQL_LINE_BYTES = 1073741824;

    protected $signature = 'ops:restore-database {backup} {--confirm=}';
    protected $description = 'Verify a backup SHA-256 manifest and restore it to MySQL';

    public function handle(): int
    {
        if ($this->option('confirm') !== 'RESTORE') {
            $this->components->error('Destructive restore blocked. Re-run with --confirm=RESTORE.');
            return self::FAILURE;
        }
        $path = realpath((string) $this->argument('backup'));
        if (! $path || ! is_file($path) || ! (str_ends_with(strtolower($path), '.sql') || str_ends_with(strtolower($path), '.sql.gz'))) {
            $this->components->error('Backup SQL file not found.');
            return self::FAILURE;
        }
        $manifestPath = $path.'.manifest.json';
        $manifest = is_file($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true) : null;
        if (! is_array($manifest) || ! hash_equals((string) ($manifest['sha256'] ?? ''), hash_file('sha256', $path))) {
            $this->components->error('Backup manifest missing or SHA-256 verification failed.');
            return self::FAILURE;
        }
        $connection = config('database.connections.mysql');
        $process = new Process([
            (string) config('database.backup.mysql_binary', 'mysql'), '--host='.(string) $connection['host'],
            '--port='.(string) $connection['port'], '--user='.(string) $connection['username'],
            '--max-allowed-packet=1G', '--default-character-set=utf8mb4', (string) $connection['database'],
        ]);
        $process->setTimeout(7200);
        $process->setEnv(array_merge($_ENV, ['MYSQL_PWD' => (string) $connection['password']]));
        $compressed = str_ends_with(strtolower($path), '.gz');
        $handle = $compressed ? @gzopen($path, 'rb') : @fopen($path, 'rb');
        if ($handle === false) {
            $this->components->error('Backup SQL could not be opened.');
            return self::FAILURE;
        }
        try {
            $input = $this->restoreInput($handle, $compressed, $manifest);
            $inputFinished = is_resource($input);
            if ($input instanceof Generator) {
                $input = (function () use ($input, &$inputFinished): Generator {
                    yield from $input;
                    $inputFinished = true;
                })();
            }
            $process->setInput($input);
            $process->run();
            if ($process->isSuccessful() && ! $inputFinished) {
                throw new RuntimeException('BACKUP_INPUT_NOT_FULLY_CONSUMED');
            }
        } catch (Throwable) {
            if ($process->isRunning()) {
                $process->stop(0);
            }
            $this->components->error('Restore failed before complete backup input was imported.');
            return self::FAILURE;
        } finally {
            if ($compressed) gzclose($handle); else fclose($handle);
        }
        if (! $process->isSuccessful()) {
            $this->components->error('Restore failed: '.$process->getErrorOutput());
            return self::FAILURE;
        }
        $this->components->info('Restore completed from verified backup: '.$path);
        return self::SUCCESS;
    }

    /** @param resource $handle @return resource|Generator<string> */
    private function restoreInput($handle, bool $compressed, array $manifest, int $batchBytes = self::INPUT_CHUNK_BYTES): mixed
    {
        if (($manifest['verification_layout'] ?? null) !== 'per_table_v1') {
            return $handle;
        }
        if ($batchBytes < 0 || $batchBytes > self::INPUT_CHUNK_BYTES) {
            throw new RuntimeException('RESTORE_BATCH_LIMIT_INVALID');
        }
        return $this->perTableInput($handle, $compressed, $manifest, $batchBytes);
    }

    /** @param resource $handle @return Generator<string> */
    private function perTableInput($handle, bool $compressed, array $manifest, int $batchBytes): Generator
    {
        $stream = (object) ['bytes' => 0, 'hash' => hash_init('sha256')];
        $batchPrefix = '';
        $batchValues = '';
        $transactionBytes = 0;
        yield "SET SESSION autocommit=0;\n";
        foreach ($this->sqlLines($handle, $compressed, $stream) as $line) {
            $parts = $this->literalInsert($line);
            if ($parts === null) {
                if ($batchValues !== '') {
                    yield from $this->boundedInsert($batchPrefix.$batchValues.";\n", $transactionBytes);
                    $batchPrefix = $batchValues = '';
                }
                yield $line;
                continue;
            }
            [$prefix, $values] = $parts;
            $singleBytes = strlen($prefix) + strlen($values) + 2;
            if ($batchBytes === 0 || max($singleBytes, strlen($line)) > $batchBytes) {
                if ($batchValues !== '') {
                    yield from $this->boundedInsert($batchPrefix.$batchValues.";\n", $transactionBytes);
                    $batchPrefix = $batchValues = '';
                }
                yield from $this->boundedInsert($line, $transactionBytes);
                continue;
            }
            if ($batchValues !== '' && ($prefix !== $batchPrefix
                || strlen($batchPrefix) + strlen($batchValues) + 2 + strlen($values) + 2 > $batchBytes)) {
                yield from $this->boundedInsert($batchPrefix.$batchValues.";\n", $transactionBytes);
                $batchPrefix = $batchValues = '';
            }
            $batchPrefix = $prefix;
            $batchValues .= ($batchValues === '' ? '' : ",\n").$values;
        }
        if ($stream->bytes === 0) {
            throw new RuntimeException('BACKUP_SQL_EMPTY');
        }
        $digest = hash_final($stream->hash);
        if ((isset($manifest['uncompressed_bytes']) && (int) $manifest['uncompressed_bytes'] !== $stream->bytes)
            || (isset($manifest['uncompressed_sha256']) && ! hash_equals((string) $manifest['uncompressed_sha256'], $digest))) {
            throw new RuntimeException('BACKUP_SQL_STREAM_HASH_MISMATCH');
        }
        if ($batchValues !== '') {
            yield from $this->boundedInsert($batchPrefix.$batchValues.";\n", $transactionBytes);
        }
        yield "COMMIT;\n";
    }

    /** @return Generator<string> */
    private function boundedInsert(string $statement, int &$transactionBytes): Generator
    {
        $bytes = strlen($statement);
        if ($transactionBytes > 0 && $transactionBytes + $bytes > self::TRANSACTION_BYTES) {
            yield "COMMIT;\nSET SESSION autocommit=0;\n";
            $transactionBytes = 0;
        }
        yield $statement;
        $transactionBytes += $bytes;
        // A single oversized original row stays intact, then commits alone.
        if ($transactionBytes >= self::TRANSACTION_BYTES) {
            yield "COMMIT;\nSET SESSION autocommit=0;\n";
            $transactionBytes = 0;
        }
    }

    /** @param resource $handle @return Generator<string> */
    private function sqlLines($handle, bool $compressed, object $stream): Generator
    {
        $pending = '';
        while (true) {
            set_error_handler(static function (): never {
                throw new RuntimeException('BACKUP_SQL_READ_FAILED');
            });
            try {
                $block = $compressed ? gzread($handle, self::INPUT_CHUNK_BYTES) : fread($handle, self::INPUT_CHUNK_BYTES);
            } finally {
                restore_error_handler();
            }
            if ($block === false || ($block === '' && ! ($compressed ? gzeof($handle) : feof($handle)))) {
                throw new RuntimeException('BACKUP_SQL_READ_FAILED');
            }
            if ($block === '') break;
            $stream->bytes += strlen($block);
            hash_update($stream->hash, $block);
            $pending .= $block;
            $start = 0;
            while (($end = strpos($pending, "\n", $start)) !== false) {
                if ($end + 1 - $start > self::MAX_SQL_LINE_BYTES) {
                    throw new RuntimeException('BACKUP_SQL_LINE_TOO_LARGE');
                }
                yield substr($pending, $start, $end + 1 - $start);
                $start = $end + 1;
            }
            if ($start > 0) $pending = substr($pending, $start);
            if (strlen($pending) > self::MAX_SQL_LINE_BYTES) {
                throw new RuntimeException('BACKUP_SQL_LINE_TOO_LARGE');
            }
        }
        if ($pending !== '') yield $pending;
    }

    /** @return array{string,string}|null */
    private function literalInsert(string $line): ?array
    {
        if (! str_starts_with($line, 'INSERT INTO ')) return null;
        if (! preg_match('/\AINSERT INTO `[A-Za-z0-9_$]+` VALUES \(/', $line, $match)) {
            throw new RuntimeException('BACKUP_NATIVE_INSERT_REQUIRED');
        }
        $valuesStart = strlen($match[0]) - 1;
        $index = $valuesStart + 1;
        $length = strlen($line);
        while ($index < $length) {
            if ($line[$index] === "'") {
                ++$index;
                $closed = false;
                while (($quote = strpos($line, "'", $index)) !== false) {
                    $slashes = 0;
                    for ($position = $quote - 1; $position >= $index && $line[$position] === '\\'; --$position) ++$slashes;
                    if ($slashes % 2 === 1) {
                        $index = $quote + 1;
                    } elseif ($quote + 1 < $length && $line[$quote + 1] === "'") {
                        $index = $quote + 2;
                    } else {
                        $index = $quote + 1;
                        $closed = true;
                        break;
                    }
                }
                if (! $closed) throw new RuntimeException('BACKUP_INSERT_STRING_INCOMPLETE');
            } elseif ($line[$index] === ';') {
                if ($index < 1 || $line[$index - 1] !== ')' || trim(substr($line, $index + 1)) !== '') {
                    throw new RuntimeException('BACKUP_INSERT_STATEMENT_INCOMPLETE');
                }
                return [substr($line, 0, $valuesStart), substr($line, $valuesStart, $index - $valuesStart)];
            } elseif (preg_match('/\G(?:0x[0-9a-fA-F]+|[+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?|NULL|_binary|[(), \t\r\n])/', $line, $value, 0, $index)) {
                $index += strlen($value[0]);
            } else {
                throw new RuntimeException('BACKUP_INSERT_LITERAL_REQUIRED');
            }
        }
        throw new RuntimeException('BACKUP_INSERT_STATEMENT_INCOMPLETE');
    }
}
