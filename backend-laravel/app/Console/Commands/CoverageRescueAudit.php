<?php

namespace App\Console\Commands;

use App\Services\CoverageRescueAuditService;
use App\Services\LabPopulationService;
use Illuminate\Console\Command;

class CoverageRescueAudit extends Command
{
    protected $signature = 'trading:coverage-rescue {--symbol=XAUUSD} {--generation= : Generation to audit; latest completed generation when omitted} {--seal : Append and seal the formal audit} {--open : Open the coverage-only child generation after a successful sealed audit}';
    protected $description = 'Audit dynamic edge/coverage evidence and optionally open only a frozen-parent coverage rescue generation';

    public function handle(CoverageRescueAuditService $auditor, LabPopulationService $population): int
    {
        $generation = $this->option('generation');
        $audit = $auditor->audit((string) $this->option('symbol'), filled($generation) ? (int) $generation : null);
        $this->line(json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if ((bool) $this->option('seal')) $auditor->seal($audit);
        if ((bool) $this->option('open')) {
            if (! (bool) $audit['eligible']) { $this->error('Coverage rescue refused: audit is not eligible.'); return self::FAILURE; }
            if (! (bool) $this->option('seal')) { $this->error('--open requires --seal so the audit is immutable first.'); return self::FAILURE; }
            $generation = $population->build((string) $audit['symbol'], 'coverage_rescue', true, (string) $audit['timeframe'], $audit);
            $generation ? $this->info('Coverage rescue generation '.$generation->generation.' opened.') : $this->warn('No coverage rescue generation opened (active generation or contract gate).');
        }
        return self::SUCCESS;
    }
}
