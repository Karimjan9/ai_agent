<?php

namespace App\Services;

use App\Models\AiLaboratory;
use App\Models\ModelMarketPerformance;

/**
 * Turns completed challenger history into a bounded research input.
 *
 * Historical challengers are never silently promoted to genetic parents:
 * their forward passport can be incomplete.  They are nevertheless valuable
 * evidence about where the search has collapsed.  In particular, repeatedly
 * testing one strategy family is not independent exploration, even when its
 * parameter values differ.
 */
class HistoricalResearchAtlasService
{
    public const PROTOCOL = 'historical_research_atlas_v1';

    /** @return array<string, mixed> */
    public function summarize(AiLaboratory $lab): array
    {
        $families = ['hybrid', 'differential_router', 'trend', 'mean_reversion', 'volatility'];
        $rows = ModelMarketPerformance::query()
            ->where('symbol', strtoupper((string) $lab->symbol))
            ->where('timeframe', strtoupper((string) $lab->timeframe))
            ->where('evidence_status', 'valid')
            ->whereIn('status', ['challenger', 'forward_validated', 'paper', 'champion', 'rejected', 'stagnated'])
            ->latest('id')
            ->limit(500)
            ->get(['id', 'strategy_family', 'status', 'metrics']);

        $familyCounts = array_fill_keys($families, 0);
        $statusCounts = [];
        $failureCounts = [];
        $sourceIds = [];
        foreach ($rows as $row) {
            $family = app(StrategyParameterSchemaService::class)->family((string) $row->strategy_family);
            if (array_key_exists($family, $familyCounts)) $familyCounts[$family]++;
            $status = (string) $row->status;
            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
            $sourceIds[] = (int) $row->id;
            foreach ((array) data_get((array) $row->metrics, 'screening_survival.reason_codes', []) as $reason) {
                $reason = (string) $reason;
                if ($reason !== '') $failureCounts[$reason] = ($failureCounts[$reason] ?? 0) + 1;
            }
        }
        arsort($failureCounts);

        $underexplored = array_values(array_filter(
            ['trend', 'mean_reversion', 'volatility'],
            fn (string $family): bool => (int) ($familyCounts[$family] ?? 0) === 0,
        ));

        return [
            'protocol' => self::PROTOCOL,
            'scope' => ['symbol' => strtoupper((string) $lab->symbol), 'timeframe' => strtoupper((string) $lab->timeframe)],
            'source_performance_count' => $rows->count(),
            'source_performance_ids' => $sourceIds,
            'family_evidence_counts' => $familyCounts,
            'status_counts' => $statusCounts,
            'dominant_historical_failure_codes' => array_slice($failureCounts, 0, 5, true),
            'underexplored_executable_families' => $underexplored,
            'planner_action' => $underexplored === []
                ? 'all_core_families_have_history_keep_behavioral_diversity_budget'
                : 'reserve_exact_control_and_two_tactic_probes_for_each_underexplored_family',
            'parent_rule' => 'archive history guides hypothesis allocation only; parent passport remains independently required',
            'promotion_evidence' => false,
        ];
    }
}
