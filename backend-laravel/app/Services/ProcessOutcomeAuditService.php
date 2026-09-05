<?php

namespace App\Services;

/**
 * Keeps financial outcome separate from decision-process quality.
 *
 * A profitable replay with a broken process is a BAD WIN and must never
 * become breeder/promotion evidence. A losing replay with a powered, intact
 * process is a GOOD LOSS: preserve the process contract and investigate the
 * edge/context through the slow causal loop.
 */
class ProcessOutcomeAuditService
{
    public const PROTOCOL = 'process_outcome_quadrant_v1';

    /** @return array<string,mixed> */
    public function project(array $result, array $scorecard, array $fitness): array
    {
        $financialValue = $this->number($result, [
            'net_profit_percent', 'total_return_percent', 'return_percent',
        ]);
        $financialSource = $financialValue !== null ? 'net_return' : null;
        if ($financialValue === null) {
            $profitFactor = $this->number($result, ['pf_attribution.summary.net_pf', 'profit_factor']);
            if ($profitFactor !== null) {
                $financialValue = $profitFactor - 1.0;
                $financialSource = 'profit_factor_margin';
            }
        }
        $financialOutcome = match (true) {
            $financialValue === null => 'unknown',
            $financialValue > 0 => 'profit',
            $financialValue < 0 => 'loss',
            default => 'breakeven',
        };

        $processAxis = (array) data_get($fitness, 'dimensions.decision_discipline', []);
        $processScore = is_numeric($processAxis['score'] ?? null)
            ? (float) $processAxis['score'] : null;
        $processPowered = $processScore !== null && (bool) ($processAxis['powered'] ?? false);
        $threshold = .90;
        $observedMistakes = $this->observedMistakes($result);
        $taxonomy = $this->taxonomy($observedMistakes, $result);
        $hardViolations = $this->hardViolations($result, $taxonomy);
        $processQuality = match (true) {
            ! $processPowered => 'unknown',
            $processScore >= $threshold && $hardViolations === [] => 'good',
            default => 'bad',
        };
        $quadrant = match ([$financialOutcome, $processQuality]) {
            ['profit', 'good'] => 'GOOD_WIN',
            ['loss', 'good'] => 'GOOD_LOSS',
            ['profit', 'bad'] => 'BAD_WIN',
            ['loss', 'bad'] => 'BAD_LOSS',
            ['breakeven', 'good'] => 'GOOD_BREAKEVEN',
            ['breakeven', 'bad'] => 'BAD_BREAKEVEN',
            default => 'UNRESOLVED',
        };
        $badProcess = in_array($quadrant, ['BAD_WIN', 'BAD_LOSS', 'BAD_BREAKEVEN'], true);

        return [
            'protocol' => self::PROTOCOL,
            'status' => $quadrant === 'UNRESOLVED' ? 'insufficient_evidence' : 'observed',
            'quadrant' => $quadrant,
            'financial' => [
                'outcome' => $financialOutcome,
                'value' => $financialValue === null ? null : round($financialValue, 6),
                'source' => $financialSource,
            ],
            'process' => [
                'quality' => $processQuality,
                'score' => $processScore === null ? null : round(max(0.0, min(1.0, $processScore)), 6),
                'powered' => $processPowered,
                'good_threshold' => $threshold,
                'hard_violations' => $hardViolations,
            ],
            'mistake_attribution' => $taxonomy,
            'learning_contract' => match ($quadrant) {
                'GOOD_WIN' => [
                    'action' => 'retain_process_and_require_causal_replication',
                    'financial_result_learning_eligible' => true,
                    'process_repair_required' => false,
                ],
                'GOOD_LOSS' => [
                    'action' => 'preserve_process_and_test_edge_or_context',
                    'financial_result_learning_eligible' => true,
                    'process_repair_required' => false,
                ],
                'BAD_WIN' => [
                    'action' => 'quarantine_profit_and_repair_process_first',
                    'financial_result_learning_eligible' => false,
                    'process_repair_required' => true,
                ],
                'BAD_LOSS', 'BAD_BREAKEVEN' => [
                    'action' => 'repair_process_before_strategy_attribution',
                    'financial_result_learning_eligible' => false,
                    'process_repair_required' => true,
                ],
                default => [
                    'action' => 'collect_process_and_financial_evidence',
                    'financial_result_learning_eligible' => false,
                    'process_repair_required' => false,
                ],
            },
            'hard_veto' => $badProcess ? 'BAD_PROCESS_OUTCOME' : null,
            'lesson_is_not_rule_change' => true,
            'technical_integrity_is_not_rule_adherence' => true,
            'promotion_evidence' => false,
        ];
    }

