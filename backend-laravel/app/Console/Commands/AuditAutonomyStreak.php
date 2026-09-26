<?php

namespace App\Console\Commands;

use App\Services\GenerationAutonomyReceiptService;
use Illuminate\Console\Command;

class AuditAutonomyStreak extends Command
{
    protected $signature = 'trading:audit-autonomy-streak
        {symbol=XAUUSD}
        {--timeframe=H1}
        {--required=2}
        {--json}
        {--strict}';

    protected $description = 'Require consecutive immutable clean-generation autonomy receipts';

    public function handle(GenerationAutonomyReceiptService $receipts): int
    {
        $report = $receipts->consecutiveProof(
            strtoupper((string) $this->argument('symbol')),
            strtoupper((string) $this->option('timeframe')),
            (int) $this->option('required'),
        );
        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(sprintf(
                'Autonomy streak: %s (%d/%d)',
                strtoupper((string) $report['status']),
                (int) $report['observed'],
                (int) $report['required'],
            ));
            if ((array) $report['reason_codes'] !== []) {
                $this->warn(implode(', ', (array) $report['reason_codes']));
            }
        }

        return $this->option('strict') && ! ($report['passed'] ?? false)
            ? self::FAILURE
            : self::SUCCESS;
    }
}
