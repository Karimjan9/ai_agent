<?php

namespace App\Console\Commands;

use App\Services\ProtectedSecretFileService;
use Illuminate\Console\Command;

class GenerateInternalApiToken extends Command
{
    protected $signature = 'security:generate-internal-token {--force}';
    protected $description = 'Generate the shared Laravel/Python internal API token without printing it';

    public function handle(ProtectedSecretFileService $secrets): int
    {
        $path = $secrets->path('internal_api');
        if (is_file($path) && ! $this->option('force')) {
            $this->components->warn('Internal token already exists; use --force only for a coordinated rotation.');
            return self::SUCCESS;
        }
        $token = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $secrets->put('internal_api', $token);
        $this->components->info('Internal API token generated in protected storage. Restart managed services to apply it.');
        return self::SUCCESS;
    }
}
