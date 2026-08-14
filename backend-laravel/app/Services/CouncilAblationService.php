<?php

namespace App\Services;

use App\Models\LabCouncilAblationRun;
use Illuminate\Support\Facades\Schema;

/**
 * Builds and evaluates leave-one-out council tests.
 *
 * A council's aggregate PF is not enough to identify skill contribution. The
 * full route and every required specialist exclusion share the same snapshot
 * and execution contract. This service remains research-only.
 */
class CouncilAblationService
{
    public const PROTOCOL = 'council_leave_one_out_ablation_v1';

    /** @return array<string, mixed> */
    public function contract(array $members, array $context = []): array
    {
        $roles = array_values(array_unique(array_filter(array_map(
            static fn (array $member): string => (string) data_get($member, 'role', data_get($member, 'council_role', '')),
            $members,
        ))));
        // The runtime council uses concrete specialist role names (for
        // example trend_up_specialist). Every declared member is therefore a
        // required leave-one-out seat; the config list remains a semantic
        // grouping hint, not a reason to silently skip a concrete role.
        $requiredRoles = $roles !== []
            ? $roles
            : array_values((array) config('services.lab_selection.council_ablation_roles', ['entry', 'risk', 'regime', 'volume_temporal']));
        $councilKey = hash('sha256', json_encode([
            'protocol' => self::PROTOCOL,
            'members' => $members,
            'context' => $context,
        ], JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES));
        $contextKey = hash('sha256', json_encode($this->contextIdentity($context), JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES));
        $plans = [];
        foreach ($roles as $role) {
            $member = collect($members)->first(fn (array $item): bool => (string) data_get($item, 'role', data_get($item, 'council_role', '')) === $role);
            $plans[] = [
                'member_role' => $role,
                'excluded_member_model_version_id' => data_get($member, 'model_version_id'),
                'required' => in_array($role, $requiredRoles, true),
                'same_snapshot_required' => true,
                'same_execution_contract_required' => true,
            ];
        }
        $rows = $this->rows($councilKey, $contextKey);
        $requiredPlans = collect($plans)->filter(fn (array $plan): bool => (bool) $plan['required']);
        $completed = $requiredPlans->every(function (array $plan) use ($rows): bool {
            $row = $rows->first(fn (LabCouncilAblationRun $candidate): bool => $candidate->member_role === $plan['member_role']);
            return $row?->status === 'completed';
        });

        return [
            'protocol' => self::PROTOCOL,
            'status' => $completed ? 'completed' : 'planned',
            'council_key' => $councilKey,
            'context_key' => $contextKey,
            'roles' => $roles,
            'required_roles' => $requiredRoles,
            'plans' => $plans,
            'completed_roles' => $rows->where('status', 'completed')->pluck('member_role')->values()->all(),
            'missing_roles' => $requiredPlans->reject(fn (array $plan): bool => in_array($plan['member_role'], $rows->where('status', 'completed')->pluck('member_role')->all(), true))->pluck('member_role')->values()->all(),
            'official_proxy_eligible' => $completed,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function plan(array $members, array $context = []): array
    {
        $contract = $this->contract($members, $context);
        if (! $this->available()) return [...$contract, 'status' => 'migration_pending'];
        foreach ((array) $contract['plans'] as $plan) {
            if (! (bool) data_get($plan, 'required', false)) continue;
            LabCouncilAblationRun::query()->firstOrCreate(
                [
                    'council_key' => $contract['council_key'],
                    'member_role' => $plan['member_role'],
                    'excluded_member_model_version_id' => data_get($plan, 'excluded_member_model_version_id'),
                    'context_key' => $contract['context_key'],
                ],
                [
                    'symbol' => strtoupper((string) data_get($context, 'symbol', 'XAUUSD')),
                    'timeframe' => strtoupper((string) data_get($context, 'timeframe', 'H1')),
                    'snapshot_hash' => data_get($context, 'snapshot_hash', data_get($context, 'data_hash')),
                    'execution_hash' => data_get($context, 'execution_hash'),
                    'status' => 'planned',
                    'payload' => [
                        'protocol' => self::PROTOCOL,
                        'plan' => $plan,
                        'same_snapshot_required' => true,
                        'same_execution_contract_required' => true,
                        'promotion_evidence' => false,
                    ],
                    'promotion_evidence' => false,
                ],
            );
        }
        return $this->contract($members, $context);
    }

    /** @return array<string, mixed> */
    public function record(
        string $councilKey,
        string $contextKey,
        string $role,
        array $metrics,
        array $context = [],
    ): array {
        if (! $this->available()) return ['protocol' => self::PROTOCOL, 'status' => 'migration_pending', 'promotion_evidence' => false];
        $row = LabCouncilAblationRun::query()
            ->where('council_key', $councilKey)
            ->where('context_key', $contextKey)
            ->where('member_role', $role)
            ->first();
        if (! $row) return ['protocol' => self::PROTOCOL, 'status' => 'plan_missing', 'promotion_evidence' => false];
        $full = $this->score((array) data_get($metrics, 'full_council', []));
        $without = $this->score((array) data_get($metrics, 'without_member', []));
        $delta = $full !== null && $without !== null ? $full - $without : null;
        $row->update([
            'status' => $full !== null && $without !== null ? 'completed' : 'invalid',
            'incremental_delta' => $delta,
            'snapshot_hash' => data_get($context, 'snapshot_hash', data_get($context, 'data_hash', $row->snapshot_hash)),
            'execution_hash' => data_get($context, 'execution_hash', $row->execution_hash),
            'evidence_run_id' => data_get($context, 'evidence_run_id'),
            'metrics' => $metrics,
            'payload' => [
                ...((array) $row->payload),
                'context' => $context,
                'promotion_evidence' => false,
            ],
            'completed_at' => $full !== null && $without !== null ? now()->utc() : null,
        ]);
        return [
            'protocol' => self::PROTOCOL,
            'status' => $row->status,
            'member_role' => $role,
            'incremental_delta' => $delta,
            'promotion_evidence' => false,
        ];
    }

    private function rows(string $councilKey, string $contextKey)
    {
        if (! $this->available()) return collect();
        return LabCouncilAblationRun::query()
            ->where('council_key', $councilKey)
            ->where('context_key', $contextKey)
            ->get();
    }

    private function score(array $metrics): ?float
    {
        foreach (['forward_score', 'profit_factor', 'net_profit_percent'] as $key) {
            if (is_numeric($metrics[$key] ?? null)) return (float) $metrics[$key];
        }
        return null;
    }

    private function contextIdentity(array $context): array
    {
        return [
            'symbol' => strtoupper((string) data_get($context, 'symbol', 'XAUUSD')),
            'timeframe' => strtoupper((string) data_get($context, 'timeframe', 'H1')),
            'data_hash' => data_get($context, 'data_hash', data_get($context, 'snapshot_hash')),
            'execution_hash' => data_get($context, 'execution_hash'),
        ];
    }

    private function available(): bool
    {
        try {
            return Schema::hasTable('lab_council_ablation_runs');
        } catch (\Throwable) {
            return false;
        }
    }
}
