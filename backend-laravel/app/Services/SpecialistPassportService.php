<?php

namespace App\Services;

use App\Models\ModelMarketPerformance;

/**
 * Builds the immutable specialist identity used by Champion Council.
 *
 * This is a projection of existing individual evidence. It never creates a
 * gate pass by itself and never grants paper, parent, mentor, or champion
 * permissions.
 */
class SpecialistPassportService
{
    public const PROTOCOL = 'specialist_passport_v1';

    /** @var array<int, string> */
    public const REGIME_ROLES = [
        'trend_up_specialist',
        'trend_down_specialist',
        'range_specialist',
    ];

    public const ROUTER_ROLE = 'transition_risk_router';

    /** @return array<string, mixed> */
    public function build(ModelMarketPerformance $candidate, array $overrides = []): array
    {
        $metadata = (array) ($candidate->modelVersion?->metadata ?? []);
        $role = (string) (
            data_get($metadata, 'council_specialist_contract.role')
            ?: data_get($metadata, 'portfolio_council_lane.specialist_role')
            ?: data_get($metadata, 'portfolio_council_lane.role')
        );
        $regime = (string) (
            data_get($metadata, 'council_specialist_contract.owner_regime')
            ?: data_get($metadata, 'portfolio_council_lane.regime')
            ?: data_get($metadata, 'portfolio_research_contract.target_regime')
            ?: data_get($candidate->metrics, 'edge_claim.target_regime', 'unproven')
        );
        $volatility = (string) (
            data_get($metadata, 'portfolio_research_contract.target_volatility')
            ?: data_get($metadata, 'portfolio_council_lane.volatility')
            ?: data_get($candidate->metrics, 'edge_claim.target_volatility', 'any')
        );
        $direction = data_get($metadata, 'portfolio_research_contract.target_direction')
            ?: data_get($candidate->metrics, 'edge_claim.target_direction');
        $direction = in_array(strtoupper((string) $direction), ['BUY', 'SELL'], true)
            ? strtoupper((string) $direction) : null;
        $session = (string) (
            data_get($metadata, 'portfolio_research_contract.target_session')
            ?: data_get($metadata, 'specialist_council_membership.contextual_cell.session')
            ?: 'any'
        );
        $venuePhase = (string) (
            data_get($metadata, 'specialist_council_membership.contextual_cell.venue_phase')
            ?: data_get($metadata, 'portfolio_research_contract.target_venue_phase')
            ?: ''
        );
        $identityEnvelope = (array) data_get($metadata, 'contextual_specialist_identity', []);
        $identity = (array) data_get($identityEnvelope, 'identity', $identityEnvelope);
        if (filled(data_get($identityEnvelope, 'identity_hash'))) {
            $identity['identity_hash'] = data_get($identityEnvelope, 'identity_hash');
        }
        $authorityEvidence = $venuePhase !== ''
            ? app(ContextualSpecialistEvidenceService::class)->forCandidate($candidate)
            : [];
        $contextualAuthority = $venuePhase !== ''
            ? app(ContextualSpecialistAuthorityService::class)->assess($authorityEvidence)
            : null;

        $niche = $this->nicheEvidence($candidate, $regime, $volatility, $direction, $session);
        $dst = $this->dstEvidence($candidate, $regime, $volatility, $direction, $session);
        $checks = [
            'role_declared' => in_array($role, [...self::REGIME_ROLES, self::ROUTER_ROLE], true),
            'individual_forward' => (bool) ($overrides['individual_forward_passed'] ?? (
                in_array((string) $candidate->status, ['forward_validated', 'paper'], true)
                && $candidate->evidence_status === 'valid'
            )),
            'individual_passport' => (bool) ($overrides['individual_passport_passed'] ?? (
                data_get($candidate->metrics, 'elite_agent_passport.status') === 'passed'
            )),
            'niche_evidence' => (bool) ($overrides['niche_evidence_passed'] ?? (
                $role === self::ROUTER_ROLE
                    || ((int) data_get($niche, 'trades', 0) >= 10
                        && (float) data_get($niche, 'net_pf', 0) >= 1.3)
            )),
            // Missing evidence must never become permission. Historical
            // candidates without an explicit non-target comparison remain
            // auditable, but cannot own a council seat.
            'no_regression' => data_get($candidate->metrics, 'no_regression_contract.status', 'missing') === 'passed',
            // London and New York change UTC offsets. A specialist which won
            // in only one offset state is a provisional calendar fit, not a
            // durable session capability. Asia has no DST and `any` is not a
            // session-specialist claim, so neither requires a two-state test.
            'dst_offset_coverage' => ! (bool) data_get($dst, 'required', false)
                || (int) data_get($dst, 'qualified_offset_state_count', 0) >= 2,
            'router_calibration' => $role !== self::ROUTER_ROLE
                || data_get($candidate->metrics, 'router_evidence.status', 'assessed') === 'assessed',
            'contextual_session_authority' => $venuePhase === ''
                || data_get($contextualAuthority, 'status') === 'contextually_confirmed_specialist',
        ];
        $failed = collect($checks)->filter(fn (bool $passed): bool => ! $passed)
            ->keys()->map(fn (string $key): string => 'FAILED_SPECIALIST_'.strtoupper($key))
            ->values()->all();

        return [
            'protocol' => self::PROTOCOL,
            'status' => $failed === [] ? 'passed' : 'failed',
            'promotion_evidence' => false,
            'candidate' => [
                'performance_id' => $candidate->id,
                'model_version_id' => $candidate->model_version_id,
                'symbol' => $candidate->symbol,
                'timeframe' => $candidate->timeframe,
            ],
            'role' => $role,
            'owner_regime' => $regime,
            'owner_volatility' => $volatility,
            'owner_direction' => $direction,
            'owner_session' => $session,
            'owner_venue_phase' => $venuePhase !== '' ? $venuePhase : null,
            'contextual_specialist_identity' => $identity !== [] ? $identity : null,
            'contextual_specialist_authority' => $contextualAuthority,
            'niche' => $niche,
            'dst_offset_evidence' => $dst,
            'checks' => $checks,
            'reason_codes' => $failed,
            'skill' => [
                'capability' => $this->capabilityFor($role),
                'stage' => $failed === [] ? 'specialist_validated' : 'apprentice',
                'mentor_eligible' => false,
            ],
            'identity_hash' => hash('sha256', json_encode([
                'protocol' => self::PROTOCOL,
                'performance_id' => $candidate->id,
                'role' => $role,
                'regime' => $regime,
                'volatility' => $volatility,
                'direction' => $direction,
                'session' => $session,
                'venue_phase' => $venuePhase,
                'contextual_identity_hash' => data_get($identity, 'identity_hash'),
            ], JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)),
        ];
    }

