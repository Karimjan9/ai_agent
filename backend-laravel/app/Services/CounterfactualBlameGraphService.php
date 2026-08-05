<?php

namespace App\Services;

use App\Models\DecisionCounterfactualEdge;
use App\Models\LabAgent;
use App\Models\ModelMarketPerformance;

class CounterfactualBlameGraphService
{
    public function sync(ModelMarketPerformance $performance, ?LabAgent $agent, array $result): array
    {
        $edges = (array) data_get($result, 'decision_blame_graph.edges', []);
        // Older replays may contain the complete veto ledger but predate the
        // Python graph emitter. Persist a clearly labelled shadow-only edge
        // from that ledger so the diagnostic history is not lost. It can never
        // receive mutation credit or promotion authority.
        if ($edges === []) $edges = $this->deriveShadowEdges($result);
        foreach ($edges as $edge) {
            if (! is_array($edge) || ! filled(data_get($edge, 'edge_key'))) continue;
            DecisionCounterfactualEdge::updateOrCreate([
                'model_market_performance_id' => $performance->id, 'lab_agent_id' => $agent?->id, 'edge_key' => $edge['edge_key'],
            ], [
                'source_node' => data_get($edge, 'source_node', 'unknown'), 'target_node' => data_get($edge, 'target_node', 'outcome'),
                'regime' => data_get($edge, 'regime'), 'cost_scenario' => data_get($edge, 'cost_scenario'),
                'baseline_value' => data_get($edge, 'baseline'), 'intervention_value' => data_get($edge, 'intervention'),
                'delta_value' => data_get($edge, 'delta'), 'lower_confidence_bound' => data_get($edge, 'confidence_interval.0'),
                'upper_confidence_bound' => data_get($edge, 'confidence_interval.1'), 'sample_count' => (int) data_get($edge, 'sample_count', 0),
                'evidence_status' => data_get($edge, 'evidence_status', 'not_assessed'), 'metadata' => [
                    'protocol' => data_get($result, 'decision_blame_graph.protocol', 'derived_veto_regret_v1'),
                    'intervention_type' => data_get($edge, 'intervention_type'), 'derived' => (bool) data_get($edge, '_derived', false),
                ],
            ]);
        }
        return ['protocol' => 'decision_blame_graph_v1', 'edge_count' => count($edges),
            'rule' => 'Only assessed edges may constrain the named mutation component; unassessed branches remain visible but non-causal.'];
    }

    private function deriveShadowEdges(array $result): array
    {
        $edges = [];
        foreach ((array) data_get($result, 'veto_regret.by_regime_context', []) as $context => $metrics) {
            $sample = (int) data_get($metrics, 'shadow_trades', 0);
            if ($sample < 1) continue;
            $parts = explode('|', (string) $context);
            $baseline = (float) data_get($metrics, 'net_shadow_profit_percent', 0) / max(1, $sample);
            $edges[] = [
                '_derived' => true, 'edge_key' => 'veto|outcome|'.$context,
                'source_node' => 'veto', 'target_node' => 'outcome',
                'regime' => $parts[1] ?? 'unknown', 'cost_scenario' => 'same_next_open_costed_shadow',
                'baseline' => round($baseline, 6), 'intervention' => 0, 'delta' => round(-$baseline, 6),
                'confidence_interval' => [null, null], 'sample_count' => $sample,
                'evidence_status' => 'counterfactual_shadow_only', 'intervention_type' => 'no_trade_vs_shadow_allow',
            ];
        }
        return $edges;
    }
}
