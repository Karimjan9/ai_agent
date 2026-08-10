<?php

namespace App\Services;

use App\Models\Candle;
use App\Models\MarketDriftSnapshot;
use App\Models\Symbol;
use Illuminate\Support\Collection;

/**
 * Computes drift only from the canonical price series.
 *
 * A drift number without a provider, cutoff and source hash is not
 * reproducible evidence.  Such legacy rows remain visible for diagnosis but
 * cannot expire skills or trigger a new elite generation.
 */
class MarketDriftDetectionService
{
    public const ALGORITHM_VERSION = 'canonical_drift_v2';

    /** @return array<string, mixed> */
    public function canonicalDataContract(string $symbol, string $timeframe = 'H1', int $limit = 2501): array
    {
        $series = $this->canonicalSeries($symbol, $timeframe, $limit);

        return $this->contract($series, $symbol, $timeframe);
    }

    public function detect(string $symbol, string $timeframe = 'H1'): ?MarketDriftSnapshot
    {
        $series = $this->canonicalSeries($symbol, $timeframe, 2501);
        $contract = $this->contract($series, $symbol, $timeframe);
        if ($contract['status'] !== 'ready') return null;

        $closes = $series->pluck('close')->map(fn ($value): float => (float) $value)->values();
        if ($closes->count() < 1000) return null;

        $returns = $closes->zip($closes->slice(1))->map(
            fn ($pair): float => $pair[0] ? (($pair[1] - $pair[0]) / $pair[0]) : 0.0,
        )->values();
        $recent = $returns->slice(-500)->values();
        $baseline = $returns->slice(0, max(0, $returns->count() - 500))->values();
        $baseMean = (float) $baseline->avg();
        $recentMean = (float) $recent->avg();
        $baseStd = $this->std($baseline->all(), $baseMean);
        $recentStd = $this->std($recent->all(), $recentMean);
        $psi = $this->psi($baseline->all(), $recent->all());
        $ratio = $baseStd > 0 ? $recentStd / $baseStd : 1.0;
        $status = $psi >= 0.25 || $ratio >= 1.5 || $ratio <= 0.67
            ? 'drift'
            : ($psi >= 0.1 ? 'warning' : 'stable');

        $metrics = [
            'algorithm_version' => self::ALGORITHM_VERSION,
            'baseline_samples' => $baseline->count(),
            'recent_samples' => $recent->count(),
            'baseline_std' => $baseStd,
            'recent_std' => $recentStd,
            'canonical_provider' => $contract['canonical_provider'],
            'source_provider' => $contract['provider'],
            'data_hash' => $contract['data_hash'],
            'cutoff_at' => $contract['cutoff_at'],
            'promotion_evidence' => false,
        ];

        return MarketDriftSnapshot::create([
            'symbol' => strtoupper($symbol),
            'timeframe' => strtoupper($timeframe),
            'psi_score' => round($psi, 4),
            'volatility_ratio' => round($ratio, 4),
            'mean_return_shift' => round(abs($recentMean - $baseMean), 6),
            'status' => $status,
            'metrics' => $metrics,
            'detected_at' => now(),
            'provider' => $contract['provider'],
            'data_hash' => $contract['data_hash'],
            'candle_count' => $contract['candle_count'],
            'first_candle_at' => $contract['first_candle_at'],
            'last_candle_at' => $contract['last_candle_at'],
            'cutoff_at' => $contract['cutoff_at'],
            'evidence_status' => $contract['is_canonical'] ? 'valid' : 'unverified',
        ]);
    }

