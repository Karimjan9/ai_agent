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
                'evidence_status' => data_get($edge, 'evidence_status', 'not_assessed'), 'metadata' => ['protocol' => data_get($result, 'decision_blame_graph.protocol'), 'intervention_type' => data_get($edge, 'intervention_type')],
            ]);
        }
        return ['protocol' => 'decision_blame_graph_v1', 'edge_count' => count($edges),
            'rule' => 'Only assessed edges may constrain the named mutation component; unassessed branches remain visible but non-causal.'];
    }
}
