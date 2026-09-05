<?php

namespace App\Services;

/**
 * Evidence-first scorecard for the seven dependent parts of the Trading OS.
 *
 * It never fills missing evidence with zero and never promotes an agent. The
 * geometric system score exists only when every block is both observed and
 * powered; until then the missing block is a research task, not a failure.
 */
class TradingOperatingSystemScorecardService
{
    public const PROTOCOL = 'xauusd_trading_operating_system_scorecard_v1';

    private const BLOCKS = [
        'edge_context',
        'selection',
        'execution',
        'risk',
        'management',
        'process',
        'learning',
    ];

    /** @return array<string,mixed> */
    public function assess(array $result, array $edgeMetrics = []): array
    {
        $trades = (int) ($this->number($result, ['total_trades', 'trade_count']) ?? 0);
        $minimumTrades = 8;
        $blocks = [
            'edge_context' => $this->edgeContext($result, $edgeMetrics, $trades, $minimumTrades),
            'selection' => $this->selection($result, $trades, $minimumTrades),
            'execution' => $this->execution($result, $trades, $minimumTrades),
            'risk' => $this->risk($result, $edgeMetrics, $trades, $minimumTrades),
            'management' => $this->management($result, $trades, $minimumTrades),
            'process' => $this->process($result),
            'learning' => $this->learning($result),
        ];
        $observed = collect($blocks)->filter(fn (array $block): bool => is_numeric($block['score']));
        $powered = $observed->filter(fn (array $block): bool => $block['powered'] === true);
        $missing = collect($blocks)->filter(fn (array $block): bool => $block['score'] === null)->keys()->values()->all();
        $underpowered = $observed->filter(fn (array $block): bool => $block['powered'] !== true)->keys()->values()->all();
        $bottleneckName = $observed->sortBy('score')->keys()->first();
        $bottleneck = $bottleneckName ? $blocks[$bottleneckName] : null;
        $complete = $observed->count() === count(self::BLOCKS);
        $completeAndPowered = $complete && $powered->count() === count(self::BLOCKS);
        $systemScore = $completeAndPowered
            ? $this->geometricMean($observed->pluck('score')->map(fn ($value): float => (float) $value)->all())
            : null;
        $target = $this->target($bottleneckName, $bottleneck, $missing, $underpowered);

        return [
            'protocol' => self::PROTOCOL,
            'formula' => 'edge_context × selection × execution × risk × management × process × learning',
            'blocks' => $blocks,
            'evidence_coverage' => [
                'observed_blocks' => $observed->count(),
                'required_blocks' => count(self::BLOCKS),
                'coverage_ratio' => round($observed->count() / count(self::BLOCKS), 6),
                'missing_blocks' => $missing,
                'underpowered_blocks' => $underpowered,
            ],
            'status' => match (true) {
                ! $complete => 'incomplete_evidence',
                ! $completeAndPowered => 'underpowered',
                default => 'complete_powered',
            },
            'system_integrity_score' => $systemScore,
            'quality_grade' => $systemScore === null ? 'UNRATED' : $this->grade($systemScore),
            'weakest_observed_block' => $bottleneckName ? [
                'block' => $bottleneckName,
                'score' => $bottleneck['score'],
                'powered' => $bottleneck['powered'],
            ] : null,
            'error_taxonomy' => $this->errors($blocks),
            'evolution_directive' => $target,
            'invariants' => [
                'missing_evidence_is_not_zero' => true,
                'financial_result_is_not_process_result' => true,
                'quality_grade_requires_all_blocks_powered' => true,
                'one_target_axis_per_repair' => true,
                'promotion_evidence' => false,
            ],
            'promotion_evidence' => false,
        ];
    }

