<?php

namespace App\Services;

use App\Models\ModelMarketPerformance;
use App\Models\PaperConfidenceCalibration;
use App\Models\PaperSignal;
use App\Models\PaperSignalOutcome;
use Illuminate\Support\Collection;

class PaperConfidenceCalibrationService
{
    /** Rebuild all relevant scopes after an immutable paper outcome is written. */
    public function learn(ModelMarketPerformance $candidate, PaperSignal $signal): void
    {
        foreach ($this->scopes($candidate, $signal) as $scope) {
            $rows = $this->outcomes($scope, $candidate, $signal);
            $this->store($scope, $candidate, $signal, $rows);
        }
    }

    /**
     * Bayesian bin reliability mapping.  Until a scope owns enough closed
     * paper observations we retain raw confidence and explicitly mark it as
     * uncalibrated; no backtest result may impersonate paper evidence.
     */
    public function calibrate(ModelMarketPerformance $candidate, string $regime, float $raw): array
    {
        $raw = max(0.0, min(1.0, $raw));
        $scopes = [
            'candidate:'.$candidate->id.':'.$regime,
            'family:'.$candidate->symbol.':'.$candidate->timeframe.':'.$candidate->strategy_family.':'.$regime,
            'family:'.$candidate->symbol.':'.$candidate->timeframe.':'.$candidate->strategy_family.':all',
        ];
        $calibration = PaperConfidenceCalibration::query()->whereIn('scope_key', $scopes)
            ->orderByDesc('sample_count')->first();
        $minimum = (int) config('services.paper_calibration.minimum_samples', 20);
        if (! $calibration || $calibration->sample_count < $minimum) {
            return ['confidence' => $raw, 'status' => 'insufficient_paper_evidence', 'sample_count' => $calibration?->sample_count ?? 0, 'allowed' => true];
        }

        $bucket = $this->bucket($raw);
        $bin = data_get($calibration->bins, (string) $bucket, []);
        // Beta(1,1) smoothing avoids a single lucky loss/win forcing a zero/one probability.
        $calibrated = (float) (data_get($bin, 'posterior_win_probability') ?? $raw);
        $edge = (array) data_get($bin, 'edge', []);
        $lowerEdge = data_get($edge, 'lower_expected_value_percent');
        $edgeAssessed = is_numeric($lowerEdge);
        return [
            'confidence' => round($calibrated, 4), 'status' => 'calibrated',
            'sample_count' => $calibration->sample_count, 'scope' => $calibration->scope_key,
            'expected_edge' => $edge,
            'reliability_error' => $calibration->reliability_error,
            // Confidence is an edge probability only when its lower-bound EV
            // is positive. Legacy bins without immutable P/L retain their
            // old probability-only behaviour until they are rebuilt.
            'allowed' => $calibrated >= (float) config('services.paper_calibration.minimum_calibrated_confidence', .55)
                && (! $edgeAssessed || (float) $lowerEdge > 0),
        ];
    }

    private function scopes(ModelMarketPerformance $candidate, PaperSignal $signal): array
    {
        return [
            'candidate:'.$candidate->id.':'.$signal->market_regime,
            'family:'.$candidate->symbol.':'.$candidate->timeframe.':'.$candidate->strategy_family.':'.$signal->market_regime,
            'family:'.$candidate->symbol.':'.$candidate->timeframe.':'.$candidate->strategy_family.':all',
        ];
    }

    private function outcomes(string $scope, ModelMarketPerformance $candidate, PaperSignal $signal): Collection
    {
        $query = PaperSignalOutcome::query()->with('signal.marketPerformance')->whereHas('signal', function ($query) use ($scope, $candidate, $signal): void {
            if (str_starts_with($scope, 'candidate:')) {
                $query->where('model_market_performance_id', $candidate->id)->where('market_regime', $signal->market_regime);
                return;
            }
            $query->where('symbol', $candidate->symbol)->where('timeframe', $candidate->timeframe)
                ->whereHas('marketPerformance', fn ($performance) => $performance->where('strategy_family', $candidate->strategy_family));
            if (! str_ends_with($scope, ':all')) $query->where('market_regime', $signal->market_regime);
        });
        return $query->get();
    }

