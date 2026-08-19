<?php

namespace App\Services;

use App\Models\AiLaboratory;
use App\Models\LabGeneration;
use App\Models\LearningProtocolBaseline;
use App\Models\ModelMarketPerformance;
use Illuminate\Support\Facades\Schema;

/** Freezes the incumbent champion facts used by the council transition gate. */
class ChampionIncumbentBaselineService
{
    public const PROTOCOL = 'champion_council_incumbent_baseline_v1';

    /** @return array<string, mixed> */
    public function freeze(string $symbol, string $timeframe): array
    {
        $symbol = strtoupper($symbol);
        $timeframe = strtoupper($timeframe);
        if (! Schema::hasTable('model_market_performance') || ! Schema::hasTable('learning_protocol_baselines')) {
            return $this->blocked($symbol, $timeframe, 'BASELINE_MIGRATION_PENDING');
        }

        $performances = ModelMarketPerformance::query()->with('modelVersion')
            ->where('symbol', $symbol)->where('timeframe', $timeframe)
            ->where('status', 'champion')->whereNull('invalidated_at')
            ->orderBy('champion_slot')->orderByDesc('promoted_at')->get();
        if ($performances->isEmpty()) {
            return $this->blocked($symbol, $timeframe, 'INCUMBENT_CHAMPION_MISSING');
        }

        $lab = AiLaboratory::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->first();
        $generation = $lab?->generations()->latest('id')->first();
        if (! $generation) return $this->blocked($symbol, $timeframe, 'BASELINE_GENERATION_MISSING');

        $snapshot = [
            'protocol' => self::PROTOCOL,
            'scope' => ['symbol' => $symbol, 'timeframe' => $timeframe],
            'captured_at' => now()->toIso8601String(),
            'incumbent' => $performances->map(fn (ModelMarketPerformance $performance): array => $this->performanceSnapshot($performance))->values()->all(),
            'measurement_contract' => [
                'profit_factor' => 'fitness/metrics profit factor at freeze time',
                'drawdown' => 'metrics max drawdown percentage',
                'stress_cost_pf' => 'screening_survival or pf_attribution stress cost PF',
                'regime_coverage' => 'metrics regime performance map',
                'worst_window' => 'screening_survival worst temporal/window PF',
                'router_behavior' => 'metrics router/switch trace',
                'trade_behavior' => 'metrics trade counts, sides, entries and exits',
            ],
            'promotion_evidence' => false,
        ];
        $json = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $baseline = LearningProtocolBaseline::firstOrCreate([
            'protocol_version' => self::PROTOCOL,
            'lab_generation_id' => $generation->id,
        ], [
            'snapshot_hash' => hash('sha256', $json),
            'snapshot' => $snapshot,
            'frozen_at' => now(),
        ]);

        return [
            'status' => 'frozen',
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'baseline_id' => (int) $baseline->id,
            'generation_id' => (int) $generation->id,
            'snapshot_hash' => (string) $baseline->snapshot_hash,
            'already_frozen' => ! $baseline->wasRecentlyCreated,
            'champion_count' => $performances->count(),
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function current(string $symbol, string $timeframe): array
    {
        $baseline = LearningProtocolBaseline::query()->where('protocol_version', self::PROTOCOL)
            ->whereHas('generation.laboratory', fn ($query) => $query->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe)))
            ->latest('id')->first();
        return $baseline ? [
            'status' => 'frozen', 'baseline_id' => (int) $baseline->id,
            'snapshot_hash' => (string) $baseline->snapshot_hash,
            'frozen_at' => $baseline->frozen_at?->toIso8601String(),
        ] : $this->blocked(strtoupper($symbol), strtoupper($timeframe), 'INCUMBENT_BASELINE_MISSING');
    }

    /** @return array<string, mixed> */
    private function performanceSnapshot(ModelMarketPerformance $performance): array
    {
        $metrics = (array) $performance->metrics;
        return [
            'performance_id' => (int) $performance->id,
            'model_version_id' => (int) $performance->model_version_id,
            'strategy_family' => $performance->strategy_family,
            'champion_slot' => $performance->champion_slot,
            'pf' => $this->firstNumber($metrics, ['profit_factor', 'pf', 'fitness'], (float) $performance->fitness),
            'drawdown' => $this->firstNumber($metrics, ['max_drawdown_percent', 'max_drawdown', 'drawdown']),
            'stress_cost_pf' => $this->firstNumber($metrics, ['screening_survival.stress_cost_pf', 'pf_attribution.stress_cost.profit_factor', 'stress_test.profit_factor']),
            'regime_coverage' => (array) data_get($metrics, 'regime_performance', data_get($metrics, 'regime_coverage', [])),
            'worst_window' => $this->firstNumber($metrics, ['screening_survival.worst_window_pf', 'screening_survival.worst_temporal_chunk_pf', 'monthly_passport.worst_month_pf']),
            'router_behavior' => (array) data_get($metrics, 'router', data_get($metrics, 'router_behavior', [])),
            'trade_behavior' => (array) data_get($metrics, 'trade_behavior', [
                'sample_count' => $performance->sample_count,
                'rolling_windows' => $performance->rolling_windows_count,
                'forward_wins' => $performance->rolling_forward_wins,
                'paper_sample_count' => $performance->paper_sample_count,
            ]),
            'raw_metric_hash' => hash('sha256', json_encode($metrics, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)),
        ];
    }

    private function firstNumber(array $metrics, array $paths, ?float $fallback = null): ?float
    {
        foreach ($paths as $path) {
            $value = data_get($metrics, $path);
            if (is_numeric($value)) return (float) $value;
        }
        return $fallback;
    }

    private function blocked(string $symbol, string $timeframe, string $reason): array
    {
        return ['status' => 'blocked', 'symbol' => $symbol, 'timeframe' => $timeframe, 'reason_code' => $reason, 'promotion_evidence' => false];
    }
}
