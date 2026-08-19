<?php

namespace App\Services;

/**
 * Measures whether a set of individually valid specialists is a council.
 * High PF alone is never sufficient; role coverage and non-duplication are
 * hard structural conditions, while combined evidence proves synergy.
 */
class CouncilCompatibilityService
{
    public const PROTOCOL = 'champion_council_compatibility_v1';

    /** @param array<int, mixed> $members */
    /** @return array<string, mixed> */
    public function assess(array $members): array
    {
        $roles = collect($members)->map(fn ($member): string => (string) (
            data_get($member, 'role')
            ?: data_get($member, 'council_role')
            ?: data_get($member, 'modelVersion.metadata.council_specialist_contract.role', '')
        ))->filter()->values();
        $regimes = collect($members)->filter(fn ($member): bool => $this->isRegimeRole($this->role($member)))
            ->map(fn ($member): string => $this->regime($member))
            ->filter()->unique()->values();
        $niches = collect($members)->map(fn ($member): string => implode('|', [
            $this->role($member),
            $this->regime($member) ?: 'any',
            (string) (data_get($member, 'target_volatility') ?: data_get($member, 'owner_volatility', data_get($member, 'volatility', data_get($member, 'modelVersion.metadata.portfolio_research_contract.target_volatility', 'any')))),
            (string) data_get($member, 'target_direction', data_get($member, 'direction', 'any')),
        ]));
        $duplicateNiches = $niches->duplicates()->values()->all();
        $reasons = [];
        if ($regimes->count() < (int) $this->setting('services.lab_selection.council_min_regime_specialists', 2)) {
            $reasons[] = 'COUNCIL_NEEDS_TWO_DISTINCT_REGIME_SPECIALISTS';
        }
        if (! $roles->contains(SpecialistPassportService::ROUTER_ROLE)) {
            $reasons[] = 'COUNCIL_NEEDS_TRANSITION_RISK_ROUTER';
        }
        if ($duplicateNiches !== []) $reasons[] = 'COUNCIL_HAS_DUPLICATE_NICHE';
        $max = (int) $this->setting('services.lab_selection.council_max_members', 6);
        if ($roles->count() > $max) $reasons[] = 'COUNCIL_MEMBER_LIMIT_EXCEEDED';

        return [
            'protocol' => self::PROTOCOL,
            'status' => $reasons === [] ? 'compatible' : 'incompatible',
            'promotion_evidence' => false,
            'roles' => $roles->unique()->values()->all(),
            'regimes' => $regimes->all(),
            'member_count' => count($members),
            'duplicate_niches' => $duplicateNiches,
            'reason_codes' => $reasons,
            'objective' => 'individual_quality + coverage + complementarity + council_synergy - tail_risk - switching_instability',
        ];
    }

    /** @param array<int, mixed> $members */
    /** @return array<string, mixed> */
    public function combinedEvidence(array $members, array $result): array
    {
        $structure = $this->assess($members);
        $portfolio = (array) data_get($result, 'portfolio_evidence', []);
        $leaveOneOut = (float) data_get($portfolio, 'leave_one_member_out.minimum_profit_factor', 0);
        $correlation = (float) data_get($portfolio, 'loss_correlation.max_jaccard', 1);
        $switchRate = (float) data_get($portfolio, 'router_stability.switch_rate', 1);
        $contributionCap = (float) data_get($portfolio, 'member_contribution.max_positive_share', 1);
        $checks = [
            'structure' => $structure['status'] === 'compatible',
            'leave_one_out' => $leaveOneOut >= 1.0,
            'loss_correlation' => $correlation <= .50,
            'router_stability' => $switchRate <= .25,
            'contribution_cap' => $contributionCap <= .65,
        ];
        $bestMember = data_get($portfolio, 'best_member_score');
        $combined = data_get($portfolio, 'combined_score', data_get($result, 'forward_score'));
        $synergyDelta = is_numeric($bestMember) && is_numeric($combined)
            ? (float) $combined - (float) $bestMember : null;

        return [
            'protocol' => self::PROTOCOL,
            'status' => collect($checks)->every(fn (bool $passed): bool => $passed) ? 'assessed_passed' : 'failed',
            'promotion_evidence' => false,
            'checks' => $checks,
            'synergy_delta' => $synergyDelta,
            'leave_one_out_minimum_profit_factor' => $leaveOneOut,
            'loss_correlation_max_jaccard' => $correlation,
            'router_switch_rate' => $switchRate,
            'member_contribution_cap' => $contributionCap,
            'structure' => $structure,
        ];
    }

    private function isRegimeRole(string $role): bool
    {
        return in_array($role, SpecialistPassportService::REGIME_ROLES, true);
    }

    private function role(mixed $member): string
    {
        return (string) (data_get($member, 'role')
            ?: data_get($member, 'council_role')
            ?: data_get($member, 'modelVersion.metadata.council_specialist_contract.role')
            ?: data_get($member, 'modelVersion.metadata.portfolio_council_lane.specialist_role'));
    }

    private function regime(mixed $member): string
    {
        return (string) (data_get($member, 'target_regime')
            ?: data_get($member, 'owner_regime')
            ?: data_get($member, 'regime')
            ?: data_get($member, 'modelVersion.metadata.council_specialist_contract.owner_regime')
            ?: data_get($member, 'modelVersion.metadata.portfolio_research_contract.target_regime')
            ?: data_get($member, 'modelVersion.metadata.portfolio_council_lane.regime'));
    }

    private function setting(string $key, mixed $default): mixed
    {
        try {
            return function_exists('app') && app()->bound('config') ? config($key, $default) : $default;
        } catch (\Throwable) {
            return $default;
        }
    }
}
