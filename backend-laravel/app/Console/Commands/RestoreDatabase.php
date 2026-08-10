<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class RestoreDatabase extends Command
{
    protected $signature = 'ops:restore-database {backup} {--confirm=}';
    protected $description = 'Verify a backup SHA-256 manifest and restore it to MySQL';

    public function handle(): int
    {
        if ($this->option('confirm') !== 'RESTORE') {
            $this->components->error('Destructive restore blocked. Re-run with --confirm=RESTORE.');
            return self::FAILURE;
        }
        $path = realpath((string) $this->argument('backup'));
        if (! $path || ! is_file($path) || ! str_ends_with(strtolower($path), '.sql')) {
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
            '--default-character-set=utf8mb4', (string) $connection['database'],
        ]);
        $process->setTimeout(3600);
        $process->setEnv(array_merge($_ENV, ['MYSQL_PWD' => (string) $connection['password']]));
        $handle = fopen($path, 'rb');
        $process->setInput($handle);
        $process->run();
        fclose($handle);
        if (! $process->isSuccessful()) {
            $this->components->error('Restore failed: '.$process->getErrorOutput());
            return self::FAILURE;
        }
        $this->components->info('Restore completed from verified backup: '.$path);
        return self::SUCCESS;
    }
}
