<?php

namespace App\Console\Commands;

use App\Services\ProtectedSecretFileService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** One-way local secret migration; provider-console rotation remains external. */
class MigrateRuntimeSecrets extends Command
{
    protected $signature = 'security:migrate-runtime-secrets {--rotate-internal : Replace the internal Laravel/AI token}';
    protected $description = 'Move runtime secrets out of .env and PM2 metadata into protected local files';

    public function handle(ProtectedSecretFileService $secrets): int
    {
        $migrated = [];
        $internal = $this->option('rotate-internal') ? Str::random(64) : (string) config('services.internal_api.token');
        if ($internal === '') {
            $this->error('Internal API token is unavailable; refusing an unsafe migration.');
            return self::FAILURE;
        }
        $secrets->put('internal_api', $internal);
        $migrated[] = 'INTERNAL_API_TOKEN(rotated)';

        foreach ([
            'alpha_vantage' => (string) config('services.alpha_vantage.api_key'),
            'currents_api' => (string) config('services.currents_api.api_key'),
        ] as $name => $value) {
            if ($value === '') continue;
            $secrets->put($name, $value);
            $migrated[] = strtoupper($name).'_KEY(migrated; provider rotation required externally)';
        }

        $envPath = base_path('.env');
        $contents = (string) file_get_contents($envPath);
        $names = ['INTERNAL_API_TOKEN', 'ALPHA_VANTAGE_API_KEY', 'CURRENTS_API_KEY', 'CURRENTSAPI_API_KEY'];
        foreach ($names as $name) {
            $contents = preg_replace('/^'.preg_quote($name, '/').'=.*/m', $name.'=', $contents) ?? $contents;
        }
        file_put_contents($envPath, $contents, LOCK_EX);
        @chmod($envPath, 0600);

        $this->info('Protected files updated; .env secret values removed: '.implode(', ', $migrated).'.');
        $this->warn('Rotate Alpha Vantage/Currents credentials in their provider consoles, then rerun this command to import the replacements.');
        return self::SUCCESS;
    }
}
