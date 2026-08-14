<?php

namespace App\Console\Commands;

use App\Services\MarketData\MarketTrainingDataService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class ImportTrainingMarketDataCsv extends Command
{
    protected $signature = 'market-data:import-training-csv
                            {symbol}
                            {path}
                            {--timeframe=H1}
                            {--dataset=foundation_10y}
                            {--provider=dukascopy}
                            {--from= : Optional UTC inclusive boundary}
                            {--to= : Optional UTC exclusive boundary}';

    protected $description = 'Import a historical OHLCV CSV into the isolated agent training archive';

    public function handle(MarketTrainingDataService $training): int
    {
        $symbol = strtoupper((string) $this->argument('symbol'));
        $timeframe = strtoupper((string) $this->option('timeframe'));
        if (! in_array($timeframe, ['H1', 'M15'], true)) {
            $this->error("Unsupported training timeframe: {$timeframe}");

            return self::INVALID;
        }

        try {
            $from = $this->parseBoundary($this->option('from'));
            $to = $this->parseBoundary($this->option('to'));
            $targetFrom = $from ?? CarbonImmutable::now('UTC')->subYears(10)->startOfDay();
            $targetTo = $to ?? $this->lastClosedBoundary($timeframe);
            if ($targetFrom->greaterThanOrEqualTo($targetTo)) {
                throw new \InvalidArgumentException('Training range bo\'sh bo\'lishi mumkin emas.');
            }

            $archive = $training->ensureArchive(
                (string) $this->option('dataset'),
                (string) $this->option('provider'),
                $symbol,
                $timeframe,
                $targetFrom,
                $targetTo,
            );
            $result = $training->importCsv($archive, (string) $this->argument('path'), $from, $to);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $coverage = $result['coverage'];
        $this->info(sprintf(
            '%s %s training archive: imported=%d skipped=%d total=%d (%s -> %s)',
            $symbol,
            $timeframe,
            $result['imported'],
            $result['skipped'],
            $coverage['row_count'],
            $coverage['first_candle_at'] ?? 'none',
            $coverage['last_candle_at'] ?? 'none',
        ));

        return self::SUCCESS;
    }

    private function parseBoundary(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return CarbonImmutable::parse($value, 'UTC')->utc();
    }

    private function lastClosedBoundary(string $timeframe): CarbonImmutable
    {
        $now = CarbonImmutable::now('UTC');

        return $timeframe === 'M15'
            ? $now->setTime($now->hour, intdiv($now->minute, 15) * 15, 0)
            : $now->startOfHour();
    }
}
