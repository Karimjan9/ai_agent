<?php

namespace App\Console\Commands;

use App\Models\MarketSymbol;
use App\Services\MarketData\HistoricalDataQualityService;
use App\Services\MarketData\MarketDataService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class RepairHistoricalDataGaps extends Command
{
    protected $signature = 'market-data:repair-gaps
                            {symbol?}
                            {--timeframe=H1}
                            {--provider= : Canonical provider only; non-canonical archives belong in the foundation lane}
                            {--transport=legacy : Dukascopy transport for archival repair (legacy, jetta, or auto)}
                            {--chunk-days=365}
                            {--max-ranges=10}
                            {--dry-run}';

    protected $description = 'Backfill known historical market-open gaps in bounded provider requests';

    public function handle(MarketDataService $marketData, HistoricalDataQualityService $quality): int
    {
        $symbols = $this->argument('symbol')
            ? MarketSymbol::query()->where('symbol', strtoupper((string) $this->argument('symbol')))->get()
            : MarketSymbol::query()->where('is_active', true)->orderBy('symbol')->get();
        $timeframe = (string) $this->option('timeframe');
        $chunkDays = max(1, (int) $this->option('chunk-days'));
        $maxRanges = max(1, min(100, (int) $this->option('max-ranges')));
        $canonicalProvider = strtolower((string) config('services.market_data.canonical_provider', 'twelve'));
        $provider = strtolower(trim((string) ($this->option('provider') ?: $canonicalProvider)));
        if (! in_array($provider, ['dukascopy', 'twelve', 'csv'], true)) {
            $this->error("Market data provider topilmadi: {$provider}");

            return self::INVALID;
        }
        if ($provider !== $canonicalProvider) {
            $this->error("Canonical gap repair faqat {$canonicalProvider} provider bilan ishlaydi; {$provider} foundation/archive lane uchun.");

            return self::INVALID;
        }
        $transport = strtolower(trim((string) $this->option('transport')));
        if ($provider === 'dukascopy' && ! in_array($transport, ['legacy', 'jetta', 'auto'], true)) {
            $this->error("Dukascopy transport topilmadi: {$transport}");

            return self::INVALID;
        }

        // Gap repair must use the explicitly selected archive provider. The
        // normal live-feed fallback chain can waste quota and obscure which
        // source actually supplied historical evidence.
        config([
            'services.market_data.provider' => $provider,
            'services.market_data.fallback_provider' => null,
            // The Jetta monthly archive can return a sparse historical slice
            // with HTTP 200.  Legacy datafeed is the authoritative repair
            // transport because it retains flat candles and has a local cache.
            'services.dukascopy.transport' => $provider === 'dukascopy' ? $transport : config('services.dukascopy.transport'),
            // A repair can re-upsert its boundary candles even when the
            // provider cannot fill the actual hole.  Those maintenance writes
            // must not trigger the expensive market-reality pipeline; the
            // normal scheduled live update will analyse genuinely new data.
            'services.market_reality.enabled' => false,
        ]);
        $failed = false;

        foreach ($symbols as $marketSymbol) {
            $report = $quality->inspect($marketSymbol->symbol, $timeframe, true);
            $ranges = collect($report['gap_examples'])->take($maxRanges);
            if ($ranges->isEmpty()) {
                $this->info("{$marketSymbol->symbol}: repair qilinadigan historical gap yo'q.");
                continue;
            }

            foreach ($ranges as $range) {
                // Include both boundary candles. This lets the Dukascopy tick
                // fallback replace malformed sparse-H1 boundary rows as well
                // as fill the missing hours between them.
                $from = CarbonImmutable::parse($range['after'], 'UTC');
                $end = CarbonImmutable::parse($range['before'], 'UTC')->addHour();
                $this->line("{$marketSymbol->symbol}: {$from->toIso8601String()} -> {$end->toIso8601String()}");
                if ($this->option('dry-run')) {
                    continue;
                }

                for ($cursor = $from; $cursor->lessThan($end); $cursor = $chunkEnd) {
                    $chunkEnd = $cursor->addDays($chunkDays);
                    if ($chunkEnd->greaterThan($end)) {
                        $chunkEnd = $end;
                    }
                    try {
                        $saved = $marketData->updateCandles(
                            marketSymbol: $marketSymbol,
                            timeframe: $timeframe,
                            limit: 5000,
                            from: $cursor,
                            to: $chunkEnd,
                        );
                        $this->info("  {$saved} candles: {$cursor->format('Y-m-d H:i')} -> {$chunkEnd->format('Y-m-d H:i')}");
                    } catch (Throwable $exception) {
                        $failed = true;
                        $this->error('  '.$exception->getMessage());
                    }
                }
            }

            if (! $this->option('dry-run')) {
                $after = $quality->inspect($marketSymbol->symbol, $timeframe, true);
                $this->line("{$marketSymbol->symbol}: remaining missing_open_hours={$after['missing_open_hours']}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
