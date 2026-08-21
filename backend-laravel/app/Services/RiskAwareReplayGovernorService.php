<?php

namespace App\Services;

class RiskAwareReplayGovernorService
{
    /** @return array<string,mixed> */
    public function allocate(array $arms, array $budget = []): array
    {
        $scores = collect($arms)->map(function (array $arm, string|int $key): array {
            $score = (float) ($arm['expected_edge_after_cost'] ?? 0) - .6 * (float) ($arm['tail_risk'] ?? 0) - .35 * (float) ($arm['uncertainty'] ?? 0) + .45 * (float) ($arm['information_gain'] ?? 0) - .5 * (float) ($arm['regression_risk'] ?? 0);

            return ['arm' => $arm['arm'] ?? $key, 'research_value' => round($score, 6)];
        })->sortByDesc('research_value')->values();
        $selected = $scores->first();

        return ['status' => $selected ? 'allocated_shadow_replay' : 'learning_starvation_probe', 'selected_arm' => $selected['arm'] ?? 'learning_starvation_probe', 'research_value' => $selected['research_value'] ?? 0, 'budgets' => ['exploit' => (float) ($budget['exploit'] ?? .4), 'repair' => (float) ($budget['repair'] ?? .4), 'discovery' => (float) ($budget['discovery'] ?? .2)], 'trade_risk_budget_used' => false, 'promotion_evidence' => false];
    }
}
