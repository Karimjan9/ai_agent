<?php

namespace App\Services;

/**
 * Declares and scores the temporal mutation ablation. It is deliberately a
 * research protocol: it can describe/evaluate evidence, but it cannot change
 * a gate, promote a model, or turn a rescue into a parent.
 */
class TemporalAblationProtocolService
{
    public const PROTOCOL = 'temporal_clean_ablation_v2';
    public const SCORING_REVISION = 'temporal_control_telemetry_v1';
    public const VARIANTS = ['control', 'adaptive_expiry', 'drift_abstention', 'calibration_only'];
    public const LEGACY_VARIANT_ALIASES = [
        'state_only' => 'adaptive_expiry',
        'interaction' => 'drift_abstention',
    ];

    /** @return array<string, mixed> */
    public function contract(array $context = []): array
    {
        $windows = max(3, (int) data_get($context, 'required_independent_windows', config('services.rescue_circuit_breaker.ablation_windows', 3)));
        $threshold = (float) data_get($context, 'temporal_threshold', config('services.rescue_circuit_breaker.temporal_threshold', 1.0));
        $executionHash = data_get($context, 'execution_hash');
        $dataHash = data_get($context, 'data_hash', data_get($context, 'snapshot_hash'));

        return [
            'protocol' => self::PROTOCOL,
            'scoring_revision' => self::SCORING_REVISION,
            'status' => 'planned',
            'variants' => self::VARIANTS,
            'control_variant' => 'control',
            'candidate_variants' => ['adaptive_expiry', 'drift_abstention', 'calibration_only'],
            'required_independent_windows' => $windows,
            'minimum_candidate_beats_control_windows' => min($windows, (int) data_get($context, 'minimum_beats_control_windows', 2)),
            'temporal_threshold' => $threshold,
            'paired_identity' => [
                'same_data_hash_required_within_window' => true,
                'same_execution_hash_required_within_window' => true,
                'source_data_hash' => $dataHash,
                'source_execution_hash' => $executionHash,
                'missing_identity_blocks_evaluation' => true,
            ],
            'window_contract' => [
                'chronological' => true,
                'independent' => true,
                'no_random_resample_substitution' => true,
                'window_ids_required' => true,
                'required_roles' => ['development', 'validation', 'sealed_holdout'],
                'non_overlapping' => true,
                'purge_and_embargo_required' => true,
            ],
            'admission_rule' => 'No temporal mutation credit until exactly three sealed chronological windows share paired data/execution identity, one candidate beats control in at least two windows, reaches the temporal threshold, and introduces no material drawdown/coverage degradation.',
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function validatePlan(array $variants, array $context = []): array
    {
        $declared = array_values(array_unique(array_filter(array_map(
            fn (string $variant): string => self::LEGACY_VARIANT_ALIASES[$variant] ?? $variant,
            array_map('strval', $variants),
        ))));
        $missing = array_values(array_diff(self::VARIANTS, $declared));
        $contract = $this->contract($context);

        return [
            ...$contract,
            'status' => $missing === [] ? 'valid' : 'blocked',
            'declared_variants' => $declared,
            'missing_variants' => $missing,
            'reason_code' => $missing === [] ? null : 'TEMPORAL_ABLATION_VARIANTS_INCOMPLETE',
        ];
    }

    /**
     * Evaluate paired window observations.
     *
     * Each window is expected to contain:
     * ['window_id', 'data_hash', 'execution_hash', 'variants' => [variant => metrics]]
     * Metrics may expose temporal_margin, temporal_score, worst_temporal_pf or
     * worst_temporal_chunk_pf. Higher is better for the temporal threshold.
     *
     * @return array<string, mixed>
     */
    public function evaluate(array $windows, array $context = []): array
    {
        $contract = $this->contract($context);
        $requiredWindows = (int) $contract['required_independent_windows'];
        $threshold = (float) $contract['temporal_threshold'];
        $validRows = [];
        $invalidRows = [];
        $seenWindowIds = [];
        $seenDataHashes = [];
        $aliasesSeen = [];
        foreach (array_values($windows) as $index => $window) {
            $row = is_array($window) ? $window : [];
            $variants = $this->canonicalVariantMap((array) data_get($row, 'variants', []), $aliasesSeen);
            $missing = array_values(array_diff(self::VARIANTS, array_keys($variants)));
            $dataHash = (string) data_get($row, 'data_hash', data_get($row, 'snapshot_hash', ''));
            $executionHash = (string) data_get($row, 'execution_hash', '');
            $sameData = $dataHash !== '' && collect($variants)->every(fn (mixed $metrics): bool =>
                (string) data_get((array) $metrics, 'data_hash', $dataHash) === $dataHash
            );
            $sameExecution = $executionHash !== '' && collect($variants)->every(fn (mixed $metrics): bool =>
                (string) data_get((array) $metrics, 'execution_hash', $executionHash) === $executionHash
            );
            $windowId = (string) data_get($row, 'window_id', '');
            $reusedWindowId = $windowId !== '' && in_array($windowId, $seenWindowIds, true);
            $reusedDataHash = $dataHash !== '' && in_array($dataHash, $seenDataHashes, true);
            if ($windowId === '' || $missing !== [] || ! $sameData || ! $sameExecution || $reusedWindowId || $reusedDataHash) {
                $invalidRows[] = [
                    'index' => $index,
                    'window_id' => $windowId,
                    'missing_variants' => $missing,
                    'same_data_hash' => $sameData,
                    'same_execution_hash' => $sameExecution,
                    'reason_code' => $windowId === ''
                        ? 'TEMPORAL_WINDOW_ID_MISSING'
                        : ($reusedDataHash ? 'TEMPORAL_WINDOW_DATA_REUSED' : ($reusedWindowId ? 'TEMPORAL_WINDOW_ID_REUSED' : 'TEMPORAL_PAIRED_IDENTITY_INVALID')),
                ];
                continue;
            }
            $seenWindowIds[] = $windowId;
            $seenDataHashes[] = $dataHash;

            $control = $this->score((array) $variants['control']);
            $missingScores = collect(self::VARIANTS)
                ->filter(fn (string $variant): bool => $this->score((array) ($variants[$variant] ?? [])) === null)
                ->values()
                ->all();
            if ($missingScores !== []) {
                $invalidRows[] = [
                    'index' => $index,
                    'window_id' => $windowId,
                    'missing_variants' => [],
                    'missing_scores' => $missingScores,
                    'same_data_hash' => $sameData,
                    'same_execution_hash' => $sameExecution,
                    'reason_code' => 'TEMPORAL_METRIC_MISSING',
                ];
                continue;
            }
            $comparisons = [];
            foreach (['adaptive_expiry', 'drift_abstention', 'calibration_only'] as $candidate) {
                $score = $this->score((array) $variants[$candidate]);
                $noDegradation = $this->noMaterialDegradation((array) $variants[$candidate], (array) $variants['control']);
                $comparisons[$candidate] = [
                    'candidate_score' => $score,
                    'control_score' => $control,
                    'beats_control' => $score !== null && $control !== null && $score > $control,
                    'reaches_temporal_threshold' => $score !== null && $score >= $threshold,
                    'no_material_degradation' => $noDegradation,
                ];
            }
            $validRows[] = [
                'window_id' => $windowId,
                'chronological_order' => (int) data_get($row, 'chronological_order', $index + 1),
                'data_hash' => $dataHash,
                'execution_hash' => $executionHash,
                'comparisons' => $comparisons,
            ];
        }

        $results = [];
        foreach (['adaptive_expiry', 'drift_abstention', 'calibration_only'] as $candidate) {
            $beats = collect($validRows)->filter(fn (array $row): bool => (bool) data_get($row, 'comparisons.'.$candidate.'.beats_control', false))->count();
            $thresholdWindows = collect($validRows)->filter(fn (array $row): bool => (bool) data_get($row, 'comparisons.'.$candidate.'.reaches_temporal_threshold', false))->count();
            $stableWindows = collect($validRows)->filter(fn (array $row): bool => (bool) data_get($row, 'comparisons.'.$candidate.'.no_material_degradation', false))->count();
            $results[$candidate] = [
                'beats_control_windows' => $beats,
                'threshold_windows' => $thresholdWindows,
                'no_material_degradation_windows' => $stableWindows,
                'qualified' => count($validRows) >= $requiredWindows
                    && $beats >= (int) $contract['minimum_candidate_beats_control_windows']
                    && $thresholdWindows >= (int) $contract['minimum_candidate_beats_control_windows'],
            ];
        }
        foreach ($results as $candidate => &$result) {
            $result['qualified'] = $result['qualified']
                && $result['no_material_degradation_windows'] >= (int) $contract['minimum_candidate_beats_control_windows'];
        }
        unset($result);
        $qualified = collect($results)->filter(fn (array $result): bool => (bool) $result['qualified'])->keys()->values()->all();
        foreach ($aliasesSeen as $alias => $canonical) {
            if (in_array($canonical, $qualified, true)) $qualified[] = $alias;
        }
        $status = count($validRows) < $requiredWindows
            ? 'incomplete'
            : ($qualified !== [] ? 'qualified' : 'failed');

        return [
            ...$contract,
            'status' => $status,
            'observed_window_count' => count($validRows),
            'valid_windows' => $validRows,
            'invalid_windows' => $invalidRows,
            'variant_results' => $results,
            'qualified_variants' => $qualified,
            'mutation_credit_allowed' => false,
            'promotion_evidence' => false,
        ];
    }

    private function score(array $metrics): ?float
    {
        foreach (['temporal_margin', 'temporal_score', 'worst_temporal_pf', 'worst_temporal_chunk_pf'] as $key) {
            if (is_numeric($metrics[$key] ?? null)) return (float) $metrics[$key];
        }

        return null;
    }

    /** @param array<string, mixed> $variants */
    private function canonicalVariantMap(array $variants, array &$aliasesSeen): array
    {
        $canonical = [];
        foreach ($variants as $name => $metrics) {
            $name = (string) $name;
            $target = self::LEGACY_VARIANT_ALIASES[$name] ?? $name;
            if ($target !== $name) $aliasesSeen[$name] = $target;
            if (! array_key_exists($target, $canonical)) $canonical[$target] = $metrics;
        }

        return $canonical;
    }

    private function noMaterialDegradation(array $candidate, array $control): bool
    {
        $candidateDrawdown = $this->numeric($candidate, ['max_drawdown_percent', 'max_drawdown']);
        $controlDrawdown = $this->numeric($control, ['max_drawdown_percent', 'max_drawdown']);
        if ($candidateDrawdown !== null && $controlDrawdown !== null
            && $candidateDrawdown > max(0.0, $controlDrawdown) * 1.15 + .25) return false;

        $candidateTrades = $this->numeric($candidate, ['total_trades', 'trade_count', 'trades']);
        $controlTrades = $this->numeric($control, ['total_trades', 'trade_count', 'trades']);
        if ($candidateTrades !== null && $controlTrades !== null && $controlTrades > 0
            && $candidateTrades < $controlTrades * .50) return false;

        $candidateSurvival = $this->numeric($candidate, ['calendar_month_survival', 'temporal_survival_rate']);
        $controlSurvival = $this->numeric($control, ['calendar_month_survival', 'temporal_survival_rate']);
        if ($candidateSurvival !== null && $controlSurvival !== null
            && $candidateSurvival + .10 < $controlSurvival) return false;

        return true;
    }

    /** @param array<string, mixed> $metrics @param array<int, string> $keys */
    private function numeric(array $metrics, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (is_numeric($metrics[$key] ?? null)) return (float) $metrics[$key];
        }

        return null;
    }
}
