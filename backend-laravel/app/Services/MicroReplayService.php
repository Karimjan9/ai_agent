<?php

namespace App\Services;

use App\Models\LabLearningLaneDispatch;
use App\Models\LabLearningLanePair;
use Illuminate\Support\Facades\Schema;

/**
 * Cheap confirmation gate for the learning lane.
 *
 * Screening already stores sealed temporal-survival windows.  We use those
 * immutable windows as three independent micro-replay slices before paying
 * for a full replay.  This is intentionally research-only and can never set
 * a promotion/forward decision.
 */
class MicroReplayService
{
    public const PROTOCOL = 'micro_replay_v1';

    public function __construct(private readonly FailureDojoService $dojo) {}

    /** @return array<string, mixed> */
    public function assessPair(LabLearningLanePair $pair, bool $persist = true): array
    {
        if ($persist) {
            $existing = $pair->dispatches()->whereIn('micro_status', ['failed', 'passed'])->latest('id')->first();
            if ($existing?->micro_status === 'failed') {
                return (array) ($existing->micro_metadata ?: [
                    'protocol' => self::PROTOCOL, 'status' => 'failed', 'reason' => 'MICRO_ALREADY_FAILED',
                ]);
            }
        }
        $candidate = (array) $pair->candidate_metrics;
        $windows = $this->windows($candidate);
        $requiredWindows = max(3, (int) config('services.learning_lane.micro_windows_required', 3));
        if (count($windows) < $requiredWindows) {
            $assessment = [
                'protocol' => self::PROTOCOL,
                'status' => 'deferred',
                'reason' => 'MICRO_WINDOWS_INSUFFICIENT',
                'windows' => $windows,
                'score' => 0.0,
            ];
            if ($persist) $this->dojo->recordAssessment($pair, $assessment);
            return $assessment;
        }

        $positive = 0;
        $hardFailures = 0;
        $scores = [];
        foreach (array_slice($windows, 0, $requiredWindows) as $window) {
            $status = strtolower((string) ($window['status'] ?? $window['decision'] ?? ''));
            $pf = $window['profit_factor'] ?? $window['pf'] ?? null;
            $net = $window['net_return'] ?? $window['net_pct'] ?? $window['net'] ?? null;
            $isPositive = in_array($status, ['pass', 'passed', 'positive', 'valid', 'ok'], true)
                || (is_numeric($pf) && (float) $pf > 1.0 && (! is_numeric($net) || (float) $net >= 0));
            $isHardFailure = in_array($status, ['fail', 'failed', 'invalid', 'blocked', 'critical'], true)
                || (is_numeric($pf) && (float) $pf < 0.85);
            if ($isPositive) $positive++;
            if ($isHardFailure) $hardFailures++;
            $scores[] = [
                'key' => $window['key'] ?? $window['window_key'] ?? count($scores),
                'positive' => $isPositive,
                'hard_failure' => $isHardFailure,
                'pf' => is_numeric($pf) ? (float) $pf : null,
                'net' => is_numeric($net) ? (float) $net : null,
            ];
        }

        $passed = $positive >= max(2, (int) config('services.learning_lane.micro_positive_windows_required', 2)) && $hardFailures === 0;
        $assessment = [
            'protocol' => self::PROTOCOL,
            'status' => $passed ? 'passed' : 'failed',
            'reason' => $passed ? 'MICRO_2_OF_3_PASS' : 'MICRO_CONFIRMATION_FAILED',
            'windows' => $scores,
            'positive_windows' => $positive,
            'hard_failures' => $hardFailures,
            'score' => round($positive / max(1, $requiredWindows), 6),
        ];
        if ($persist) $this->dojo->recordAssessment($pair, $assessment);
        return $assessment;
    }

    /** @return array<int, array<string, mixed>> */
    private function windows(array $metrics): array
    {
        $candidates = [
            data_get($metrics, 'window_survival.windows'),
            data_get($metrics, 'screening_survival.windows'),
            data_get($metrics, 'monthly_passport.windows'),
            data_get($metrics, 'forward_windows'),
        ];
        foreach ($candidates as $windows) {
            if (is_array($windows) && count($windows) >= 3) {
                return array_values(array_filter($windows, 'is_array'));
            }
        }
        // The current screening projection stores the three chronological
        // slices as compact PF/score vectors rather than verbose window rows.
        // Expand that immutable projection into the same micro contract.
        $profitFactors = data_get($metrics, 'screening_survival.temporal_chunk_survival.window_profit_factors', []);
        $scores = data_get($metrics, 'screening_survival.temporal_chunk_survival.window_scores', []);
        if (is_array($profitFactors) && count($profitFactors) >= 3) {
            return collect(array_values($profitFactors))->map(function ($pf, int $index) use ($scores): array {
                return [
                    'key' => 'temporal_chunk_'.($index + 1),
                    'profit_factor' => is_numeric($pf) ? (float) $pf : null,
                    'score' => $scores[$index] ?? null,
                    'status' => is_numeric($pf) && (float) $pf > 1.0 ? 'passed' : 'failed',
                ];
            })->all();
        }
        return [];
    }
}
