<?php

namespace App\Console\Commands;

use App\Services\EconomicCalendarService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SyncEconomicCalendar extends Command
{
    protected $signature = 'trading:sync-economic-calendar
        {--provider= : financial_modeling_prep, alpha_vantage_news, or currents_api_news}
        {--from= : Optional UTC start date for official historical backfill}
        {--to= : Optional UTC end date for official historical backfill}';
    protected $description = 'Synchronize the configured economic-event feed for execution vetoes';

    public function handle(EconomicCalendarService $calendar): int
    {
        try {
            $from = $this->option('from') ? Carbon::parse((string) $this->option('from'), 'UTC')->startOfDay() : null;
            $to = $this->option('to') ? Carbon::parse((string) $this->option('to'), 'UTC')->endOfDay() : null;
        } catch (\Throwable) {
            $this->error('Calendar backfill dates must be valid UTC dates.');
            return self::INVALID;
        }
        if ($from && $to && $from->greaterThan($to)) {
            $this->error('Calendar backfill --from cannot be after --to.');
            return self::INVALID;
        }
        $result = $calendar->sync($this->option('provider') ?: null, $from, $to);
        $this->info('Economic calendar: '.$result['status'].'; synced '.($result['synced'] ?? 0).'.');
        return $result['status'] === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
