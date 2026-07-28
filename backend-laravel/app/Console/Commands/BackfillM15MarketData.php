<?php

namespace App\Console\Commands;

use App\Models\MarketSymbol;
use App\Services\MarketData\HistoricalDataQualityService;
use App\Services\MarketData\MarketDataAuditService;
use App\Services\MarketData\MarketDataService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class BackfillM15MarketData extends Command
{
    protected $signature = 'market-data:backfill-m15
                            {--symbol=EURUSD : Active symbol to import}
                            {--from= : UTC start (required)}
                            {--to= : UTC end; defaults to the last closed M15 boundary}
                            {--chunk-days=7 : Import backwards in chunks of this size}';

    protected $description = 'Backfill M15 history newest-to-oldest and stop immediately on a gap audit failure';

    public function handle(
        MarketDataService $marketData,
        MarketDataAuditService $audit,
        HistoricalDataQualityService $quality,
    ): int {
        $symbolCode = strtoupper((string) $this->option('symbol'));
        $fromOption = (string) $this->option('from');
        if ($fromOption === '') {
            $this->error('--from is required (for example: 2026-05-01 00:00:00 UTC).');

            return self::FAILURE;
        }

        try {
            $from = CarbonImmutable::parse($fromOption, 'UTC');
            $to = $this->option('to')
                ? CarbonImmutable::parse((string) $this->option('to'), 'UTC')
                : $this->lastClosedM15Boundary();
        } catch (Throwable $exception) {
            $this->error('Invalid UTC boundary: '.$exception->getMessage());

            return self::FAILURE;
        }

        $chunkDays = max(1, min(31, (int) $this->option('chunk-days')));
        if ($from->greaterThanOrEqualTo($to)) {
            $this->error('--from must be before --to.');

            return self::FAILURE;
        }

        $marketSymbol = MarketSymbol::query()->where('symbol', $symbolCode)->where('is_active', true)->first();
        if (! $marketSymbol) {
            $this->error("Active symbol not found: {$symbolCode}");

            return self::FAILURE;
        }

        $provider = (string) config('services.market_data.provider', 'dukascopy');
        $chunkEnd = $to;
        while ($chunkEnd->greaterThan($from)) {
            $chunkStart = $chunkEnd->subDays($chunkDays);
            if ($chunkStart->lessThan($from)) {
                $chunkStart = $from;
            }

            try {
                $saved = $marketData->updateCandles($marketSymbol, 'M15', 10000, $chunkStart, $chunkEnd);
                $auditReport = $audit->audit($provider, $symbolCode, 'M15');
                $qualityReport = $quality->inspect($symbolCode, 'M15', true);
            } catch (Throwable $exception) {
                $this->error("{$chunkStart->toIso8601String()} → {$chunkEnd->toIso8601String()}: {$exception->getMessage()}");

                return self::FAILURE;
            }

            $missing = (int) ($qualityReport['missing_open_candles'] ?? $qualityReport['missing_open_hours'] ?? 0);
            $gaps = (int) ($auditReport['unexpected_gaps'] ?? 0);
            $this->line(sprintf(
                '%s → %s: %d candles; audit gaps=%d; historical missing=%d; rows=%d',
                $chunkStart->format('Y-m-d H:i'),
                $chunkEnd->format('Y-m-d H:i'),
                $saved,
                $gaps,
                $missing,
                (int) ($qualityReport['row_count'] ?? 0),
            ));

            // A short archive remains "blocked" until it has 5,000 rows;
            // that is expected during controlled backfill.  Any actual gap is
            // not expected and must halt before later chunks mask its source.
            if ($gaps > 0 || $missing > 0) {
                $this->error('Stopped: M15 gap audit failed. Repair this exact chunk before continuing.');

                return self::FAILURE;
            }

            $chunkEnd = $chunkStart;
        }

        $this->info('M15 backfill range completed with zero audited open-market gaps.');

        return self::SUCCESS;
    }

    private function lastClosedM15Boundary(): CarbonImmutable
    {
        $now = CarbonImmutable::now('UTC');

        return $now->setTime($now->hour, intdiv($now->minute, 15) * 15, 0);
    }
}
