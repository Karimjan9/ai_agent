<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

class BackupDatabase extends Command
{
    protected $signature = 'ops:backup-database {--retain=14}';
    protected $description = 'Create an atomic MySQL backup with a full SHA-256 manifest';

    public function handle(): int
    {
        if (config('database.default') !== 'mysql') {
            $this->components->error('This command currently supports the production MySQL connection only.');
            return self::FAILURE;
        }
        $directory = storage_path('app/backups');
        File::ensureDirectoryExists($directory);
        $stamp = now('UTC')->format('Ymd_His');
        $final = "{$directory}/neurotrader_{$stamp}.sql";
        $temporary = $final.'.partial';
        $connection = config('database.connections.mysql');
        $binary = (string) env('MYSQLDUMP_BINARY', 'mysqldump');
        $process = new Process([
            $binary, '--single-transaction', '--quick', '--routines', '--triggers',
            '--host='.(string) $connection['host'], '--port='.(string) $connection['port'],
            '--user='.(string) $connection['username'], '--default-character-set=utf8mb4',
            (string) $connection['database'],
        ]);
        $process->setTimeout(3600);
        $process->setEnv(array_merge($_ENV, ['MYSQL_PWD' => (string) $connection['password']]));
        $handle = fopen($temporary, 'wb');
        if (! $handle) throw new RuntimeException('Backup temp file cannot be opened.');
        $errors = '';
        $process->run(function (string $type, string $buffer) use ($handle, &$errors): void {
            if ($type === Process::OUT) fwrite($handle, $buffer); else $errors .= $buffer;
        });
        fclose($handle);
        if (! $process->isSuccessful() || ! is_file($temporary) || filesize($temporary) === 0) {
            @unlink($temporary);
            $this->components->error('mysqldump failed: '.trim($errors ?: $process->getErrorOutput()));
            return self::FAILURE;
        }
        rename($temporary, $final);
        $manifest = [
            'schema' => 'neurotrader-db-backup/v1', 'database' => $connection['database'],
            'created_at_utc' => now('UTC')->toIso8601String(), 'file' => basename($final),
            'bytes' => filesize($final), 'sha256' => hash_file('sha256', $final),
        ];
        File::put($final.'.manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        $this->prune($directory, max(1, (int) $this->option('retain')));
        $this->components->info('Backup verified: '.$final);
        return self::SUCCESS;
    }

    private function prune(string $directory, int $retain): void
    {
        $files = collect(File::glob($directory.'/neurotrader_*.sql'))->sortDesc()->values();
        foreach ($files->slice($retain) as $file) {
            File::delete([$file, $file.'.manifest.json']);
        }
    }
}
