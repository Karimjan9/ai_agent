<?php

namespace App\Console\Commands;

use App\Services\MarketData\MarketVolumeService;
use Illuminate\Console\Command;

class SyncCanonicalMarketVolume extends Command
{
    protected $signature = 'market-data:sync-volume {symbol} {--timeframe=H1} {--from=} {--to=}';

    protected $description = 'Sync the single canonical Dukascopy Jetta tick-volume source without changing price candles';

    public function handle(MarketVolumeService $volumes): int
    {
        try {
            $result = $volumes->sync(
                strtoupper((string) $this->argument('symbol')),
                strtoupper((string) $this->option('timeframe')),
                $this->date($this->option('from')),
                $this->date($this->option('to')),
            );
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
