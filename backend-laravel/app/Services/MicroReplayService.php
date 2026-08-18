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
        $control = $this->verifiedControl($pair);
        if (! $control['verified']) {
            $debugWindows = $this->windows((array) $pair->candidate_metrics);
            [$positiveWindows, $hardFailures] = $this->windowCounts($debugWindows);
            $assessment = [
                'protocol' => self::PROTOCOL,
                'status' => 'failed',
                'reason' => 'MISSING_FROZEN_CONTROL_PAIR',
                'causal_probe' => $control,
                'positive_windows' => $positiveWindows,
                'hard_failures' => $hardFailures,
                'promotion_evidence' => false,
            ];
            if ($persist) $this->dojo->recordAssessment($pair, $assessment);
            return $assessment;
        }
        if ($persist) {
            $existing = $pair->dispatches()->whereIn('micro_status', ['failed', 'passed'])->latest('id')->first();
            if ($existing?->micro_status === 'failed'
                && ! in_array((string) data_get($existing->micro_metadata, 'reason'), [
                    'MISSING_FROZEN_CONTROL_PAIR', 'CAUSAL_OBSERVATION_INCOMPLETE',
                    'PARAMETER_ONLY_NO_CAUSAL_EFFECT', 'NO_TARGET_GATE_IMPROVEMENT',
                ], true)) {
                return (array) ($existing->micro_metadata ?: [
                    'protocol' => self::PROTOCOL, 'status' => 'failed', 'reason' => 'MICRO_ALREADY_FAILED',
                ]);
            }
        }
        $candidate = (array) $pair->candidate_metrics;
        $causal = $this->causalProbe($pair, $candidate, (array) $pair->control_metrics);
        if ($causal['status'] !== 'passed') {
            $assessment = [
                'protocol' => self::PROTOCOL,
                'status' => 'failed',
                'reason' => $causal['reason'],
                'causal_probe' => $causal,
                'score' => 0.0,
                'promotion_evidence' => false,
            ];
            if ($persist) $this->dojo->recordAssessment($pair, $assessment);
            return $assessment;
        }
        $windows = $this->windows($candidate);
        $requiredWindows = max(3, (int) config('services.learning_lane.micro_windows_required', 3));
        if (count($windows) < $requiredWindows) {
            $assessment = [
                'protocol' => self::PROTOCOL,
                'status' => 'deferred',
                'reason' => 'MICRO_WINDOWS_INSUFFICIENT',
                'windows' => $windows,
                'causal_probe' => $causal,
                'score' => 0.0,
                'promotion_evidence' => false,
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
            'causal_probe' => $causal,
            'positive_windows' => $positive,
            'hard_failures' => $hardFailures,
            'score' => round($positive / max(1, $requiredWindows), 6),
            'promotion_evidence' => false,
        ];
        if ($persist) $this->dojo->recordAssessment($pair, $assessment);
        return $assessment;
    }

    /** @return array<string, mixed> */
    private function verifiedControl(LabLearningLanePair $pair): array
    {
        $metadata = (array) $pair->metadata;
        $verified = in_array((string) $pair->status, [
            'screen_paired', 'provisional', 'learning_queued', 'learning_observed', 'confirmed',
        ], true)
            && (int) $pair->control_agent_id > 0
            && (int) $pair->control_response_map_id > 0
            && (array) $pair->control_metrics !== []
            && (bool) data_get($metadata, 'same_snapshot', false)
            && (bool) data_get($metadata, 'same_execution_contract', false)
            && \App\Models\LabMutationResponseMap::query()
                ->whereKey($pair->control_response_map_id)
                ->where('status', 'control')
                ->exists();

        return [
            'protocol' => StructuralResearchCohortService::CONTROL_PAIR_PROTOCOL,
            'status' => $verified ? 'verified' : 'missing_control',
            'verified' => $verified,
            'control_agent_id' => $pair->control_agent_id,
            'control_response_map_id' => $pair->control_response_map_id,
            'same_snapshot' => (bool) data_get($metadata, 'same_snapshot', false),
            'same_execution_contract' => (bool) data_get($metadata, 'same_execution_contract', false),
            'promotion_evidence' => false,
        ];
    }

    /**
     * A parameter fingerprint is only a declaration. The probe requires a
     * changed trade set plus an observable entry/exit/event effect and a
     * positive target-margin delta against the same frozen control.
     *
     * @return array<string, mixed>
     */
    private function causalProbe(LabLearningLanePair $pair, array $candidate, array $control): array
    {
        $candidateView = $this->causalView($candidate);
        $controlView = $this->causalView($control);
        $hashAvailable = $candidateView['trade_set_hash'] !== '' && $controlView['trade_set_hash'] !== '';
        $tradeSetChanged = $hashAvailable && ! hash_equals($candidateView['trade_set_hash'], $controlView['trade_set_hash']);
        $entryChanged = $candidateView['accepted_entry_count'] !== null
            && $controlView['accepted_entry_count'] !== null
            && $candidateView['accepted_entry_count'] !== $controlView['accepted_entry_count'];
        $exitChanged = $candidateView['accepted_exit_count'] !== null
            && $controlView['accepted_exit_count'] !== null
            && $candidateView['accepted_exit_count'] !== $controlView['accepted_exit_count'];
        $eventChanged = $candidateView['event_hash'] !== '' && $controlView['event_hash'] !== ''
            && ! hash_equals($candidateView['event_hash'], $controlView['event_hash']);
        $abstentionAvailable = $candidateView['abstention_count'] !== null && $controlView['abstention_count'] !== null;
        $abstentionRemovedRealTrade = $abstentionAvailable
            && $candidateView['abstention_count'] > $controlView['abstention_count']
            && (($candidateView['trade_count'] ?? 0) < ($controlView['trade_count'] ?? 0)
                || ($candidateView['accepted_entry_count'] ?? 0) < ($controlView['accepted_entry_count'] ?? 0));
        $target = (string) ($pair->target ?: 'profit_factor');
        $comparison = app(GateMarginService::class)->compare($candidate, $control, $target);
        $marginDelta = data_get($comparison, 'margin_delta');
        $marginImproved = is_numeric($marginDelta) && (float) $marginDelta > 0;
        $behaviorChanged = $tradeSetChanged && ($entryChanged || $exitChanged || $eventChanged || $abstentionRemovedRealTrade);
        $parameterChanged = (array) data_get($candidate, 'parameter_diff', []) !== []
            || (string) data_get($candidate, 'parameter_hash', '') !== (string) data_get($control, 'parameter_hash', '');

        $reason = match (true) {
            ! $hashAvailable => 'CAUSAL_OBSERVATION_INCOMPLETE',
            ! $behaviorChanged && $parameterChanged => 'PARAMETER_ONLY_NO_CAUSAL_EFFECT',
            ! $behaviorChanged => 'CAUSAL_BEHAVIOR_UNCHANGED',
            ! $marginImproved => 'NO_TARGET_GATE_IMPROVEMENT',
            default => 'CAUSAL_EFFECT_CONFIRMED',
        };

        return [
            'protocol' => StructuralResearchCohortService::CAUSAL_PROBE_PROTOCOL,
            'status' => $reason === 'CAUSAL_EFFECT_CONFIRMED' ? 'passed' : 'failed',
            'reason' => $reason,
            'trade_set_hash_changed' => $tradeSetChanged,
            'candidate_trade_set_hash' => $candidateView['trade_set_hash'],
            'control_trade_set_hash' => $controlView['trade_set_hash'],
            'accepted_entry_count_changed' => $entryChanged,
            'candidate_accepted_entry_count' => $candidateView['accepted_entry_count'],
            'control_accepted_entry_count' => $controlView['accepted_entry_count'],
            'accepted_exit_count_changed' => $exitChanged,
            'candidate_accepted_exit_count' => $candidateView['accepted_exit_count'],
            'control_accepted_exit_count' => $controlView['accepted_exit_count'],
            'event_digest_changed' => $eventChanged,
            'abstention_check_available' => $abstentionAvailable,
            'abstention_removed_real_trade' => $abstentionRemovedRealTrade,
            'candidate_abstention_count' => $candidateView['abstention_count'],
            'control_abstention_count' => $controlView['abstention_count'],
            'target' => $target,
            'target_gate_margin_delta' => is_numeric($marginDelta) ? (float) $marginDelta : null,
            'target_gate_margin_improved_vs_control' => $marginImproved,
            'control_comparison' => $comparison,
            'parameter_hash_alone_is_insufficient' => true,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function causalView(array $metrics): array
    {
        $tradeHash = '';
        foreach (['trade_set_hash', 'trade_ledger_hash', 'observability_manifest.trade_ledger_hash', 'trade_digest.hash'] as $path) {
            $value = data_get($metrics, $path);
            if (is_scalar($value) && trim((string) $value) !== '') {
                $tradeHash = (string) $value;
                break;
            }
        }
        $eventHash = '';
        foreach (['event_ledger_hash', 'event_digest.hash', 'execution_event_hash', 'observability_manifest.event_ledger_hash'] as $path) {
            $value = data_get($metrics, $path);
            if (is_scalar($value) && trim((string) $value) !== '') {
                $eventHash = (string) $value;
                break;
            }
        }

        return [
            'trade_set_hash' => $tradeHash,
            'event_hash' => $eventHash,
            'accepted_entry_count' => $this->firstNumeric($metrics, [
                'entry_funnel.accepted_entries', 'accepted_entry_count', 'accepted_entries',
            ]),
            'accepted_exit_count' => $this->firstNumeric($metrics, [
                'exit_funnel.accepted_exits', 'accepted_exit_count', 'accepted_exits',
                'closed_trade_count', 'total_trades', 'trade_count',
            ]),
            'trade_count' => $this->firstNumeric($metrics, ['total_trades', 'trade_count', 'sample_count']),
            'abstention_count' => $this->firstNumeric($metrics, [
                'abstention_count', 'temporal_survival.abstention_count',
                'temporal_survival_abstention.abstention_count', 'veto_metrics.abstention_count',
            ]),
        ];
    }

    /** @return array{0:int,1:int} */
    private function windowCounts(array $windows): array
    {
        $positive = 0;
        $hardFailures = 0;
        foreach (array_slice($windows, 0, max(3, (int) config('services.learning_lane.micro_windows_required', 3))) as $window) {
            $status = strtolower((string) ($window['status'] ?? $window['decision'] ?? ''));
            $pf = $window['profit_factor'] ?? $window['pf'] ?? null;
            $net = $window['net_return'] ?? $window['net_pct'] ?? $window['net'] ?? null;
            if (in_array($status, ['pass', 'passed', 'positive', 'valid', 'ok'], true)
                || (is_numeric($pf) && (float) $pf > 1.0 && (! is_numeric($net) || (float) $net >= 0))) $positive++;
            if (in_array($status, ['fail', 'failed', 'invalid', 'blocked', 'critical'], true)
                || (is_numeric($pf) && (float) $pf < 0.85)) $hardFailures++;
        }

        return [$positive, $hardFailures];
    }

    private function firstNumeric(array $payload, array $paths): ?int
    {
        foreach ($paths as $path) {
            $value = data_get($payload, $path);
            if (is_numeric($value)) return (int) $value;
        }

        return null;
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
