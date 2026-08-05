<?php

namespace App\Console\Commands;

use App\Services\TechnicalGenerationRecoveryService;
use Illuminate\Console\Command;

class RecoverTechnicalGenerations extends Command
{
    protected $signature = 'trading:recover-technical-generations {--generation=25,29} {--older-than=90} {--apply : Dispatch the single retry or technical quarantine}';
    protected $description = 'Append-only recovery for stale G25/G29 screening work; never creates a quality verdict';

    public function handle(TechnicalGenerationRecoveryService $recovery): int
    {
        $numbers = collect(explode(',', (string) $this->option('generation')))->map(fn ($value) => (int) trim($value))->filter()->values()->all();
        $result = $recovery->recover($numbers ?: [25, 29], (int) $this->option('older-than'), (bool) $this->option('apply'));
        $this->table(['retry', 'technical_quarantine', 'skipped', 'reason'], [[
            $result['retried'], $result['quarantined'], $result['skipped'], $result['reason'],
        ]]);
        return self::SUCCESS;
    }
}
