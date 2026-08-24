<?php

namespace App\Console\Commands;

use App\Services\ReleaseSealService;
use Illuminate\Console\Command;

class VerifyReleaseSeal extends Command
{
    protected $signature = 'trading:release-seal {action=verify : verify or build} {--test-run-id=} {--tests-passed} {--json}';

    protected $description = 'Build or verify the fail-closed production release manifest';

    public function handle(ReleaseSealService $seals): int
    {
        $action = strtolower((string) $this->argument('action'));
        $result = match ($action) {
            'build' => $seals->build(trim((string) $this->option('test-run-id')), (bool) $this->option('tests-passed')),
            'verify' => $seals->verify(),
            default => ['status' => 'blocked', 'reason_codes' => ['UNKNOWN_ACTION']],
        };
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_SLASHES));
        } else {
            $this->line(strtoupper((string) data_get($result, 'status', 'blocked')).' '.implode(',', (array) data_get($result, 'reason_codes', [])));
        }

        return in_array(data_get($result, 'status'), ['sealed', 'verified'], true) ? self::SUCCESS : self::FAILURE;
    }
}
