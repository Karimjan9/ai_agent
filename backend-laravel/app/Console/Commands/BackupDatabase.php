<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

class BackupDatabase extends Command
{
    protected $signature = 'ops:backup-database {--retain= : Number of daily backups to keep (defaults to DATABASE_BACKUP_RETENTION)}';
    protected $description = 'Create the daily atomic MySQL backup on the configured G: drive and prune older backups';

    public function handle(): int
    {
        if (config('database.default') !== 'mysql') {
            $this->components->error('This command currently supports the production MySQL connection only.');
            return self::FAILURE;
        }
        $directory = (string) config('database.backup.directory', 'G:/NeuroTrader/backups');
        if (! preg_match('#^G:[\\/]#i', $directory)) {
            $this->components->error('Refusing to write a backup outside G:. Set DATABASE_BACKUP_PATH to a G:/ directory.');
            return self::FAILURE;
        }
        File::ensureDirectoryExists($directory);
        if (! is_dir($directory) || ! is_writable($directory)) {
            $this->components->error("Backup directory is not writable: {$directory}");
            return self::FAILURE;
        }
        // A killed mysqldump leaves only a non-evidence .partial artifact.
        // Remove stale temporary files before starting the next atomic run;
        // manifests and completed backups are never touched here.
        foreach (File::glob($directory.'/neurotrader_*.sql.partial') as $partial) {
            if (File::lastModified($partial) < now()->subMinutes(5)->timestamp) {
                File::delete($partial);
            }
        }
        $stamp = now('UTC')->format('Ymd_His');
        $final = "{$directory}/neurotrader_{$stamp}.sql";
        $temporary = $final.'.partial';
        $connection = config('database.connections.mysql');
        $binary = (string) config('database.backup.mysqldump_binary', 'mysqldump');
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
        $retention = max(1, (int) ($this->option('retain') ?: config('database.backup.retention', 3)));
        $this->prune($directory, $retention);
        $this->components->info('Backup verified: '.$final);
        $this->components->info("Retention: {$retention} backup(s); old backups were pruned.");
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
