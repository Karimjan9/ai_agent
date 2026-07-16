<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class GenerateInternalApiToken extends Command
{
    protected $signature = 'security:generate-internal-token {--force}';
    protected $description = 'Generate the shared Laravel/Python internal API token without printing it';

    public function handle(): int
    {
        $path = storage_path('app/internal-api.token');
        if (is_file($path) && ! $this->option('force')) {
            $this->components->warn('Internal token already exists; use --force only for a coordinated rotation.');
            return self::SUCCESS;
        }
        $token = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        File::put($path, $token.PHP_EOL, true);
        @chmod($path, 0600);
        $this->components->info('Internal API token generated in protected storage. Restart managed services to apply it.');
        return self::SUCCESS;
    }
}