    /** @return array{primary_cause:string,contributions:array<string,float>,evidence_gaps:array<int,string>} */
    public function causalAttribution(array $scorecard, bool $activityStarved = false): array
    {
        $missing = (array) data_get($scorecard, 'evidence_coverage.missing_blocks', []);
        $underpowered = (array) data_get($scorecard, 'evidence_coverage.underpowered_blocks', []);
        if ($activityStarved) {
            return [
                'primary_cause' => 'execution_admission_starvation',
                'contributions' => ['selection' => .5, 'execution' => .5],
                'evidence_gaps' => array_values(array_unique([...$missing, ...array_map(fn (string $block): string => "underpowered:{$block}", $underpowered)])),
            ];
        }
        if ((string) ($scorecard['status'] ?? '') !== 'complete_powered') {
            return [
                'primary_cause' => 'evidence_completion',
                'contributions' => [],
                'evidence_gaps' => array_values(array_unique([...$missing, ...array_map(fn (string $block): string => "underpowered:{$block}", $underpowered)])),
            ];
        }

        $deficits = collect((array) ($scorecard['blocks'] ?? []))
            ->filter(fn (array $block): bool => is_numeric($block['score'] ?? null))
            ->map(fn (array $block): float => max(0.0, 1.0 - (float) $block['score']))
            ->filter(fn (float $deficit): bool => $deficit > 0);
        $sum = (float) $deficits->sum();
        $contributions = $sum > 0
            ? $deficits->map(fn (float $deficit): float => round($deficit / $sum, 6))->all()
            : [];

        return [
            'primary_cause' => (string) data_get($scorecard, 'weakest_observed_block.block', 'unresolved'),
            'contributions' => $contributions,
            'evidence_gaps' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function edgeContext(array $result, array $edge, int $trades, int $minimum): array
    {
        $edgeQuality = $this->unitOrNull($edge['edge_quality'] ?? null);
        if ($edgeQuality === null) {
            $edgeQuality = $this->unitOrNull($this->number($result, [
                'statistical_evidence.deflated_sharpe.deflated_sharpe_probability',
                'deflated_sharpe.deflated_sharpe_probability',
            ]));
        }
        $costAdjusted = $this->unitOrNull($edge['cost_adjusted_return'] ?? null);
        if ($costAdjusted === null) {
            $profitFactor = $this->number($result, [
                'pf_attribution.stress_cost.profit_factor',
                'screening_survival.stress_cost_pf',
                'pf_attribution.summary.net_pf',
                'profit_factor',
            ]);
            $costAdjusted = $profitFactor !== null ? $this->unit(($profitFactor - 1.0) / .30) : null;
        }
        $values = [
            'edge_quality' => $edgeQuality,
            'cost_adjusted_return' => $costAdjusted,
            'regime_coverage' => $this->unitOrNull($edge['regime_coverage'] ?? $this->number($result, ['fitness_breakdown.components.regime_coverage'])),
        ];

        return $this->block($values, $trades >= $minimum, ['trades' => $trades, 'minimum_trades' => $minimum]);
    }

    /** @return array<string,mixed> */
    private function selection(array $result, int $trades, int $minimum): array
    {
        $recall = $this->unitOrNull($this->number($result, ['opportunity_recall.opportunity_recall', 'opportunity_metrics.coverage']));
        $precision = $this->unitOrNull($this->number($result, ['opportunity_recall.abstention_precision']));
        $selectionF1 = $recall !== null && $precision !== null && ($recall + $precision) > 0
            ? round(2 * $recall * $precision / ($recall + $precision), 6)
            : null;
        $opportunities = (int) ($this->number($result, ['opportunity_recall.opportunities', 'opportunity_metrics.valid_signal_opportunities']) ?? 0);
        $accepted = $this->number($result, [
            'entry_funnel.accepted_entries',
            'entry_funnel.executed_trades',
            'opportunity_recall.accepted_entries',
            'opportunity_metrics.accepted_entries',
        ]);
        $scopeConsistent = $accepted === null || $opportunities === 0
            ? null
            : $accepted <= $opportunities;

        return $this->block([
            'opportunity_recall' => $recall,
            'abstention_precision' => $precision,
            'selection_harmonic_score' => $selectionF1,
        ], $opportunities >= $minimum && $scopeConsistent !== false, [
            'opportunities' => $opportunities,
            'accepted_entries' => $accepted,
            'aggregate_trade_count' => $trades,
            'funnel_scope_consistent' => $scopeConsistent,
            'minimum_opportunities' => $minimum,
        ], ['opportunity_recall', 'abstention_precision'], $selectionF1);
    }

    /** @return array<string,mixed> */
    private function execution(array $result, int $trades, int $minimum): array
    {
        $costPercent = $this->number($result, ['pf_attribution.summary.cost_to_gross_profit_percent']);
        $costEfficiency = $costPercent !== null ? $this->unit(1 - ($costPercent / 100)) : null;
        $fillQuality = $this->unitOrNull($this->number($result, [
            'execution_quality.score',
            'management_evidence.execution_quality',
        ]));

        return $this->block([
            'cost_efficiency' => $costEfficiency,
            'fill_quality' => $fillQuality,
        ], $trades >= $minimum, [
            'trades' => $trades,
            'minimum_trades' => $minimum,
            'cost_to_gross_profit_percent' => $costPercent,
        ]);
    }

    /** @return array<string,mixed> */
    private function risk(array $result, array $edge, int $trades, int $minimum): array
    {
        $drawdown = $this->number($result, ['max_drawdown_percent', 'max_drawdown']);
        $ruin = $this->number($result, ['monte_carlo.risk_of_ruin_percent', 'risk_of_ruin_percent']);
        $drawdownLimit = (float) config('services.risk.sentinel_max_drawdown_percent', 15);
        $ruinLimit = (float) config('services.risk.sentinel_max_risk_of_ruin_percent', 10);

        return $this->block([
            'drawdown_safety' => $this->unitOrNull($edge['drawdown_safety'] ?? ($drawdown !== null && $drawdownLimit > 0 ? 1 - ($drawdown / $drawdownLimit) : null)),
            'risk_of_ruin_safety' => $this->unitOrNull($edge['risk_of_ruin'] ?? ($ruin !== null && $ruinLimit > 0 ? 1 - ($ruin / $ruinLimit) : null)),
        ], $trades >= $minimum, [
            'trades' => $trades,
            'minimum_trades' => $minimum,
            'max_drawdown_percent' => $drawdown,
            'risk_of_ruin_percent' => $ruin,
        ]);
    }

    /** @return array<string,mixed> */
    private function management(array $result, int $trades, int $minimum): array
    {
        $reportedPower = data_get($result, 'management_evidence.powered');
        $powered = is_bool($reportedPower) ? $reportedPower : $trades >= $minimum;
        $values = [
            'target_capture_ratio' => $this->unitOrNull($this->number($result, [
                'management_evidence.target_capture_ratio',
                'execution_tactic.target_capture_ratio',
                'management_audit.mfe_capture_ratio',
            ])),
            'stop_efficiency' => $this->unitOrNull($this->number($result, [
                'management_evidence.stop_efficiency',
                'execution_tactic.stop_efficiency',
            ])),
        ];
        $premature = $this->number($result, [
            'management_evidence.premature_stop_rate',
            'execution_tactic.premature_stop_rate',
        ]);
        $values['premature_stop_avoidance'] = $premature !== null ? $this->unit(1 - $premature) : null;

        return $this->block($values, $powered, [
            'trades' => $trades,
            'minimum_trades' => $minimum,
            'observed_management_paths' => $this->number($result, ['management_evidence.observed_trades']),
            'measured_winner_paths' => $this->number($result, ['management_evidence.measured_winner_paths']),
            'path_precision' => data_get($result, 'management_evidence.path_precision'),
            'required_evidence' => 'same-entry management counterfactual or attested MFE/MAE path metrics',
        ]);
    }

    /** @return array<string,mixed> */
    private function process(array $result): array
    {
        $values = [];
        $dataStatus = data_get($result, 'data_quality.status');
        if (is_string($dataStatus) && $dataStatus !== '') {
            $values['data_contract'] = in_array($dataStatus, ['passed', 'healthy'], true) ? 1.0 : 0.0;
        }
        $executionStatus = data_get($result, 'execution_contract.status');
        if (is_string($executionStatus) && $executionStatus !== '') {
            $values['execution_contract'] = $executionStatus === 'matched' ? 1.0 : 0.0;
        }
        $proofStatus = data_get($result, 'proof_carrying_replay.status');
        if (is_string($proofStatus) && $proofStatus !== '') {
            $values['proof_carrying_replay'] = $proofStatus === 'passed' ? 1.0 : 0.0;
        }
        if (array_key_exists('temporal_firewall_passed', $result)) {
            $values['temporal_firewall'] = $result['temporal_firewall_passed'] === true ? 1.0 : 0.0;
        }
        if ((bool) data_get($result, 'data_drift', false) || (bool) data_get($result, 'execution_drift', false)) {
            $values['runtime_identity'] = 0.0;
        }

        return $this->block($values, $values !== [], [
            'technical_contract_observations' => count($values),
        ]);
    }

    /** @return array<string,mixed> */
    private function learning(array $result): array
    {
        $poweredWindows = (int) ($this->number($result, [
            'walk_forward.forward_window_protocol.powered_windows',
            'forward_window_protocol.powered_windows',
        ]) ?? 0);
        $positiveWindows = (int) ($this->number($result, [
            'walk_forward.forward_window_protocol.positive_windows',
            'forward_window_protocol.positive_windows',
        ]) ?? 0);
        $stability = $poweredWindows > 0 ? $this->unit($positiveWindows / $poweredWindows) : null;

        return $this->block([
            'independent_window_stability' => $stability,
        ], $poweredWindows >= 3, [
            'powered_windows' => $poweredWindows,
            'positive_windows' => $positiveWindows,
            'required_powered_windows' => 3,
        ]);
    }

    /** @return array<string,mixed> */
    private function block(array $measurements, bool $powered, array $evidence, array $scoreKeys = [], ?float $score = null): array
    {
        $observed = collect($measurements)->filter(fn ($value): bool => is_numeric($value));
        if ($score === null) {
            $scored = $scoreKeys === [] ? $observed : $observed->only($scoreKeys);
            $score = $scored->isEmpty() ? null : round((float) $scored->average(), 6);
        }

        return [
            'score' => $score,
            'status' => $score === null ? 'missing_evidence' : ($powered ? 'powered' : 'underpowered'),
            'powered' => $score !== null && $powered,
            'measurements' => $measurements,
            'evidence' => $evidence,
        ];
    }

    /** @return array<string,mixed> */
    private function target(?string $name, ?array $block, array $missing, array $underpowered): array
    {
        if ($missing !== []) {
            return [
                'status' => 'collect_missing_evidence',
                'target' => 'evidence_completion',
                'missing_blocks' => $missing,
                'underpowered_blocks' => $underpowered,
                'one_axis_repair_authorized' => false,
                'promotion_evidence' => false,
            ];
        }
        if ($underpowered !== []) {
            return [
                'status' => 'increase_independent_power',
                'target' => 'evidence_completion',
                'underpowered_blocks' => $underpowered,
                'one_axis_repair_authorized' => false,
                'promotion_evidence' => false,
            ];
        }

        $target = match ($name) {
            'edge_context' => 'edge_quality',
            'selection' => 'selection_quality',
            'execution' => 'execution_quality',
            'risk' => 'drawdown_risk',
            'management' => 'management_quality',
            'process' => 'technical_repair',
            'learning' => 'evidence_completion',
            default => 'evidence_completion',
        };

        return [
            'status' => 'measured_bottleneck',
            'target' => $target,
            'source_block' => $name,
            'source_score' => $block['score'] ?? null,
            'one_axis_repair_authorized' => ! in_array($target, ['technical_repair', 'evidence_completion'], true),
            'promotion_evidence' => false,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function errors(array $blocks): array
    {
        $errors = [];
        foreach ($blocks as $name => $block) {
            if (! is_numeric($block['score']) || (float) $block['score'] >= .5) {
                continue;
            }
            $type = match ($name) {
                'edge_context' => (($block['measurements']['regime_coverage'] ?? 1) < .5) ? 'analysis_error' : 'strategy_error',
                'selection' => 'selection_error',
                'execution' => 'execution_error',
                'risk' => 'risk_error',
                'management' => 'management_error',
                'process' => 'process_integrity_error',
                'learning' => 'adaptation_error',
                default => 'unresolved_error',
            };
            $errors[] = ['type' => $type, 'source_block' => $name, 'score' => $block['score']];
        }

        return $errors;
    }

    private function grade(float $score): string
    {
        return match (true) {
            $score >= .9 => 'A+',
            $score >= .8 => 'A',
            $score >= .7 => 'B',
            $score >= .6 => 'C',
            default => 'REJECT',
        };
    }

    /** @param array<int,float> $values */
    private function geometricMean(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        $product = 1.0;
        foreach ($values as $value) {
            $product *= max(.000001, min(1.0, $value));
        }

        return round($product ** (1 / count($values)), 6);
    }

    private function unitOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? $this->unit((float) $value) : null;
    }

    private function unit(float $value): float
    {
        return round(max(0.0, min(1.0, $value)), 6);
    }

    /** @param list<string> $paths */
    private function number(array $values, array $paths): ?float
    {
        foreach ($paths as $path) {
            $value = data_get($values, $path);
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }
}