    /** @return list<string> */
    private function observedMistakes(array $result): array
    {
        $values = [];
        foreach (['top_mistakes', 'mistakes', 'loss_taxonomy', 'discipline.violations', 'smart_discipline.violations'] as $path) {
            foreach ((array) data_get($result, $path, []) as $item) {
                $value = is_array($item)
                    ? ($item['type'] ?? $item['code'] ?? $item['mistake_type'] ?? null)
                    : $item;
                if (is_string($value) && trim($value) !== '') {
                    $values[] = strtolower(trim($value));
                }
            }
        }

        return array_values(array_unique($values));
    }

    /** @return list<array{code:string,label:string,source:string}> */
    private function taxonomy(array $mistakes, array $result): array
    {
        if ((bool) data_get($result, 'data_drift', false)) $mistakes[] = 'data_drift';
        if ((bool) data_get($result, 'execution_drift', false)) $mistakes[] = 'execution_drift';
        if (data_get($result, 'temporal_firewall_passed') === false) $mistakes[] = 'temporal_firewall';

        $rows = [];
        foreach (array_values(array_unique($mistakes)) as $mistake) {
            [$code, $label] = match (true) {
                str_contains($mistake, 'regime'), str_contains($mistake, 'sideway'), str_contains($mistake, 'transition') => ['M01', 'regime_error'],
                str_contains($mistake, 'bias'), str_contains($mistake, 'context'), str_contains($mistake, 'direction') => ['M02', 'bias_context_error'],
                str_contains($mistake, 'setup') => ['M03', 'setup_error'],
                str_contains($mistake, 'filter'), str_contains($mistake, 'abstention') => ['M04', 'filter_error'],
                str_contains($mistake, 'confirm') => ['M05', 'confirmation_error'],
                str_contains($mistake, 'discipline'), str_contains($mistake, 'rule'), str_contains($mistake, 'fomo'), str_contains($mistake, 'overtrad'), str_contains($mistake, 'revenge') => ['M12', 'discipline_error'],
                str_contains($mistake, 'entry'), str_contains($mistake, 'chase') => ['M06', 'entry_error'],
                str_contains($mistake, 'stop'), str_contains($mistake, 'invalid') => ['M07', 'stop_invalidation_error'],
                str_contains($mistake, 'siz'), str_contains($mistake, 'lot'), str_contains($mistake, 'risk_budget') => ['M08', 'sizing_error'],
                str_contains($mistake, 'slippage'), str_contains($mistake, 'fill'), str_contains($mistake, 'execution'), str_contains($mistake, 'cost') => ['M09', 'execution_error'],
                str_contains($mistake, 'manage'), str_contains($mistake, 'trail'), str_contains($mistake, 'breakeven'), str_contains($mistake, 'partial') => ['M10', 'management_error'],
                str_contains($mistake, 'exit'), str_contains($mistake, 'target') => ['M11', 'exit_error'],
                str_contains($mistake, 'fatigue'), str_contains($mistake, 'fear'), str_contains($mistake, 'greed'), str_contains($mistake, 'decision_state') => ['M13', 'decision_state_error'],
                str_contains($mistake, 'news'), str_contains($mistake, 'event') => ['M14', 'news_event_error'],
                str_contains($mistake, 'data'), str_contains($mistake, 'timeout'), str_contains($mistake, 'operational'), str_contains($mistake, 'firewall') => ['M15', 'operational_error'],
                default => ['M00', 'unclassified_error'],
            };
            $rows[] = ['code' => $code, 'label' => $label, 'source' => $mistake];
        }

        return $rows;
    }

    /** @return list<string> */
    private function hardViolations(array $result, array $taxonomy): array
    {
        $violations = [];
        if ((bool) data_get($result, 'data_drift', false)) $violations[] = 'DATA_DRIFT';
        if ((bool) data_get($result, 'execution_drift', false)) $violations[] = 'EXECUTION_DRIFT';
        if (data_get($result, 'temporal_firewall_passed') === false) $violations[] = 'TEMPORAL_FIREWALL';
        foreach (['smart_discipline.rule_violation_count', 'discipline.rule_violation_count', 'execution_policy.unauthorized_override_count'] as $path) {
            if ((int) data_get($result, $path, 0) > 0) $violations[] = strtoupper(str_replace('.', '_', $path));
        }
        foreach ($taxonomy as $row) {
            if (in_array($row['code'], ['M12', 'M13', 'M15'], true)) $violations[] = $row['code'].':'.$row['label'];
        }

        return array_values(array_unique($violations));
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
