<?php

namespace App\Services;

/**
 * Contract for a research-only council assembled before individual specialist
 * passports exist. It lets the system test skill compatibility without
 * accidentally treating a composite score as a deployable champion.
 */
class MtfShadowCouncilSandboxService
{
    public const PROTOCOL = 'mtf_shadow_council_sandbox_v1';

    /** @param list<array<string, mixed>> $members */
    public function contract(array $members, array $context = []): array
    {
        $roles = collect($members)
            ->map(fn (array $member): string => (string) ($member['role'] ?? $member['specialist_role'] ?? 'unknown'))
            ->filter(fn (string $role): bool => $role !== '' && $role !== 'unknown')
            ->unique()
            ->values()
            ->all();
        $required = ['pf_entry', 'cost_exit', 'regime', 'temporal_volume', 'risk'];
        $roleAliases = [
            'pf_entry' => ['pf_entry', 'edge_quality_specialist', 'pf_specialist'],
            'cost_exit' => ['cost_exit', 'cost_stability_specialist', 'stress_specialist'],
            'regime' => ['regime', 'regime_coverage_specialist', 'regime_specialist'],
            'temporal_volume' => ['temporal_volume', 'temporal_stability_specialist', 'volume_specialist'],
            'risk' => ['risk', 'risk_specialist', 'drawdown_specialist'],
        ];
        $missing = collect($roleAliases)->filter(function (array $aliases) use ($roles): bool {
            return collect($roles)->intersect($aliases)->isEmpty();
        })->keys()->values()->all();

        return [
            'protocol' => self::PROTOCOL,
            'status' => 'research_only',
            'member_count' => count($members),
            'member_roles' => $roles,
            'required_skill_roles' => $required,
            'missing_skill_roles' => $missing,
            'context' => $context,
            'member_isolation' => 'Each specialist retains its own hypothesis, parameter diff and passport boundary.',
            'combined_proxy_rule' => 'Only individually validated specialist passports may enter combined full replay.',
            'combined_proxy_eligible' => false,
            'official_paper_eligible' => false,
            'promotion_evidence' => false,
        ];
    }
}
