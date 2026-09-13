<?php

namespace App\Services;

/**
 * Pure causal admission and conservative routing for session specialists.
 * Labels are ignored; every authority bit is re-derived from sealed evidence.
 */
class ContextualSpecialistAuthorityService
{
    public const PROTOCOL = 'contextual_specialist_authority_v1';

    /** @return array<string, mixed> */
    public function assess(array $evidence): array
    {
        $phase = strtolower((string) data_get($evidence, 'identity.venue_phase', data_get($evidence, 'venue_phase', '')));
        $windows = (array) data_get($evidence, 'chronological_windows', []);
        $qualifiedWindows = collect($windows)->filter(fn (array $row): bool =>
            (bool) data_get($row, 'candidate_better_than_control', false)
            && (float) data_get($row, 'absolute_settlement', 0) > 0
        )->count();
        $candidateInstances = array_values(array_filter(array_map('strval', (array) data_get($evidence, 'candidate_session_instance_ids', []))));
        $controlInstances = array_values(array_filter(array_map('strval', (array) data_get($evidence, 'control_session_instance_ids', []))));
        $dstRequired = str_starts_with($phase, 'london_') || str_starts_with($phase, 'comex_') || $phase === 'london_comex_overlap';
        $offsetStates = array_values(array_unique(array_filter(array_map('strval', (array) data_get($evidence, 'qualified_dst_offset_states', [])))));
        $identity = (array) data_get($evidence, 'identity', []);
        $requiredIdentityAxes = ['regime', 'venue_phase', 'volatility', 'spread_liquidity', 'transition_state', 'direction'];
        $identityComplete = collect($requiredIdentityAxes)->every(
            fn (string $axis): bool => filled(data_get($identity, $axis)),
        );
        $checks = [
            'screening_passed' => data_get($evidence, 'screening_status') === 'passed',
            'full_replay_passed' => data_get($evidence, 'full_replay_status') === 'passed',
            'exact_frozen_control' => (bool) data_get($evidence, 'exact_frozen_control', false),
            'same_session_instances' => $candidateInstances !== [] && $candidateInstances === $controlInstances,
            'frozen_control_superiority' => (bool) data_get($evidence, 'frozen_control_superiority', false),
            'positive_absolute_settlement' => (float) data_get($evidence, 'absolute_settlement', 0) > 0,
            'independent_chronological_replication' => $qualifiedWindows >= 2,
            'dst_offset_coverage' => ! $dstRequired || count($offsetStates) >= 2,
            'spread_cost_stress' => data_get($evidence, 'spread_cost_stress_status') === 'passed',
            'local_positive_posterior' => data_get($evidence, 'local_positive_posterior_status') === 'passed',
            'multiple_testing_control' => data_get($evidence, 'multiple_testing_validation_status') === 'passed',
            'no_other_session_harm' => data_get($evidence, 'other_session_regression_status') === 'passed',
            'outside_scope_abstention' => (int) data_get($evidence, 'outside_scope_activation_count', -1) === 0,
            'context_identity_sealed' => $identityComplete
                && filled(data_get($evidence, 'identity.identity_hash', data_get($evidence, 'identity_hash'))),
        ];
        $failed = collect($checks)->filter(fn (bool $passed): bool => ! $passed)->keys()->values()->all();

        return [
            'protocol' => self::PROTOCOL,
            'status' => $failed === [] ? 'contextually_confirmed_specialist' : 'research_specialist_only',
            'authority_scope' => $phase,
            'checks' => $checks,
            'failed_checks' => $failed,
            'qualified_window_count' => $qualifiedWindows,
            'qualified_dst_offset_states' => $offsetStates,
            'local_evidence_grants_global_inheritance' => false,
            'outside_scope_action' => 'WAIT',
            'promotion_evidence' => $failed === [],
        ];
    }

    /** @return array<string, mixed> */
    public function route(array $context, array $specialists, ?array $baseline = null): array
    {
        $eligible = collect($specialists)->filter(function (array $specialist) use ($context): bool {
            $assessment = $this->assess((array) data_get($specialist, 'evidence', $specialist));
            if ($assessment['status'] !== 'contextually_confirmed_specialist' || (bool) data_get($specialist, 'risk_veto', false)) {
                return false;
            }
            $identity = (array) data_get($specialist, 'evidence.identity', data_get($specialist, 'identity', []));
            foreach (['regime', 'venue_phase', 'volatility', 'spread_liquidity', 'transition_state', 'direction'] as $axis) {
                $owned = data_get($identity, $axis);
                $observed = data_get($context, $axis);
                if (filled($owned) && filled($observed) && strtolower((string) $owned) !== strtolower((string) $observed)) {
                    return false;
                }
                if (filled($owned) && ! filled($observed)) {
                    return false;
                }
            }

            return true;
        })->sortByDesc(fn (array $row): float => (float) data_get($row, 'lower_confidence_bound', data_get($row, 'evidence.lower_confidence_bound', -INF)));

        if ($eligible->isNotEmpty()) {
            $winner = (array) $eligible->first();
            return [
                'protocol' => self::PROTOCOL, 'action' => 'SPECIALIST',
                'specialist_id' => data_get($winner, 'id'),
                'identity_hash' => data_get($winner, 'evidence.identity.identity_hash', data_get($winner, 'identity.identity_hash')),
                'reason' => 'exact_context_confirmed_highest_lower_confidence_bound',
            ];
        }
        if (is_array($baseline) && (bool) data_get($baseline, 'eligible', false)) {
            return ['protocol' => self::PROTOCOL, 'action' => 'BASELINE', 'specialist_id' => null, 'reason' => 'no_exact_confirmed_specialist'];
        }

        return ['protocol' => self::PROTOCOL, 'action' => 'WAIT', 'specialist_id' => null, 'reason' => 'uncertain_or_outside_owned_context'];
    }
}
