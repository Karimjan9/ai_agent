<?php

namespace App\Services\MarketData;

use App\Models\Candle;
use App\Models\Symbol;

class CandlePayloadService
{
    public function __construct(
        private MarketVolumeService $volumes,
        private MarketTrainingDataService $training,
    ) {}

    public function candlesForBacktest(string $symbol, string $timeframe, ?int $limit = null, bool $includeVolume = false): array
    {
        if ($limit === null && ! app()->environment('testing')) {
            throw new \RuntimeException(
                'Unbounded candle payload disabled. Use LabDatasetExportService::export() and dataset_path for replay.'
            );
        }

        $symbolModel = Symbol::query()
            ->where('code', $symbol)
            ->first();

        if (! $symbolModel) {
            return [];
        }

        $query = Candle::query()
            ->where('symbol_id', $symbolModel->id)
            ->where('timeframe', $timeframe);

        if ($limit !== null) {
            $candles = $query->orderByDesc('time')->limit($limit)->get()->sortBy('time')->values();
        } else {
            $candles = $query->orderBy('time')->get();
        }

        $volumeMap = $includeVolume ? $this->volumes->forDataset($symbol, $timeframe) : [];

        return $candles
            ->map(function (Candle $candle) use ($volumeMap, $includeVolume): array {
                $key = $candle->time->copy()->utc()->format('Y-m-d H:i:s');
                $volume = $includeVolume
                    ? ($volumeMap[$key] ?? ['volume' => 0.0, 'available' => false])
                    : ['volume' => (float) ($candle->volume ?? 0), 'available' => null];
                $row = [
                    'time' => $key,
                    'open' => (float) $candle->open,
                    'high' => (float) $candle->high,
                    'low' => (float) $candle->low,
                    'close' => (float) $candle->close,
                    'volume' => (float) $volume['volume'],
                ];
                if ($includeVolume) {
                    $row['volume_available'] = (bool) $volume['available'];
                }

                return $row;
            })
            ->all();
    }

    /**
     * Explicit training/archive payload for agents. This method never falls
     * back to the canonical `candles` table; callers must name the dataset
     * and provider so research history cannot silently become live evidence.
     *
     * @return array<int, array<string, mixed>>
     */
    public function candlesForTraining(
        string $symbol,
        string $timeframe,
        string $dataset = MarketTrainingDataService::DEFAULT_DATASET,
        string $provider = MarketTrainingDataService::DEFAULT_PROVIDER,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
        ?int $limit = null,
    ): array {
        return $this->training->candlesForAgent(
            $dataset,
            $provider,
            $symbol,
            $timeframe,
            $from ? \Carbon\CarbonImmutable::instance($from)->utc() : null,
            $to ? \Carbon\CarbonImmutable::instance($to)->utc() : null,
            $limit,
        );
    }
}
