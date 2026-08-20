<?php

namespace App\Services;

/**
 * Converts a binary gate result into a bounded, non-promotional learning
 * signal. A positive margin means that the observed metric is on the safe
 * side of its threshold; a negative margin is the remaining deficit.
 *
 * This service deliberately does not decide a gate. CandidateGateDecision-
 * Service remains the only owner of promotion decisions. Margins only rank
 * near-misses and route the next declared failure-specific experiment.
 */
class GateMarginService
{
    public const PROTOCOL = 'gate_margin_v1';

    /** @var array<string, string> */
    private const REASON_TARGETS = [
        'FAILED_TRADE_COUNT' => 'trade_frequency',
        'FAILED_LOW_SCREEN_TRADES' => 'trade_frequency',
        'FAILED_NO_OPPORTUNITY' => 'trade_frequency',
        'FAILED_PROFIT_FACTOR' => 'profit_factor',
        'FAILED_NON_POSITIVE_SCORE' => 'profit_factor',
        'FAILED_FORWARD_SCORE' => 'profit_factor',
        'FAILED_STRESS_COST' => 'stress_cost',
        'FAILED_EXECUTION_STRESS_GATE' => 'stress_cost',
        'FAILED_WOUND_TEMPORAL_CHUNK' => 'temporal_stability',
        'FAILED_WOUND_CALENDAR_MONTH' => 'calendar_stability',
        'FAILED_WOUND_TRAIN_FORWARD_GAP' => 'train_forward_robustness',
        'FAILED_WOUND_COST_EXIT_STRESS' => 'stress_cost',
        'FAILED_TEMPORAL_CHUNK_SURVIVAL' => 'temporal_stability',
        'FAILED_CALENDAR_MONTH_SURVIVAL' => 'calendar_stability',
        'FAILED_MONTHLY_SURVIVAL' => 'calendar_stability',
        'FAILED_TRAIN_FORWARD_GAP' => 'train_forward_robustness',
        'FAILED_TEMPORAL_SCORE_DRIFT' => 'temporal_stability',
        'FAILED_STRATIFIED_HISTORICAL_SURVIVAL' => 'temporal_stability',
        'FAILED_STRATIFIED_HISTORICAL_CATASTROPHIC' => 'temporal_stability',
        'FAILED_PARAMETER_STABILITY' => 'temporal_stability',
        'FAILED_SIGNAL_TIMING_STABILITY' => 'temporal_stability',
        'FAILED_REGIME_COVERAGE' => 'regime_coverage',
        'INSUFFICIENT_REGIME_EVIDENCE' => 'regime_coverage',
        'FAILED_TRANSITION' => 'regime_coverage',
        'FAILED_NON_TARGET_REGRESSION' => 'drawdown_risk',
        'FAILED_DRAWDOWN' => 'drawdown_risk',
        'FAILED_RUIN' => 'drawdown_risk',
        'FAILED_RUIN_RISK' => 'drawdown_risk',
        'FAILED_OVERFIT' => 'architecture',
        'FAILED_STATISTICAL' => 'architecture',
        'FAILED_ELITE_PASSPORT' => 'architecture',
    ];

    /** @return array<string, mixed> */
    public function screening(array $result, array $reasonCodes = []): array
    {
        $gates = $this->buildGates($result, 'screening');

        $targets = array_values(array_unique(array_filter(array_map(
            fn (mixed $reason): ?string => $this->targetForReason((string) $reason),
            $reasonCodes,
        ))));
        $contracts = app(GateContractService::class)->contracts($reasonCodes);
        $canonicalTargets = collect($contracts)
            ->pluck('optimization_target')
            ->filter()
            ->unique()
            ->values()
            ->all();
        $contractGates = array_values(array_unique(array_filter(array_map(
            static fn (array $contract): ?string => data_get($contract, 'gate'),
            $contracts,
        ))));
        $dominantTarget = $this->dominantTarget($targets, $gates);
        $dominantGate = $this->dominantGate($contractGates, $gates);
        $targetMargin = $dominantGate !== null ? ($gates[$dominantGate] ?? null) : $this->targetMargin($dominantTarget, $gates);
        $known = collect($gates)->filter(fn (array $gate): bool => $gate['status'] !== 'unknown');
        $deficit = round($known->sum(fn (array $gate): float => (float) ($gate['deficit_normalized'] ?? 0.0)), 6);
        $nearMissScore = $known->isEmpty()
            ? 0.0
            : round(100.0 / (1.0 + $deficit), 4);

        return [
            'protocol' => self::PROTOCOL,
            'stage' => 'screening',
            'gates' => $gates,
            'gate_margin_vector' => $this->vector($gates),
            'failure_targets' => $targets,
            'failure_contracts' => $contracts,
            'canonical_failure_targets' => $canonicalTargets,
            'dominant_target' => $dominantTarget,
            'optimization_target' => $dominantGate ?: $dominantTarget,
            'dominant_gate' => $dominantGate,
            'target_margin' => $targetMargin,
            'near_miss_score' => $nearMissScore,
            'total_normalized_deficit' => $deficit,
            'known_gate_count' => $known->count(),
            'all_known_gates_passed' => $known->isNotEmpty() && $known->every(fn (array $gate): bool => $gate['passed'] === true),
            'promotion_evidence' => false,
            'rule' => 'Gate margin ranks research near-misses only; it never lowers a gate or opens full replay/paper eligibility.',
        ];
    }

