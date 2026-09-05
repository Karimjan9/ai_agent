<?php

namespace App\Console\Commands;

use App\Models\Candle;
use App\Models\MarketCandleObservation;
use App\Models\MarketSymbol;
use App\Models\Symbol;
use App\Services\MarketData\HistoricalDataQualityService;
use App\Services\MarketData\TwelveDataMarketDataProvider;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class RepairIntradayShadowGaps extends Command
{
    protected $signature = 'market-data:repair-intraday-shadow-gaps {--symbol=XAUUSD}';

    protected $description = 'Repair only audited XAUUSD M1 shadow gaps from Twelve Data; never overwrite existing Dukascopy candles';

    public function handle(HistoricalDataQualityService $quality, TwelveDataMarketDataProvider $twelve): int
    {
        $symbol = strtoupper((string) $this->option('symbol'));
        if ($symbol !== 'XAUUSD') {
            $this->error('Intraday gap repair faqat XAUUSD uchun ruxsat etilgan.');

            return self::INVALID;
        }
        $marketSymbol = MarketSymbol::query()->where('symbol', $symbol)->where('is_active', true)->first();
        $symbolId = Symbol::query()->where('code', $symbol)->value('id');
        if (! $marketSymbol || ! $symbolId) {
            $this->error('Active canonical XAUUSD symbol topilmadi.');

            return self::FAILURE;
        }

        $report = $quality->inspect($symbol, 'M1', true);
        $missing = $this->missingTimes($report['gap_examples'] ?? [], $quality, $symbol);
        if ($missing === []) {
            $this->info('XAUUSD M1 audited gap topilmadi.');

            return self::SUCCESS;
        }

        $saved = 0;
        $skippedDays = [];
        $repairedDays = [];
        foreach (collect($missing)->groupBy(fn (CarbonImmutable $time): string => $time->toDateString()) as $date => $times) {
            $day = CarbonImmutable::parse($date, 'UTC')->startOfDay();
            try {
                $source = $twelve->fetchCandles($symbol, $marketSymbol->provider_symbol ?? $symbol, 'M1', 2000, $day, $day->addDay());
            } catch (\Throwable $exception) {
                $skippedDays[$date] = 'Twelve Data fetch failed: '.$exception->getMessage();
                continue;
            }
            $byTime = collect($source)->keyBy('time');
            $agreement = $this->sourceAgreement($symbolId, $day, $byTime);
            if (! $agreement['accepted']) {
                $skippedDays[$date] = $agreement['reason'];
                continue;
            }
            $rows = [];
            foreach ($times as $time) {
                $row = $byTime->get($time->format('Y-m-d H:i:s'));
                if (! $row || Candle::query()->where('symbol_id', $symbolId)->where('timeframe', 'M1')->where('time', $time)->exists()) {
                    $skippedDays[$date] = 'Twelve Data missing required minute.';
                    continue;
                }
                $rows[] = [
                    'symbol_id' => $symbolId, 'timeframe' => 'M1', 'time' => $row['time'],
                    'open' => $row['open'], 'high' => $row['high'], 'low' => $row['low'], 'close' => $row['close'], 'volume' => $row['volume'],
                    'provider' => 'twelve_gap_repair', 'created_at' => now(), 'updated_at' => now(),
                ];
            }
            if ($rows === []) continue;
            Candle::query()->upsert($rows, ['symbol_id', 'timeframe', 'time'], ['open', 'high', 'low', 'close', 'volume', 'provider', 'updated_at']);
            MarketCandleObservation::query()->upsert(collect($rows)->map(fn (array $row): array => [
                'provider' => 'twelve_gap_repair', 'symbol' => $symbol, 'timeframe' => 'M1', 'time' => $row['time'],
                'open' => $row['open'], 'high' => $row['high'], 'low' => $row['low'], 'close' => $row['close'], 'volume' => $row['volume'],
                'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'],
            ])->all(), ['provider', 'symbol', 'timeframe', 'time'], ['open', 'high', 'low', 'close', 'volume', 'updated_at']);
            $saved += count($rows);
            $repairedDays[] = $day;
        }

        // M5/M30 never use a provider's independently aggregated bars.  If
        // a verified M1 repair was accepted, rebuild only the affected UTC
        // days from the frozen M1 sequence before publishing quality again.
        foreach (collect($repairedDays)->unique(fn (CarbonImmutable $day): string => $day->toDateString()) as $day) {
            Artisan::call('market-data:backfill-intraday-shadow', [
                '--symbol' => $symbol,
                '--from' => $day->format('Y-m-d H:i:s'),
                '--to' => $day->addDay()->format('Y-m-d H:i:s'),
                '--derive-only' => true,
                '--chunk-days' => 1,
                '--max-chunks' => 1,
            ]);
        }

        $after = $quality->inspect($symbol, 'M1', true);
        $this->line(json_encode([
            'symbol' => $symbol,
            'source' => 'twelve_data_gap_repair',
            'candidate_missing_m1' => count($missing),
            'saved' => $saved,
            'derived_m5_m30_days' => collect($repairedDays)->map(fn (CarbonImmutable $day): string => $day->toDateString())->unique()->values()->all(),
            'skipped_days' => $skippedDays,
            'post_repair_status' => $after['status'],
            'remaining_missing_m1' => $after['missing_open_candles'],
            'promotion_evidence' => false,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $after['status'] === 'ready' ? self::SUCCESS : self::FAILURE;
    }

    /** @param \Illuminate\Support\Collection<string, array<string, mixed>> $source */
    private function sourceAgreement(int $symbolId, CarbonImmutable $day, \Illuminate\Support\Collection $source): array
    {
        $primary = Candle::query()
            ->where('symbol_id', $symbolId)
            ->where('timeframe', 'M1')
            ->where('provider', 'dukascopy')
            ->where('time', '>=', $day)
            ->where('time', '<', $day->addDay())
            ->get(['time', 'close']);
        $bps = $primary->map(function (Candle $row) use ($source): ?float {
            $candidate = $source->get($row->time->utc()->format('Y-m-d H:i:s'));
            $close = (float) $row->close;
            if (! $candidate || $close <= 0) return null;

            return abs((((float) $candidate['close'] - $close) / $close) * 10000);
        })->filter(fn (?float $value): bool => $value !== null)->values();
        if ($bps->count() < 30) {
            return ['accepted' => false, 'reason' => 'Secondary source has fewer than 30 primary-overlap comparisons.'];
        }
        $median = (float) $bps->sort()->values()->get((int) floor(($bps->count() - 1) / 2));
        $limit = (float) config('services.historical_data.intraday_gap_repair_max_median_bps', 25);
        if ($median > $limit) {
            return ['accepted' => false, 'reason' => "Secondary/primary median close divergence {$median} bps exceeds {$limit} bps."];
        }

        return ['accepted' => true, 'median_bps' => $median, 'overlap' => $bps->count()];
    }

    /** @return list<CarbonImmutable> */
    private function missingTimes(array $examples, HistoricalDataQualityService $quality, string $symbol): array
    {
        $times = [];
        foreach ($examples as $example) {
            $after = CarbonImmutable::parse((string) ($example['after'] ?? ''), 'UTC');
            $before = CarbonImmutable::parse((string) ($example['before'] ?? ''), 'UTC');
            for ($time = $after->addMinute(); $time->lessThan($before); $time = $time->addMinute()) {
                if ($quality->isExpectedMarketOpen($time, $symbol, 1)) $times[$time->format('Y-m-d H:i:s')] = $time;
            }
        }

        return array_values($times);
    }
}
