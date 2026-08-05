<?php

namespace App\Services;

use App\Models\MutationMemory;

/** Ranks bounded experiments by posterior benefit, deficit relevance and information gain. */
class BayesianMutationLaboratoryService
{
    public function recommend(string $symbol, string $timeframe, string $family, array $keys, array $deficits = [], ?string $scope = null): ?array
    {
        $rows = MutationMemory::query()->where(compact('symbol', 'timeframe'))->where('strategy_family', $family)
            ->whereIn('parameter_key', $keys)->get()
            // Historical aggregate-credit rows are retained for audit but
            // cannot become a Bayesian prior for a new mutation. Filtering in
            // PHP also keeps the policy deterministic across MySQL/SQLite
            // JSON implementations used by the lab and its test harness.
            ->filter(fn (MutationMemory $row): bool => data_get($row->behavioral_effect, 'causal_credit.status') === 'independently_confirmed'
                && in_array($row->outcome, ['beneficial', 'harmful'], true)
                && (float) $row->confidence >= 60
                && filled($row->execution_contract_hash)
                && (int) $row->independent_confirmation_count >= 2
                && $row->non_target_regression_status === 'passed'
                && (int) data_get($row->behavioral_effect, 'causal_credit.positive_temporal_windows', 0) >= 2
                && (bool) data_get($row->behavioral_effect, 'causal_credit.stress_cost_degraded', true) === false)
            ->groupBy('parameter_key');
        $pressure = 1 + ((float) ($deficits['trade_deficit'] ?? 0) / 30)
            + ((float) ($deficits['pf_deficit'] ?? 0) / 1.3)
            + ((float) ($deficits['rolling_deficit'] ?? 0) / 3);
        return collect($keys)->map(function (string $key) use ($rows, $pressure, $scope): array {
            $history = $rows->get($key, collect());
            $local = $scope ? $history->where('market_regime', $scope) : collect();
            // Regime-local evidence receives double weight without counting
            // the same row twice. This avoids a tiny local sample overpowering
            // the global prior while still adapting to a measured niche.
            $success = 0.0; $failure = 0.0;
            foreach ($history as $row) {
                $weight = $scope !== null && (string) $row->market_regime === $scope ? 2.0 : 1.0;
                if ($row->outcome === 'beneficial') $success += $weight;
                if ($row->outcome === 'harmful') $failure += $weight;
            }
            $trials = $success + $failure;
            // Beta(1,1) posterior; neutral evidence does not fake a success.
            $posterior = (1 + $success) / (2 + $trials);
            $effect = max(0.05, (float) $history->where('outcome', 'beneficial')->avg(fn ($row) => max(0, (float) $row->forward_delta)));
            $standardError = sqrt(max(0.000001, $posterior * (1 - $posterior) / max(3, $trials + 3)));
            $lowerBound = max(0, $posterior - (1.645 * $standardError));
            $informationGain = 1 / sqrt(1 + $trials);
            return ['parameter_key' => $key, 'posterior' => round($posterior, 4),
                'posterior_lower_bound' => round($lowerBound, 4), 'expected_improvement' => round($effect, 4),
                'information_gain' => round($informationGain, 4),
                'score' => round($pressure * (($posterior * $effect) + (.15 * $informationGain)), 5),
                'trials' => $trials, 'regime_scope' => $scope, 'local_trials' => $local->count(),
                'evidence_status' => $trials >= 3 && $lowerBound >= .5 ? 'confirmed_prior' : 'exploration_only'];
        })->sortByDesc('score')->first();
    }
}
