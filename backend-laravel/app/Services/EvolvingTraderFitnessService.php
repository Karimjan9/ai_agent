<?php

namespace App\Services;

/**
 * Evidence-first professional trader capability passport.
 *
 * The autonomous agent equivalent of psychology is decision discipline:
 * frozen contracts, veto adherence and absence of unauthorized overrides.
 * Missing observations stay null and become research tasks. They never lower
 * or inflate the measured score by assumption.
 */
class EvolvingTraderFitnessService
{
    public const PROTOCOL = 'maximum_sustainable_trader_evolution_fitness_v1';

    private const DIMENSIONS = [
        'market_understanding', 'regime_recognition', 'context_bias',
        'edge_quality', 'setup_selection', 'confirmation_quality',
        'entry_precision', 'execution_quality', 'risk_management',
        'position_sizing', 'trade_management', 'exit_quality',
        'decision_discipline', 'statistical_research', 'adaptation_ability',
    ];

    /** @return array<string,mixed> */
    public function assess(array $result, array $edge, array $operatingSystem): array
    {
        $os = (array) ($operatingSystem['blocks'] ?? []);
        $poweredWindows = (int) ($this->number($result, [
            'walk_forward.forward_window_protocol.powered_windows',
            'forward_window_protocol.powered_windows',
        ]) ?? 0);
        $positiveWindows = (int) ($this->number($result, [
            'walk_forward.forward_window_protocol.positive_windows',
            'forward_window_protocol.positive_windows',
        ]) ?? 0);
        $trades = (int) ($this->number($result, ['total_trades', 'trade_count']) ?? 0);
        $tradePowered = $trades >= 8;
        $confirmationCapability = (array) ($edge['confirmation_entry_capability_evidence'] ?? []);

        $regime = $this->unitOrNull($edge['regime_coverage'] ?? $this->number($result, [
            'fitness_breakdown.components.regime_coverage',
            'router_evidence.regime_coverage',
        ]));
        $context = $this->unitOrNull($this->number($result, [
            'router_evidence.context_alignment_score',
            'context_evidence.context_alignment_score',
            'market_adaptive_replay.context_quality.score',
        ]));
        $setup = $this->percentageOrUnit($this->number($result, [
            'smart_discipline.average_setup_quality_score',
            'discipline.average_setup_quality_score',
            'setup_quality_score',
        ]));
        $setupFromCapability = $setup === null
            && is_numeric(data_get($confirmationCapability, 'process_scores.setup_selection'));
        $setup ??= $this->unitOrNull(data_get($confirmationCapability, 'process_scores.setup_selection'));
        $confirmation = $this->unitOrNull($this->number($result, [
            'confirmation_entry_evidence.confirmation_quality',
            'confirmation_entry_evidence.confirmation_precision',
            'confirmation_entry.confirmation_quality',
        ]));
        $confirmationFromCapability = $confirmation === null
            && is_numeric(data_get($confirmationCapability, 'process_scores.confirmation_quality'));
        $confirmation ??= $this->unitOrNull(data_get($confirmationCapability, 'process_scores.confirmation_quality'));
        $entry = $this->unitOrNull($this->number($result, [
            'entry_quality.score',
            'execution_tactic.entry_efficiency',
            'confirmation_entry_evidence.entry_precision',
        ]));
        $entryFromCapability = $entry === null
            && is_numeric(data_get($confirmationCapability, 'process_scores.entry_precision'));
        $entry ??= $this->unitOrNull(data_get($confirmationCapability, 'process_scores.entry_precision'));
        $positionSizing = $this->unitOrNull($this->number($result, [
            'position_sizing_evidence.score',
            'risk_authorization.position_sizing_compliance',
            'execution_tactic.position_sizing_efficiency',
        ]));
        $discipline = $this->percentageOrUnit($this->number($result, [
            'smart_discipline.average_process_adherence_score',
            'discipline.average_process_adherence_score',
            'process_adherence_score',
        ]));
        $capture = $this->unitOrNull($this->number($result, [
            'management_evidence.target_capture_ratio',
            'management_audit.mfe_capture_ratio',
            'execution_tactic.target_capture_ratio',
        ]));
        $premature = $this->unitOrNull($this->number($result, [
            'management_evidence.premature_stop_rate',
            'execution_tactic.premature_stop_rate',
        ]));
        $exit = $this->average(array_filter([
            $capture,
            $premature === null ? null : $this->unit(1 - $premature),
        ], fn ($value): bool => $value !== null));
        $adaptation = $this->unitOrNull($this->number($result, [
            'market_adaptive_replay.adaptation.score',
            'market_adaptive_replay.adaptation.adaptation_score',
        ]));
        $drift = $this->number($result, ['market_adaptive_replay.adaptation.drift_score']);
        if ($adaptation === null && $drift !== null) {
            $adaptation = $this->unit(1 - $drift);
        }
        if ($adaptation === null && $poweredWindows > 0) {
            $adaptation = $this->unit($positiveWindows / $poweredWindows);
        }

        $dimensions = [
            'market_understanding' => $this->axis($this->average(array_filter([$regime, $context], fn ($value): bool => $value !== null)), $tradePowered, ['regime_coverage', 'context_alignment']),
            'regime_recognition' => $this->axis($regime, $tradePowered, ['regime_coverage']),
            'context_bias' => $this->axis($context, $tradePowered, ['context_alignment']),
            'edge_quality' => $this->axis($this->unitOrNull($edge['edge_quality'] ?? null), (bool) data_get($os, 'edge_context.powered', false), ['deflated_sharpe_or_bootstrap_pf']),
            'setup_selection' => $this->axis(
                $setup ?? $this->score($os, 'selection'),
                $setupFromCapability
                    ? (bool) data_get($confirmationCapability, 'dimension_power.setup_selection', false)
                    : $tradePowered,
                ['setup_quality', 'selection_harmonic_score'],
            ),
            'confirmation_quality' => $this->axis(
                $confirmation,
                $confirmation !== null && ($confirmationFromCapability
                    ? (bool) data_get($confirmationCapability, 'dimension_power.confirmation_quality', false)
                    : $tradePowered),
                ['confirmation_precision', 'independent_confirmation_ratio'],
            ),
            'entry_precision' => $this->axis(
                $entry,
                $entry !== null && ($entryFromCapability
                    ? (bool) data_get($confirmationCapability, 'dimension_power.entry_precision', false)
                    : $tradePowered),
                ['entry_efficiency', 'post_entry_mae', 'chase_geometry'],
            ),
            'execution_quality' => $this->axis($this->score($os, 'execution'), (bool) data_get($os, 'execution.powered', false), ['cost_efficiency', 'fill_quality']),
            'risk_management' => $this->axis($this->score($os, 'risk'), (bool) data_get($os, 'risk.powered', false), ['drawdown_safety', 'risk_of_ruin_safety']),
            'position_sizing' => $this->axis($positionSizing, $tradePowered, ['risk_budget_over_executable_stop', 'sizing_compliance']),
            'trade_management' => $this->axis($this->score($os, 'management'), (bool) data_get($os, 'management.powered', false), ['same_entry_management_evidence']),
            'exit_quality' => $this->axis($exit, $tradePowered, ['mfe_capture_ratio', 'premature_stop_avoidance']),
            // Technical contract integrity is necessary but is not the same
            // thing as strategy-rule adherence. Without an observed
            // discipline score, GOOD/BAD process classification stays
            // unresolved instead of borrowing the OS process block.
            'decision_discipline' => $this->axis($discipline, $discipline !== null && $tradePowered, ['rule_adherence', 'unauthorized_override_count']),
            'statistical_research' => $this->axis($poweredWindows > 0 ? $this->unit($poweredWindows / 9) : null, $poweredWindows >= 6, ['powered_disjoint_windows', 'purge_embargo']),
            'adaptation_ability' => $this->axis($adaptation, $poweredWindows >= 6, ['temporal_stability', 'drift_detection']),
        ];

        $observed = collect($dimensions)->filter(fn (array $axis): bool => is_numeric($axis['score']));
        $powered = $observed->filter(fn (array $axis): bool => $axis['powered']);
        $missing = collect($dimensions)->filter(fn (array $axis): bool => $axis['score'] === null)->keys()->values()->all();
        $underpowered = $observed->filter(fn (array $axis): bool => ! $axis['powered'])->keys()->values()->all();
        $weakest = $observed->sortBy('score')->keys()->first();
        $complete = $observed->count() === count(self::DIMENSIONS);
        $completePowered = $complete && $powered->count() === count(self::DIMENSIONS);
        $fitness = $completePowered ? $this->geometricMean($observed->pluck('score')->map(fn ($score): float => (float) $score)->all()) : null;

        return [
            'protocol' => self::PROTOCOL,
            'objective' => 'maximum_sustainable_trader_evolution',
            'formula' => implode(' × ', self::DIMENSIONS),
            'dimensions' => $dimensions,
            'status' => ! $complete ? 'incomplete_evidence' : (! $completePowered ? 'underpowered' : 'complete_powered'),
            'bottleneck_penalized_score' => $fitness,
            'weakest_observed_dimension' => $weakest ? [
                'dimension' => $weakest,
                'score' => $dimensions[$weakest]['score'],
                'powered' => $dimensions[$weakest]['powered'],
            ] : null,
            'evidence_coverage' => [
                'observed_dimensions' => $observed->count(),
                'required_dimensions' => count(self::DIMENSIONS),
                'coverage_ratio' => round($observed->count() / count(self::DIMENSIONS), 6),
                'missing_dimensions' => $missing,
                'underpowered_dimensions' => $underpowered,
            ],
            'next_deliberate_practice' => $missing !== [] ? [
                'action' => 'collect_missing_capability_evidence',
                'targets' => $missing,
                'rule_change_authorized' => false,
            ] : [
                'action' => 'one_axis_paired_bottleneck_repair',
                'target' => $weakest,
                'rule_change_authorized' => $completePowered,
            ],
            'learning_cadence_contract' => [
                'fast_loop' => ['trade_observation', 'process_score', 'mistake_tag', 'rule_change_authority' => false],
                'slow_loop' => ['repeated_pattern', 'hypothesis', 'paired_test', 'oos', 'paper', 'settlement', 'inheritance'],
                'lesson_is_not_rule_change' => true,
            ],
            'mistake_taxonomy' => [
                'M01_regime', 'M02_bias_context', 'M03_setup', 'M04_filter', 'M05_confirmation',
                'M06_entry', 'M07_stop_invalidation', 'M08_sizing', 'M09_execution',
                'M10_management', 'M11_exit', 'M12_discipline', 'M13_decision_state',
                'M14_event_news', 'M15_operational',
            ],
            'risk_asymmetry' => [
                'risk_decrease' => 'immediate_on_sentinel_or_drift',
                'risk_increase' => 'slow_only_after_positive_expectancy_oos_stability_process_adherence',
                'may_raise_live_risk' => false,
            ],
            'invariants' => [
                'missing_evidence_is_not_zero' => true,
                'financial_score_is_separate_from_process_score' => true,
                'bad_win_is_not_learning_eligible' => true,
                'one_structural_axis_per_experiment' => true,
                'promotion_evidence' => false,
            ],
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function axis(?float $score, bool $powered, array $sources): array
    {
        return [
            'score' => $score,
            'status' => $score === null ? 'missing_evidence' : ($powered ? 'powered' : 'underpowered'),
            'powered' => $score !== null && $powered,
            'evidence_sources' => $sources,
        ];
    }

    private function score(array $blocks, string $key): ?float
    {
        return $this->unitOrNull(data_get($blocks, $key.'.score'));
    }

    /** @param array<int,float> $values */
    private function average(array $values): ?float
    {
        return $values === [] ? null : round(array_sum($values) / count($values), 6);
    }

    /** @param array<int,float> $values */
    private function geometricMean(array $values): float
    {
        $product = 1.0;
        foreach ($values as $value) $product *= max(.000001, min(1.0, $value));

        return round($product ** (1 / max(1, count($values))), 6);
    }

    private function percentageOrUnit(?float $value): ?float
    {
        return $value === null ? null : $this->unit($value > 1 ? $value / 100 : $value);
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
            if (is_numeric($value)) return (float) $value;
        }

        return null;
    }
}
