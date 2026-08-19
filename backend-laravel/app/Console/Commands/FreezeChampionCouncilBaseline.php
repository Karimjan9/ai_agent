<?php

namespace App\Console\Commands;

use App\Services\ChampionIncumbentBaselineService;
use Illuminate\Console\Command;

class FreezeChampionCouncilBaseline extends Command
{
    protected $signature = 'trading:freeze-champion-council-baseline {symbol?} {--timeframe=H1} {--json}';
    protected $description = 'Freeze an immutable incumbent snapshot for Champion Council transition';

    public function handle(ChampionIncumbentBaselineService $baseline): int
    {
        $result = $baseline->freeze(strtoupper((string) ($this->argument('symbol') ?: 'XAUUSD')), strtoupper((string) $this->option('timeframe')));
        if ($this->option('json')) $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        elseif ($result['status'] === 'frozen') $this->info('Incumbent baseline frozen: '.$result['snapshot_hash']);
        else $this->warn('Baseline blocked: '.($result['reason_code'] ?? 'unknown'));
        return $result['status'] === 'frozen' ? self::SUCCESS : self::FAILURE;
    }
}
