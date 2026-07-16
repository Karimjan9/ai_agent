<?php

namespace App\Services;

use App\Models\CotFeatureSnapshot;
use App\Models\CotReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CotMarketIntelligenceService
{
    public const FEATURE_VERSION = 'cot_v1';

    /**
     * Imports official CFTC rows and creates descriptive features only.
     * No strategy, score, or order is changed by this service.
     */
    public function syncGoldReports(int $limit = 260): array
    {
        $response = Http::acceptJson()
            ->timeout((int) config('services.cot.timeout_seconds', 30))
            ->get(config('services.cot.endpoint'), [
                '$limit' => max(1, min($limit, 2_000)),
                '$where' => "market_and_exchange_names='".config('services.cot.gold_market_name')."'",
                '$order' => 'report_date_as_yyyy_mm_dd DESC',
            ]);

        if ($response->failed()) {
            throw new RuntimeException('CFTC COT import failed: HTTP '.$response->status());
        }

        $rows = collect($response->json());
        $created = 0;

        foreach ($rows as $row) {
            if (! isset($row['id'], $row['report_date_as_yyyy_mm_dd'])) {
                continue;
            }

            $report = CotReport::firstOrCreate(
                ['source_record_id' => (string) $row['id']],
                $this->reportAttributes($row),
            );

            if ($report->wasRecentlyCreated) {
                $created++;
            }
        }

        $features = $this->refreshFeatures();

        return ['received' => $rows->count(), 'created' => $created, 'features' => $features];
    }

    public function refreshFeatures(string $symbol = 'XAUUSD'): int
    {
        $reports = CotReport::query()
            ->where('symbol', $symbol)
            ->orderBy('report_date')
            ->get();
        $created = 0;

        foreach ($reports as $index => $report) {
            $history = $reports->take($index + 1);
            $existing = CotFeatureSnapshot::query()->firstOrCreate(
                ['cot_report_id' => $report->id, 'feature_version' => self::FEATURE_VERSION],
                $this->featureAttributes($report, $history),
            );

            if ($existing->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    private function reportAttributes(array $row): array
    {
        $reportDate = CarbonImmutable::parse($row['report_date_as_yyyy_mm_dd'], 'America/New_York')->startOfDay();

        return [
            'symbol' => 'XAUUSD',
            'source' => 'cftc_disaggregated_futures_only',
            'market_name' => (string) ($row['market_and_exchange_names'] ?? config('services.cot.gold_market_name')),
            'report_date' => $reportDate->toDateString(),
            // CFTC normally releases a Tuesday report on Friday 15:30 ET. The exact
            // historical holiday release timestamp is not inferred by this Phase 1 importer.
            'available_at' => $reportDate->next(CarbonImmutable::FRIDAY)->setTime(15, 30)->utc(),
            'release_time_estimated' => true,
            'open_interest' => $this->integer($row, 'open_interest_all'),
            'managed_money_long' => $this->integer($row, 'm_money_positions_long_all'),
            'managed_money_short' => $this->integer($row, 'm_money_positions_short_all'),
            'managed_money_spread' => $this->integer($row, 'm_money_positions_spread'),
            'managed_money_net' => $this->integer($row, 'm_money_positions_long_all') - $this->integer($row, 'm_money_positions_short_all'),
            'commercial_long' => $this->integer($row, 'prod_merc_positions_long'),
            'commercial_short' => $this->integer($row, 'prod_merc_positions_short'),
            'commercial_net' => $this->integer($row, 'prod_merc_positions_long') - $this->integer($row, 'prod_merc_positions_short'),
            'raw_payload' => $row,
            'ingested_at' => now(),
        ];
    }

    private function featureAttributes(CotReport $report, Collection $history): array
    {
        $netPositions = $history->pluck('managed_money_net')->map(fn ($value): int => (int) $value);
        $commercialPositions = $history->pluck('commercial_net')->map(fn ($value): int => (int) $value);
        $sample = $netPositions->take(-156)->values();
        $commercialSample = $commercialPositions->take(-156)->values();
        $percentile = $this->percentile($sample, (int) $report->managed_money_net);
        $commercialPercentile = $this->percentile($commercialSample, (int) $report->commercial_net);
        $delta1w = $history->count() > 1
            ? (int) $report->managed_money_net - (int) $history->get($history->count() - 2)->managed_money_net
            : null;
        $delta4w = $history->count() > 4
            ? (int) $report->managed_money_net - (int) $history->get($history->count() - 5)->managed_money_net
            : null;
        $crowding = round(abs($percentile - 50) * 2, 2);
        $positioning = $this->positioningState($percentile);

        return [
            'symbol' => $report->symbol,
            'report_date' => $report->report_date,
            'available_at' => $report->available_at,
            'managed_money_net' => $report->managed_money_net,
            'managed_money_delta_1w' => $delta1w,
            'managed_money_delta_4w' => $delta4w,
            'managed_money_average_12w' => round((float) $netPositions->take(-12)->avg(), 2),
            'managed_money_percentile_3y' => $percentile,
            'commercial_percentile_3y' => $commercialPercentile,
            'crowding_index' => $crowding,
            'positioning_state' => $positioning,
            'weekly_bias' => $this->weeklyBias($percentile, $delta1w),
            'confidence_score' => round(min(95, 35 + min(60, $history->count() / 156 * 60)), 2),
            'features' => [
                'managed_money_net' => $report->managed_money_net,
                'managed_money_delta_1w' => $delta1w,
                'managed_money_delta_4w' => $delta4w,
                'managed_money_average_12w' => round((float) $netPositions->take(-12)->avg(), 2),
                'managed_money_percentile_3y' => $percentile,
                'commercial_net' => $report->commercial_net,
                'commercial_percentile_3y' => $commercialPercentile,
                'open_interest' => $report->open_interest,
                'crowding_index' => $crowding,
                'sample_size' => $history->count(),
                'release_time_estimated' => $report->release_time_estimated,
                'purpose' => 'read_only_market_intelligence',
            ],
        ];
    }

    private function percentile(Collection $sample, int $value): float
    {
        if ($sample->isEmpty()) {
            return 50;
        }

        return round($sample->filter(fn (int $item): bool => $item <= $value)->count() / $sample->count() * 100, 2);
    }

    private function positioningState(float $percentile): string
    {
        return match (true) {
            $percentile >= 90 => 'crowded_long',
            $percentile >= 60 => 'bullish',
            $percentile <= 10 => 'crowded_short',
            $percentile <= 40 => 'bearish',
            default => 'neutral',
        };
    }

    private function weeklyBias(float $percentile, ?int $delta1w): string
    {
        return match (true) {
            $percentile >= 60 && ($delta1w === null || $delta1w >= 0) => 'bullish',
            $percentile <= 40 && ($delta1w === null || $delta1w <= 0) => 'bearish',
            default => 'neutral',
        };
    }

    private function integer(array $row, string $key): int
    {
        return (int) ($row[$key] ?? 0);
    }
}
