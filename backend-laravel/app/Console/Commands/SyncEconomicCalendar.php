<?php

namespace App\Console\Commands;

use App\Services\EconomicCalendarService;
use Illuminate\Console\Command;

class SyncEconomicCalendar extends Command
{
    protected $signature = 'trading:sync-economic-calendar {--provider= : financial_modeling_prep, alpha_vantage_news, or currents_api_news}';
    protected $description = 'Synchronize the configured economic-event feed for execution vetoes';

    public function handle(EconomicCalendarService $calendar): int
    {
        $result = $calendar->sync($this->option('provider') ?: null);
        $this->info('Economic calendar: '.$result['status'].'; synced '.($result['synced'] ?? 0).'.');
        return $result['status'] === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
