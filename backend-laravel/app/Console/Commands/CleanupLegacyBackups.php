<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class CleanupLegacyBackups extends Command
{
    protected $signature = 'ops:cleanup-legacy-backups';

    protected $description = 'Remove legacy database backups from the C: storage path after G: verification';

    public function handle(): int
    {
        $legacyDirectory = storage_path('app/backups');
        if (! is_dir($legacyDirectory)) {
            $this->components->info('No legacy C: backup directory exists.');

            return self::SUCCESS;
        }

        $files = array_merge(
            File::glob($legacyDirectory.'/neurotrader_*.sql'),
            File::glob($legacyDirectory.'/neurotrader_*.sql.manifest.json'),
        );

        foreach ($files as $file) {
            File::delete($file);
        }

        $this->components->info(sprintf('Removed %d legacy backup file(s) from %s.', count($files), $legacyDirectory));

        return self::SUCCESS;
    }
}
