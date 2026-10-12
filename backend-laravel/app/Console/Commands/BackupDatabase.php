<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

class BackupDatabase extends Command
{
    protected $signature = 'ops:backup-database {--retain= : Number of backups to keep (defaults to DATABASE_BACKUP_RETENTION)}';
    protected $description = 'Create a verified atomic compressed MySQL backup on G: and replace older backups';

    public function handle(): int
    {
        if (config('database.default') !== 'mysql') {
            $this->components->error('This command supports the production MySQL connection only.');
            return self::FAILURE;
        }
        $directory = (string) config('database.backup.directory', 'G:/NeuroTrader/backups');
        if (! preg_match('#^G:/#i', str_replace('\\', '/', $directory))) {
            $this->components->error('Set DATABASE_BACKUP_PATH to a writable G:/ directory.');
            return self::FAILURE;
        }
        File::ensureDirectoryExists($directory);
        if (! is_writable($directory)) return self::FAILURE;
        $directory = realpath($directory);
        $lock = Cache::lock('ops:database-backup', 4800);
        if (! $lock->get()) {
            $this->components->warn('A database backup is already running.');
            return self::FAILURE;
        }
        try {
            $root = dirname(base_path());
            $privatePython = $root.'/ai-service-python/.venv/'.(PHP_OS_FAMILY === 'Windows' ? 'Scripts/python.exe' : 'bin/python');
            $python = is_file($privatePython) ? $privatePython : (getenv('PYTHON_BINARY') ?: 'python3');
            $process = new Process([$python, '-B', base_path('scripts/create-database-backup.py')]);
            $process->setInput(json_encode([...config('database.connections.mysql'),
                'directory' => $directory, 'mysqldump_binary' => config('database.backup.mysqldump_binary')], JSON_THROW_ON_ERROR));
            // Leave time for final hash/prune inside the one-hour queue lease.
            $process->setTimeout(3300);
            $process->run();
            $manifest = json_decode($process->getOutput(), true);
            if (! $process->isSuccessful() || ! is_array($manifest) || ! isset($manifest['file'])) {
                $this->components->error('Database backup failed; the previous completed backup is retained.');
                return self::FAILURE;
            }
            $final = $directory.'/'.$manifest['file'];
            $this->pruneVerified($directory, $final, max(1, (int) ($this->option('retain') ?: config('database.backup.retention', 1))));
            $this->components->info('Verified compressed database backup: '.$final);
            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }

    private function pruneVerified(string $directory, string $newest, int $retain): void
    {
        $directory = realpath($directory);
        $newest = realpath($newest);
        $manifestPath = $newest ? $newest.'.manifest.json' : '';
        $manifest = is_file($manifestPath) ? json_decode(File::get($manifestPath), true) : null;
        if (! $directory || ! $newest || dirname($newest) !== $directory || ! is_array($manifest)
            || ($manifest['schema'] ?? null) !== 'neurotrader-db-backup/v1'
            || ($manifest['file'] ?? null) !== basename($newest)
            || (int) ($manifest['bytes'] ?? 0) !== filesize($newest)
            || ! preg_match('/^[a-f0-9]{64}$/D', $manifest['sha256'] ?? '')
            || ! hash_equals($manifest['sha256'], hash_file('sha256', $newest))) {
            throw new RuntimeException('VERIFIED_NEW_BACKUP_REQUIRED_BEFORE_PRUNE');
        }
        // Every deletion resolves to an immediate child of the configured root.
        $older = collect(array_merge(File::glob($directory.'/neurotrader_*.sql'), File::glob($directory.'/neurotrader_*.sql.gz')))
            ->filter(fn ($path) => realpath($path) !== $newest)
            ->sortByDesc(fn ($path) => File::lastModified($path))->values();
        foreach ($older->slice(max(0, $retain - 1)) as $path) {
            $resolved = realpath($path);
            if (! $resolved || dirname($resolved) !== $directory) throw new RuntimeException('BACKUP_PRUNE_SCOPE_MISMATCH');
            File::delete([$resolved, $resolved.'.manifest.json']);
        }
    }
}
