<?php

namespace App\Console\Commands;

use App\Services\OfficialUsdCalendarBackfillService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SyncOfficialUsdCalendar extends Command
{
    protected $signature = 'trading:sync-official-us-calendar {--from= : UTC date/time lower bound} {--to= : UTC date/time upper bound} {--year=2026}';

    protected $description = 'Backfill curated official BLS USD release dates without weakening calendar gates';

    public function handle(OfficialUsdCalendarBackfillService $backfill): int
    {
        try {
            $from = $this->option('from') ? Carbon::parse((string) $this->option('from'), 'UTC') : null;
            $to = $this->option('to') ? Carbon::parse((string) $this->option('to'), 'UTC') : null;
            if ($from && $to && $from->gt($to)) {
                $this->error('--from must not be after --to.');
                return self::FAILURE;
            }
            $result = $backfill->backfill($from, $to, (int) $this->option('year'));
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $result['status'] === 'completed' ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $exception) {
            $this->error('Official calendar backfill failed: '.$exception->getMessage());
            return self::FAILURE;
        }
    }
}