    /** @return array<string, mixed> */
    public function forward(array $result, array $reasonCodes = []): array
    {
        $margin = $this->screening($result, $reasonCodes);
        $gates = $this->buildGates($result, 'forward');
        $targets = array_values(array_unique(array_filter(array_map(
            fn (mixed $reason): ?string => $this->targetForReason((string) $reason),
            $reasonCodes,
        ))));
        $dominantTarget = $this->dominantTarget($targets, $gates);
        $known = collect($gates)->filter(fn (array $gate): bool => $gate['status'] !== 'unknown');
        $deficit = round($known->sum(fn (array $gate): float => (float) ($gate['deficit_normalized'] ?? 0.0)), 6);

        return [
            ...$margin,
            'stage' => 'forward',
            'gates' => $gates,
            'gate_margin_vector' => $this->vector($gates),
            'dominant_target' => $dominantTarget,
            'target_margin' => $this->targetMargin($dominantTarget, $gates),
            'near_miss_score' => $known->isEmpty() ? 0.0 : round(100.0 / (1.0 + $deficit), 4),
            'total_normalized_deficit' => $deficit,
        ];
    }

    /** @return array<string, mixed> */
    public function compare(array $candidate, array $control, ?string $target = null): array
    {
        $candidateMargin = $this->screening($candidate, []);
        $controlMargin = $this->screening($control, []);
        $target ??= (string) data_get($candidateMargin, 'dominant_target', 'profit_factor');
        $target = $target !== '' ? $target : 'profit_factor';
        $candidateScore = $this->targetObservation($target, $candidateMargin);
        $controlScore = $this->targetObservation($target, $controlMargin);
        $candidateMarginValue = $this->targetMargin($target, (array) $candidateMargin['gates']);
        $controlMarginValue = $this->targetMargin($target, (array) $controlMargin['gates']);
        $improved = $candidateScore !== null && $controlScore !== null
            ? ($target === 'drawdown_risk' ? $candidateScore < $controlScore : $candidateScore > $controlScore)
            : null;

        return [
            'protocol' => 'frozen_control_parity_v1',
            'target' => $target,
            'candidate_observation' => $candidateScore,
            'control_observation' => $controlScore,
            'candidate_margin' => $candidateMarginValue,
            'control_margin' => $controlMarginValue,
            'margin_delta' => $candidateMarginValue !== null && $controlMarginValue !== null
                ? round($candidateMarginValue['normalized_margin'] - $controlMarginValue['normalized_margin'], 6)
                : null,
            'candidate_better' => $improved,
            'candidate_near_miss_score' => data_get($candidateMargin, 'near_miss_score'),
            'control_near_miss_score' => data_get($controlMargin, 'near_miss_score'),
            'same_data_hash' => $this->sameHash($candidate, $control, 'data_manifest.snapshot_sha256', 'data_manifest.sha256'),
            'same_execution_hash' => $this->sameHash($candidate, $control, 'execution_contract.execution_hash', 'execution_hash'),
            'control_gate_status' => data_get($controlMargin, 'all_known_gates_passed') === true ? 'passed' : 'failed_or_incomplete',
            'promotion_evidence' => false,
        ];
    }

    public function targetForReason(string $reason): ?string
    {
        $reason = strtoupper(trim($reason));

        return self::REASON_TARGETS[$reason]
            ?? self::REASON_TARGETS[preg_replace('/^FAILED_RESCUE_/', 'FAILED_', $reason)]
            ?? null;
    }

    public function gateForReason(string $reason): ?string
    {
        return app(GateContractService::class)->gateForReason($reason);
    }

    public function optimizationTargetForReason(string $reason): ?string
    {
        return app(GateContractService::class)->optimizationTargetForReason($reason);
    }

    /** @param array<string, array<string, mixed>> $gates */
    private function dominantTarget(array $targets, array $gates): string
    {
        $candidates = collect($targets)
            ->filter(fn (mixed $target): bool => is_string($target) && $target !== '')
            ->unique()
            ->values();
        if ($candidates->isNotEmpty()) {
            $ranked = $candidates->mapWithKeys(function (string $target) use ($gates): array {
                $margin = $this->targetMargin($target, $gates);

                return [$target => $margin === null ? null : (float) data_get($margin, 'normalized_margin', 0)];
            })->filter(fn (mixed $margin): bool => is_numeric($margin));
            if ($ranked->isNotEmpty()) return (string) $ranked->sort()->keys()->first();

            return (string) $candidates->first();
        }

        $fallback = collect($gates)
            ->filter(fn (array $gate): bool => $gate['status'] !== 'unknown')
            ->sortBy(fn (array $gate): float => (float) $gate['normalized_margin'])
            ->first();

        return (string) data_get($fallback, 'name', 'profit_factor');
    }