    private function store(string $scope, ModelMarketPerformance $candidate, PaperSignal $signal, Collection $rows): void
    {
        $bins = [];
        $brier = 0.0;
        foreach ($rows as $row) {
            $confidence = max(0, min(1, (float) ($row->signal?->confidence ?? 0) / 100));
            $bucket = $this->bucket($confidence);
            $bins[$bucket] ??= ['trades' => 0, 'wins' => 0, 'confidence_sum' => 0.0, 'profits' => []];
            $bins[$bucket]['trades']++;
            $bins[$bucket]['wins'] += $row->outcome === 'win' ? 1 : 0;
            $bins[$bucket]['confidence_sum'] += $confidence;
            $bins[$bucket]['profits'][] = (float) $row->profit_percent;
            $brier += ($confidence - ($row->outcome === 'win' ? 1 : 0)) ** 2;
        }
        foreach ($bins as &$bin) {
            $bin['mean_raw_confidence'] = round($bin['confidence_sum'] / $bin['trades'], 4);
            $bin['realized_win_rate'] = round($bin['wins'] / $bin['trades'], 4);
            $bin['posterior_win_probability'] = round(($bin['wins'] + 1) / ($bin['trades'] + 2), 4);
            $profits = (array) $bin['profits'];
            $wins = array_values(array_filter($profits, fn (float $value): bool => $value > 0));
            $losses = array_map('abs', array_values(array_filter($profits, fn (float $value): bool => $value <= 0)));
            $probability = (float) $bin['posterior_win_probability'];
            $lowerProbability = $this->wilsonLowerBound((int) $bin['wins'], (int) $bin['trades']);
            $averageWin = $wins === [] ? 0.0 : array_sum($wins) / count($wins);
            $averageLoss = $losses === [] ? 0.0 : array_sum($losses) / count($losses);
            $bin['edge'] = [
                'protocol' => 'train_only_out_of_fold_edge_v1',
                'expected_value_percent' => round($probability * $averageWin - (1 - $probability) * $averageLoss, 6),
                'lower_expected_value_percent' => round($lowerProbability * $averageWin - (1 - $lowerProbability) * $averageLoss, 6),
                'win_probability_lower_bound' => round($lowerProbability, 6),
                'average_win_percent' => round($averageWin, 6), 'average_loss_percent' => round($averageLoss, 6),
            ];
            unset($bin['confidence_sum']);
            unset($bin['profits']);
        }
        unset($bin);
        $reliability = collect($bins)->sum(fn ($bin) => abs($bin['mean_raw_confidence'] - $bin['realized_win_rate']) * $bin['trades']) / max(1, $rows->count());
        PaperConfidenceCalibration::updateOrCreate(['scope_key' => $scope], [
            'model_market_performance_id' => str_starts_with($scope, 'candidate:') ? $candidate->id : null,
            'symbol' => $candidate->symbol, 'timeframe' => $candidate->timeframe,
            'strategy_family' => $candidate->strategy_family, 'market_regime' => str_ends_with($scope, ':all') ? null : $signal->market_regime,
            'sample_count' => $rows->count(), 'brier_score' => round($brier / max(1, $rows->count()), 6),
            'reliability_error' => round($reliability, 6), 'bins' => $bins, 'calibrated_at' => now(),
        ]);
    }

    private function bucket(float $confidence): int { return min(4, (int) floor($confidence * 5)); }

    private function wilsonLowerBound(int $wins, int $trades): float
    {
        if ($trades < 1) return 0.0;
        $z = 1.96; $p = $wins / $trades; $denominator = 1 + ($z ** 2 / $trades);
        $centre = ($p + ($z ** 2 / (2 * $trades))) / $denominator;
        $margin = $z * sqrt(($p * (1 - $p) / $trades) + (($z ** 2) / (4 * ($trades ** 2)))) / $denominator;
        return max(0.0, $centre - $margin);
    }
}
