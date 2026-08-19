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

        $niche = $this->nicheEvidence($candidate, $regime, $volatility, $direction);
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
            'no_regression' => data_get($candidate->metrics, 'no_regression_contract.status', 'passed') === 'passed',
            'router_calibration' => $role !== self::ROUTER_ROLE
                || data_get($candidate->metrics, 'router_evidence.status', 'assessed') === 'assessed',
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
            'niche' => $niche,
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
            ], JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)),
        ];
    }

    /** @return array<string, mixed> */
    private function nicheEvidence(ModelMarketPerformance $candidate, string $regime, string $volatility, ?string $direction): array
    {
        $key = $regime.'|'.$volatility;
        $path = filled($direction) && $volatility !== 'any'
            ? "pf_attribution.breakdown.by_regime_volatility_direction.{$key}.{$direction}"
            : ($volatility !== 'any'
                ? "pf_attribution.breakdown.by_regime_volatility.{$key}"
                : "pf_attribution.breakdown.by_regime.{$regime}");

        return (array) data_get($candidate->metrics, $path, [
            'trades' => 0,
            'net_pf' => 0,
            'status' => 'missing',
        ]);
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