    /** @return array<string, mixed> */
    private function nicheEvidence(ModelMarketPerformance $candidate, string $regime, string $volatility, ?string $direction, string $session): array
    {
        $key = $regime.'|'.$volatility;
        $path = $session !== 'any' && $volatility !== 'any'
            ? "pf_attribution.breakdown.by_regime_volatility_session.{$key}.{$session}"
            : (filled($direction) && $volatility !== 'any'
                ? "pf_attribution.breakdown.by_regime_volatility_direction.{$key}.{$direction}"
                : ($volatility !== 'any'
                    ? "pf_attribution.breakdown.by_regime_volatility.{$key}"
                    : "pf_attribution.breakdown.by_regime.{$regime}"));

        return (array) data_get($candidate->metrics, $path, [
            'trades' => 0,
            'net_pf' => 0,
            'status' => 'missing',
        ]);
    }

    /** @return array<string, mixed> */
    private function dstEvidence(ModelMarketPerformance $candidate, string $regime, string $volatility, ?string $direction, string $session): array
    {
        $session = strtolower($session);
        $required = in_array($session, ['london', 'new_york', 'overlap'], true);
        $groups = [];

        foreach ((array) data_get($candidate->metrics, 'robustness_matrix.dst_envelopes', []) as $key => $row) {
            $parts = explode('|', (string) $key, 5);
            if (count($parts) !== 5
                || $parts[0] !== $regime
                || $parts[1] !== $volatility
                || strtolower($parts[2]) !== $session
                || (filled($direction) && strtoupper($parts[3]) !== $direction)) {
                continue;
            }

            $offset = $parts[4];
            $groups[$offset] ??= [
                'offset_state' => $offset,
                'trades' => 0,
                'worst_net_pf' => INF,
                'source_contexts' => [],
            ];
            $groups[$offset]['trades'] += (int) data_get($row, 'trades', 0);
            $groups[$offset]['worst_net_pf'] = min(
                (float) $groups[$offset]['worst_net_pf'],
                (float) data_get($row, 'net_pf', 0),
            );
            $groups[$offset]['source_contexts'][] = (string) $key;
        }

        $states = collect($groups)->map(function (array $row): array {
            $row['worst_net_pf'] = is_finite((float) $row['worst_net_pf'])
                ? (float) $row['worst_net_pf'] : 0.0;
            $row['qualified'] = (int) $row['trades'] >= 3
                && (float) $row['worst_net_pf'] >= 1.0;

            return $row;
        })->sortKeys()->values();

        return [
            'protocol' => 'specialist_dst_offset_evidence_v1',
            'required' => $required,
            'minimum_independent_offset_states' => $required ? 2 : 0,
            'minimum_trades_per_state' => 3,
            'minimum_worst_net_pf_per_state' => 1.0,
            'observed_offset_state_count' => $states->count(),
            'qualified_offset_state_count' => $states->where('qualified', true)->count(),
            'states' => $states->all(),
            'status' => ! $required || $states->where('qualified', true)->count() >= 2
                ? 'passed' : 'insufficient',
        ];
    }

    private function capabilityFor(string $role): string
    {
        return match ($role) {
            'trend_up_specialist' => 'trend_up_regime_ownership',
            'trend_down_specialist' => 'trend_down_regime_ownership',
            'range_specialist' => 'range_regime_ownership',
            self::ROUTER_ROLE => 'transition_risk_routing_and_abstention',
            default => 'unclassified_specialist_research',
        };
    }
}
