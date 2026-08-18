<?php

namespace App\Services;

/**
 * Makes partial learning visible without confusing it with a gate pass. The
 * ladder is monotonic and promotion remains owned by CandidateGateDecision.
 */
class ProgressLadderService
{
    public const PROTOCOL = 'progress_ladder_v1';

    /** @var array<int, string> */
    public const STAGES = [
        'parameter_changed',
        'signal_changed',
        'trade_changed',
        'event_changed',
        'target_deficit_reduced',
        'control_parity_improved',
        'independent_holdout_confirmed',
        'screening_pass',
    ];

    /** @return array<string, mixed> */
    public function assess(array $observability, array $candidate = [], array $controlRelative = []): array
    {
        $flags = [
            'parameter_changed' => (bool) data_get($observability, 'parameter_changed', false),
            'signal_changed' => (bool) data_get($observability, 'signal_decisions.changed', false),
            'trade_changed' => (bool) data_get($observability, 'trade_ledger.changed', false),
            'event_changed' => (bool) data_get($observability, 'event_ledger.changed', false)
                && (bool) data_get($observability, 'event_ledger.available', false),
            'target_deficit_reduced' => (bool) data_get($observability, 'gate_margin.target_gate_improved', false),
            'control_parity_improved' => (bool) data_get($controlRelative, 'control_relative_improved', false),
            'independent_holdout_confirmed' => $this->holdoutConfirmed($candidate),
            'screening_pass' => (string) data_get($candidate, 'screen_decision', data_get($candidate, 'decision')) === 'passed',
        ];

        $reached = [];
        foreach (self::STAGES as $stage) {
            if (! $flags[$stage]) break;
            $reached[] = $stage;
        }
        $current = $reached === [] ? 'none' : $reached[array_key_last($reached)];
        $nextIndex = count($reached);

        return [
            'protocol' => self::PROTOCOL,
            'stage' => $current,
            'next_stage' => self::STAGES[$nextIndex] ?? 'complete_ladder_requires_promotion_review',
            'flags' => $flags,
            'reached' => $reached,
            // Partial progress is deliberately observable even when the
            // monotonic promotion prefix is still blocked by an earlier gate.
            'observed_stages' => array_values(array_keys(array_filter(
                $flags,
                static fn ($value): bool => (bool) $value
            ))),
            'first_blocker' => collect(self::STAGES)->first(
                static fn (string $stage): bool => ! ($flags[$stage] ?? false)
            ),
            'progress_score' => round(
                count(array_filter($flags, static fn ($value): bool => (bool) $value)) / max(1, count(self::STAGES)),
                4
            ),
            'evidence_complete' => (bool) data_get($candidate, 'evidence_run_id'),
            'promotion_evidence' => false,
        ];
    }

    private function holdoutConfirmed(array $candidate): bool
    {
        foreach ([
            'holdout_confirmation', 'independent_holdout', 'forward_confirmation',
            'verified_mutation_skill', 'forward_window_protocol',
        ] as $path) {
            $value = data_get($candidate, $path);
            if ($value === true) return true;
            if (is_array($value) && in_array((string) data_get($value, 'status'), ['passed', 'confirmed', 'valid'], true)) return true;
        }

        return false;
    }
}