    /**
     * Confirm drift only from fresh, source-identifiable consecutive checks.
     * Repeated legacy rows or a provider switch cannot create confirmation.
     *
     * @return array<string, mixed>
     */
    public function confirmation(string $symbol, string $timeframe = 'H1', ?int $required = null): array
    {
        $required ??= max(2, (int) config('services.drift_evidence.required_confirmations', 3));
        $canonicalProvider = strtolower((string) config(
            'services.market_data.canonical_provider',
            config('services.market_data.provider', 'csv'),
        ));
        $rows = MarketDriftSnapshot::query()
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))
            ->where('status', 'drift')
            ->where('evidence_status', 'valid')
            ->where('provider', $canonicalProvider)
            ->whereNotNull('data_hash')
            ->whereNotNull('cutoff_at')
            ->latest('detected_at')
            ->limit($required)
            ->get()
            ->sortBy('detected_at')
            ->values();

        $hashes = $rows->pluck('data_hash')->filter()->unique()->values();
        $cutoffs = $rows->pluck('cutoff_at')->map(fn ($value) => (string) $value)->values();
        // A new validation observation may repeat the latest closed candle;
        // what makes it independent is its own recorded_at/id, not an
        // artificial requirement that the market must print a new candle.
        $monotonic = $cutoffs->count() <= 1 || $cutoffs->every(
            fn (string $value, int $index): bool => $index === 0 || $value >= (string) $cutoffs[$index - 1],
        );
        $distinctHashMinimum = min($required, max(1, (int) config('services.drift_evidence.minimum_distinct_hashes', 1)));
        $confirmed = $rows->count() >= $required
            && $hashes->count() >= $distinctHashMinimum
            && $monotonic;

        return [
            'protocol' => 'canonical_drift_confirmation_v1',
            'status' => $confirmed ? 'confirmed' : 'waiting',
            'symbol' => strtoupper($symbol),
            'timeframe' => strtoupper($timeframe),
            'provider' => $canonicalProvider,
            'required_confirmations' => $required,
            'observed_confirmations' => $rows->count(),
            'distinct_data_hashes' => $hashes->count(),
            'minimum_distinct_hashes' => $distinctHashMinimum,
            'monotonic_cutoffs' => $monotonic,
            'latest_snapshot_id' => $rows->last()?->id,
            'latest_cutoff_at' => $rows->last()?->cutoff_at?->toIso8601String(),
            'promotion_evidence' => false,
            'rule' => 'Only three separately recorded source-identifiable canonical checks can expire a skill or trigger drift research; a repeated closed cutoff is valid evidence, not a forced new candle.',
        ];
    }

    /** @param array<int, object> $values */
    private function std(array $values, float $mean): float
    {
        return $values === []
            ? 0.0
            : sqrt(array_sum(array_map(fn ($value): float => ($value - $mean) ** 2, $values)) / count($values));
    }

    /** @param array<int, float> $base @param array<int, float> $recent */
    private function psi(array $base, array $recent): float
    {
        if ($base === [] || $recent === []) return 0.0;
        sort($base);
        $n = count($base);
        $score = 0.0;
        for ($i = 0; $i < 10; $i++) {
            $low = $i === 0 ? -INF : $base[(int) floor($n * $i / 10)];
            $high = $i === 9 ? INF : $base[(int) floor($n * ($i + 1) / 10) - 1];
            $baseRatio = max(0.0001, count(array_filter($base, fn ($value): bool => $value >= $low && $value <= $high)) / $n);
            $recentRatio = max(0.0001, count(array_filter($recent, fn ($value): bool => $value >= $low && $value <= $high)) / count($recent));
            $score += ($recentRatio - $baseRatio) * log($recentRatio / $baseRatio);
        }

        return $score;
    }

    /** @return Collection<int, Candle> */
    private function canonicalSeries(string $symbol, string $timeframe, int $limit): Collection
    {
        $symbolId = Symbol::query()->where('code', strtoupper($symbol))->value('id');
        if (! $symbolId) return collect();

        $canonicalProvider = strtolower((string) config(
            'services.market_data.canonical_provider',
            config('services.market_data.provider', 'csv'),
        ));
        $query = Candle::query()
            ->where('symbol_id', $symbolId)
            ->where('timeframe', strtoupper($timeframe))
            ->where('provider', $canonicalProvider)
            ->orderByDesc('time');
        $series = $query->limit($limit)->get(['time', 'open', 'high', 'low', 'close', 'provider']);

        // Test fixtures historically omitted provider.  Keep that fallback
        // test-only; production must fail closed when the canonical stream is
        // not present instead of silently mixing providers.
        if ($series->count() < 1000 && app()->environment('testing')) {
            $series = Candle::query()
                ->where('symbol_id', $symbolId)
                ->where('timeframe', strtoupper($timeframe))
                ->orderByDesc('time')
                ->limit($limit)
                ->get(['time', 'open', 'high', 'low', 'close', 'provider']);
        }

        return $series->sortBy('time')->values();
    }

    /** @param Collection<int, Candle> $series @return array<string, mixed> */
    private function contract(Collection $series, string $symbol, string $timeframe): array
    {
        $canonicalProvider = strtolower((string) config(
            'services.market_data.canonical_provider',
            config('services.market_data.provider', 'csv'),
        ));
        $provider = strtolower((string) ($series->first()?->provider ?: $canonicalProvider));
        $payload = $series->map(fn (Candle $candle): array => [
            'time' => $candle->time?->utc()->toIso8601String(),
            'open' => (string) $candle->open,
            'high' => (string) $candle->high,
            'low' => (string) $candle->low,
            'close' => (string) $candle->close,
            'provider' => (string) $candle->provider,
        ])->all();
        $hash = $payload === [] ? null : hash('sha256', json_encode([
            'algorithm_version' => self::ALGORITHM_VERSION,
            'symbol' => strtoupper($symbol),
            'timeframe' => strtoupper($timeframe),
            'provider' => $provider,
            'rows' => $payload,
        ], JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES));
        $first = $series->first()?->time;
        $last = $series->last()?->time;
        $isCanonical = $provider === $canonicalProvider;

        return [
            'status' => $series->count() >= 1000 && ($isCanonical || app()->environment('testing')) ? 'ready' : 'insufficient',
            'provider' => $provider,
            'canonical_provider' => $canonicalProvider,
            'is_canonical' => $isCanonical,
            'candle_count' => $series->count(),
            'first_candle_at' => $first?->toDateTimeString(),
            'last_candle_at' => $last?->toDateTimeString(),
            'cutoff_at' => $last?->toDateTimeString(),
            'data_hash' => $hash,
            'algorithm_version' => self::ALGORITHM_VERSION,
            'symbol' => strtoupper($symbol),
            'timeframe' => strtoupper($timeframe),
            'promotion_evidence' => false,
        ];
    }
}
