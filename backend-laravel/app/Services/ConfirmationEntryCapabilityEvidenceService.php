<?php

namespace App\Services;

/**
 * Converts the executable Confirmation & Entry funnel into capability
 * evidence without confusing selectivity with profitability.
 *
 * Grade, confirmation independence and chase geometry are process scores.
 * They remain underpowered for rule changes until a paired/OOS ablation
 * proves their marginal outcome value.
 */
class ConfirmationEntryCapabilityEvidenceService
{
    public const PROTOCOL = 'confirmation_entry_capability_evidence_v1';

    /** @return array<string,mixed> */
    public function project(array $result): array
    {
        $funnel = (array) data_get($result, 'entry_contract_funnel', []);
        if ((string) data_get($funnel, 'protocol') !== 'entry_contract_funnel_v2'
            || (string) data_get($funnel, 'count_semantics') !== 'ordered_cumulative_pipeline'
            || (string) data_get($funnel, 'status') !== 'observed') {
            return [
                'protocol' => self::PROTOCOL,
                'status' => 'unavailable',
                'reason' => 'strategy_does_not_supply_observed_confirmation_entry_contract',
                'dimension_power' => [
                    'setup_selection' => false,
                    'confirmation_quality' => false,
                    'entry_precision' => false,
                ],
                'promotion_evidence' => false,
            ];
        }

        $counts = (array) data_get($funnel, 'stage_counts', []);
        $setupCount = max(0, (int) ($counts['setup'] ?? 0));
        $confirmationCount = max(0, (int) ($counts['confirmation'] ?? 0));
        $triggerCount = max(0, (int) ($counts['trigger'] ?? 0));
        $entryCount = max(0, (int) ($counts['entry_ready'] ?? 0));
        $grades = (array) data_get($funnel, 'grade_distribution', []);
        $graded = max(0, array_sum(array_map('intval', $grades)));
        $setupScore = $graded > 0 ? $this->unit((
            ((int) ($grades['A+'] ?? 0) * 1.0)
            + ((int) ($grades['A'] ?? 0) * .85)
            + ((int) ($grades['B'] ?? 0) * .60)
            + ((int) ($grades['SKIP'] ?? 0) * .10)
        ) / $graded) : null;

        $independent = $this->number($funnel, 'confirmation_cost.average_independent_count');
        $raw = $this->number($funnel, 'confirmation_cost.average_raw_count');
        $confirmationScore = $independent !== null && $raw !== null && $raw > 0
            ? $this->unit($independent / $raw) : null;
        $chase = $this->number($funnel, 'confirmation_cost.average_chase_distance_atr_after_trigger');
        $maximumChase = max(.000001, (float) data_get($result, 'parameters.max_chase_atr', 1.25));
        $entryScore = $chase !== null ? $this->unit(1 - ($chase / $maximumChase)) : null;

        $ablation = (array) data_get($result, 'confirmation_entry_ablation', []);
        $outcomePowered = (string) data_get($ablation, 'status') === 'complete_powered'
            && (bool) data_get($ablation, 'paired_control', false)
            && (int) data_get($ablation, 'powered_windows', 0) >= 6;

        return [
            'protocol' => self::PROTOCOL,
            'status' => $outcomePowered ? 'outcome_powered' : 'process_observed_outcome_unpowered',
            'stage_counts' => [
                'setup' => $setupCount,
                'confirmation' => $confirmationCount,
                'trigger' => $triggerCount,
                'entry_ready' => $entryCount,
            ],
            'process_scores' => [
                'setup_selection' => $setupScore,
                'confirmation_quality' => $confirmationScore,
                'entry_precision' => $entryScore,
            ],
            'process_power' => [
                'setup_selection' => $setupCount >= 8 && $setupScore !== null,
                'confirmation_quality' => $confirmationCount >= 8 && $confirmationScore !== null,
                'entry_precision' => $triggerCount >= 8 && $entryScore !== null,
            ],
            'dimension_power' => [
                'setup_selection' => $outcomePowered && $setupCount >= 8 && $setupScore !== null,
                'confirmation_quality' => $outcomePowered && $confirmationCount >= 8 && $confirmationScore !== null,
                'entry_precision' => $outcomePowered && $triggerCount >= 8 && $entryScore !== null,
            ],
            'causal_outcome_power' => [
                'powered' => $outcomePowered,
                'required' => 'paired confirmation/entry ablation over at least six powered OOS windows',
                'conversion_rate_is_not_quality' => true,
            ],
            'research_tasks' => $outcomePowered ? [] : [
                'confirmation_present_vs_confirmation_blinded',
                'entry_timing_variant_with_same_setup_and_risk',
                'measure_false_entry_reduction_and_missed_opportunity_cost',
            ],
            'promotion_evidence' => false,
        ];
    }

    private function number(array $values, string $path): ?float
    {
        $value = data_get($values, $path);

        return is_numeric($value) ? (float) $value : null;
    }

    private function unit(float $value): float
    {
        return round(max(0.0, min(1.0, $value)), 6);
    }
}
