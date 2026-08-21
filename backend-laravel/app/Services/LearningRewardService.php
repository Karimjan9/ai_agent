<?php

namespace App\Services;

/** Multi-objective reward with safety vetoes that always dominate selection. */
class LearningRewardService
{
    public const WEIGHTS = [
        'edge_quality' => .25, 'cost_adjusted_return' => .20, 'drawdown_safety' => .15,
        'risk_of_ruin' => .15, 'temporal_stability' => .10, 'regime_coverage' => .05,
        'calibration' => .05, 'abstention_quality' => .05,
    ];

    /** @return array{selection_reward:float,components:array<string,float>,hard_failure:bool,vetoes:list<string>,evidence_state:string,insufficient_reasons:list<string>,promotion_evidence:bool} */
    public function score(array $outcome): array
    {
        $metrics = (array) ($outcome['metrics'] ?? $outcome);
        $components = [];
        $missing = [];
        foreach (self::WEIGHTS as $key => $weight) {
            $value = $metrics[$key] ?? null;
            if (! is_numeric($value)) $missing[] = $key;
            $components[$key] = is_numeric($value) ? max(0.0, min(1.0, (float) $value)) : null;
        }
        $vetoes = [];
        $drawdown = $this->number($metrics, ['drawdown_percent', 'max_drawdown_percent', 'drawdown']);
        $ruin = $this->number($metrics, ['risk_of_ruin_percent', 'risk_of_ruin']);
        $stressPf = $this->number($metrics, ['stress_profit_factor', 'stress_pf']);
        if ($drawdown !== null && $drawdown > 15) $vetoes[] = 'DRAWDOWN_LIMIT';
        if ($ruin !== null && $ruin > 10) $vetoes[] = 'RISK_OF_RUIN_LIMIT';
        if ($stressPf !== null && $stressPf < 1.05) $vetoes[] = 'STRESS_PF_LIMIT';
        if (($metrics['temporal_firewall_passed'] ?? true) !== true) $vetoes[] = 'TEMPORAL_FIREWALL';
        if (($metrics['data_drift'] ?? false) === true || ($metrics['execution_drift'] ?? false) === true) $vetoes[] = 'TECHNICAL_QUARANTINE';
        $trades = $this->number($metrics, ['total_trades', 'trade_count', 'executed_trades']);
        $insufficientReasons = [];
        if ($trades !== null && $trades <= 0) $insufficientReasons[] = 'INSUFFICIENT_ACTIVITY';
        if (count($missing) > 0) $insufficientReasons[] = 'COVERAGE_FAILURE:'.implode(',', $missing);
        if ((bool) ($metrics['opportunity_recall_failure'] ?? false)) $insufficientReasons[] = 'OPPORTUNITY_RECALL_FAILURE';
        $availableWeight = array_sum(array_map(fn (string $key): float => $components[$key] === null ? 0.0 : self::WEIGHTS[$key], array_keys(self::WEIGHTS)));
        $reward = 0.0;
        foreach (self::WEIGHTS as $key => $weight) if ($components[$key] !== null) $reward += $components[$key] * $weight;
        if ($availableWeight > 0) $reward /= $availableWeight;
        $hardFailure = $vetoes !== [];
        return [
            'selection_reward' => round($hardFailure ? min(-1.0, $reward - 1.0) : $reward, 6),
            'components' => $components,
            'hard_failure' => $hardFailure,
            'vetoes' => $vetoes,
            'evidence_state' => $insufficientReasons !== [] ? 'insufficient_evidence' : ($hardFailure ? 'negative' : 'positive'),
            'insufficient_reasons' => $insufficientReasons,
            // Only external immutable gates may set promotion evidence true.
            'promotion_evidence' => false,
        ];
    }

    private function number(array $values, array $keys): ?float
    {
        foreach ($keys as $key) if (isset($values[$key]) && is_numeric($values[$key])) return (float) $values[$key];
        return null;
    }
}