    /** @param array<string, array<string, mixed>> $gates */
    private function targetMargin(string $target, array $gates): ?array
    {
        $keys = match ($target) {
            'temporal_stability' => ['temporal_stability'],
            'monthly_survival', 'calendar_stability' => ['calendar_stability'],
            'train_forward_robustness' => ['train_forward_robustness'],
            'parameter_stability' => ['parameter_stability'],
            'regime_coverage' => ['regime_coverage'],
            'stress_cost' => ['stress_cost'],
            'drawdown_risk' => ['drawdown_risk', 'ruin_risk'],
            'trade_frequency' => ['trade_frequency'],
            'architecture' => ['profit_factor', 'temporal_stability'],
            default => ['profit_factor'],
        };
        $available = collect($keys)
            ->map(fn (string $key): ?array => $gates[$key] ?? null)
            ->filter(fn (?array $gate): bool => is_array($gate) && $gate['status'] !== 'unknown');
        if ($available->isEmpty()) return null;

        return $available->sortBy('normalized_margin')->first();
    }

    /** @param array<int, string> $gates */
    private function dominantGate(array $gates, array $margin): ?string
    {
        return collect($gates)
            ->filter(fn (string $gate): bool => isset($margin[$gate]) && data_get($margin[$gate], 'status') !== 'unknown')
            ->sortBy(fn (string $gate): float => (float) data_get($margin, $gate.'.normalized_margin', INF))
            ->first();
    }

    private function targetObservation(string $target, array $margin): ?float
    {
        return data_get($this->targetMargin($target, (array) data_get($margin, 'gates', [])), 'observed');
    }

    /** @return array<string, array<string, mixed>> */
    private function buildGates(array $result, string $stage): array
    {
        $definitions = app(GateContractService::class)->gateDefinitions($stage);

        return collect($definitions)->mapWithKeys(function (array $definition, string $name) use ($result): array {
            return [$name => $this->gate(
                $name,
                $this->number($result, (array) data_get($definition, 'paths', [])),
                (float) data_get($definition, 'threshold'),
                (string) data_get($definition, 'direction', 'higher'),
                (float) data_get($definition, 'scale', 1.0),
            )];
        })->all();
    }

    /** @return array<string, mixed> */
    private function gate(string $name, ?float $observed, float $threshold, string $direction, float $scale): array
    {
        if ($observed === null) {
            return [
                'name' => $name, 'status' => 'unknown', 'observed' => null,
                'threshold' => $threshold, 'direction' => $direction,
                'margin' => null, 'normalized_margin' => null,
                'deficit_normalized' => null, 'passed' => false,
            ];
        }
        $margin = $direction === 'lower' ? $threshold - $observed : $observed - $threshold;
        $normalized = $scale > 0 ? $margin / $scale : $margin;

        return [
            'name' => $name, 'status' => $margin >= 0 ? 'passed' : 'failed',
            'observed' => round($observed, 8), 'threshold' => $threshold,
            'direction' => $direction, 'margin' => round($margin, 8),
            'normalized_margin' => round($normalized, 8),
            'deficit_normalized' => round(max(0.0, -$normalized), 8),
            'passed' => $margin >= 0,
        ];
    }

    private function number(array $payload, array $paths): ?float
    {
        foreach ($paths as $path) {
            $value = data_get($payload, $path);
            if (is_numeric($value)) return (float) $value;
        }

        return null;
    }

    private function sameHash(array $left, array $right, string $primary, string $fallback): bool
    {
        $a = data_get($left, $primary, data_get($left, $fallback, ''));
        $b = data_get($right, $primary, data_get($right, $fallback, ''));

        return is_string($a) && $a !== '' && is_string($b) && $b !== '' && hash_equals($a, $b);
    }

    /** @param array<string, array<string, mixed>> $gates */
    private function vector(array $gates): array
    {
        $map = [
            'temporal_margin' => 'temporal_stability',
            'calendar_margin' => 'calendar_stability',
            'train_forward_margin' => 'train_forward_robustness',
            'cost_exit_margin' => 'stress_cost',
            'pf_margin' => 'profit_factor',
            'drawdown_margin' => 'drawdown_risk',
            'trade_frequency_margin' => 'trade_frequency',
            'regime_margin' => 'regime_coverage',
        ];

        $vector = [];
        foreach ($map as $key => $gate) {
            $vector[$key] = $gates[$gate] ?? [
                'name' => $gate,
                'status' => 'unknown',
                'observed' => null,
                'threshold' => null,
                'normalized_margin' => null,
                'deficit_normalized' => null,
                'passed' => false,
            ];
        }

        return $vector;
    }
}
