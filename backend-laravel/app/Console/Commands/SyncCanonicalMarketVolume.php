<?php

namespace App\Console\Commands;

use App\Services\MarketData\MarketVolumeService;
use Illuminate\Console\Command;

class SyncCanonicalMarketVolume extends Command
{
    protected $signature = 'market-data:sync-volume {symbol} {--timeframe=H1} {--from=} {--to=} {--tail-hours= : Refresh only the recent tail; omit for a full archive sync}';

    protected $description = 'Sync the single canonical Dukascopy Jetta tick-volume source without changing price candles';

    public function handle(MarketVolumeService $volumes): int
    {
        try {
            $symbol = strtoupper((string) $this->argument('symbol'));
            $timeframe = strtoupper((string) $this->option('timeframe'));
            $result = filled($this->option('tail-hours'))
                ? $volumes->syncTail($symbol, $timeframe, max(1, (int) $this->option('tail-hours')))
                : $volumes->sync($symbol, $timeframe, $this->date($this->option('from')), $this->date($this->option('to')));
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        $this->info(sprintf(
            '%s %s volume sync: %s rows stored, source=%s, from=%s, to=%s',
            $result['symbol'], $result['timeframe'], $result['stored_rows'],
            $result['contract']['source_contract'], $result['from'], $result['to'],
        ));

        return self::SUCCESS;
    }

    private function date(?string $value): ?\DateTimeImmutable
    {
        return $value ? new \DateTimeImmutable($value, new \DateTimeZone('UTC')) : null;
    }
}
