<?php

namespace App\Services;

use App\Models\MutationMemory;

/** Ranks bounded experiments by posterior benefit, deficit relevance and information gain. */
class BayesianMutationLaboratoryService
{
    public function recommend(string $symbol, string $timeframe, string $family, array $keys, array $deficits = [], ?string $scope = null): ?array
    {
        $rows = MutationMemory::query()->where(compact('symbol', 'timeframe'))->where('strategy_family', $family)
            // Historical aggregate-credit rows are retained for audit but
            // cannot become a Bayesian prior for a new mutation.
            ->whereJsonContains('behavioral_effect->causal_credit->status', 'independently_confirmed')
            ->whereIn('parameter_key', $keys)->get()->groupBy('parameter_key');
        $pressure = 1 + ((float) ($deficits['trade_deficit'] ?? 0) / 30)
            + ((float) ($deficits['pf_deficit'] ?? 0) / 1.3)
            + ((float) ($deficits['rolling_deficit'] ?? 0) / 3);
        return collect($keys)->map(function (string $key) use ($rows, $pressure, $scope): array {
            $history = $rows->get($key, collect());
            $local = $scope ? $history->where('market_regime', $scope) : collect();
            // Regime-local evidence receives double weight, but global evidence
            // remains a weak prior when the current regime is sparsely sampled.
            $success = $history->where('outcome', 'beneficial')->count() + $local->where('outcome', 'beneficial')->count();
            $failure = $history->where('outcome', 'harmful')->count() + $local->where('outcome', 'harmful')->count();
            $trials = $success + $failure;
            // Beta(1,1) posterior; neutral evidence does not fake a success.
            $posterior = (1 + $success) / (2 + $trials);
            $effect = max(0.05, (float) $history->avg(fn ($row) => max(0, (float) $row->forward_delta)));
            $informationGain = 1 / sqrt(1 + $trials);
            return ['parameter_key' => $key, 'posterior' => round($posterior, 4),
                'expected_improvement' => round($effect, 4), 'information_gain' => round($informationGain, 4),
                'score' => round($pressure * (($posterior * $effect) + (.15 * $informationGain)), 5), 'trials' => $trials,
                'regime_scope' => $scope, 'local_trials' => $local->count()];
        })->sortByDesc('score')->first();
    }
}
