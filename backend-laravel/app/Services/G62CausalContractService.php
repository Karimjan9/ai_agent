<?php

namespace App\Services;

use App\Models\LabGeneration;

/** Produces a non-mutating repair contract for an immutable G62 quarantine. */
class G62CausalContractService
{
    public const PROTOCOL = 'g62_causal_contract_repair_v1';

    /** @return array<string, mixed> */
    public function audit(LabGeneration $generation): array
    {
        $generation->loadMissing('laboratory');
        $context = (array) $generation->trigger_context;
        $source = (array) data_get($context, 'control_pairing_contract', []);
        $plan = collect((array) data_get($context, 'generation_plan', []))
            ->filter(fn ($seat): bool => is_array($seat) && (string) data_get($seat, 'family') === 'differential_router')
            ->values()->all();
        $candidateIndex = collect($plan)->keys()->first(fn ($index): bool => !(bool) data_get($plan[$index], 'niche.control_only', false));
        $controlIndex = collect($plan)->keys()->first(fn ($index): bool => $index !== $candidateIndex);
        $repairPlan = $plan;
        if ($candidateIndex !== null) {
            $repairPlan[$candidateIndex]['niche'] = [
                ...((array) data_get($repairPlan[$candidateIndex], 'niche', [])),
                'role' => 'volume_m15_specialist', 'specialist_role' => 'volume_m15_specialist',
                'data_lane' => 'volume', 'volume_shadow' => true,
                'shadow_mutation_gene' => 'volume_lane', 'control_only' => false,
            ];
        }
        if ($controlIndex !== null) {
            $repairPlan[$controlIndex]['niche'] = [
                ...((array) data_get($repairPlan[$controlIndex], 'niche', [])),
                'role' => 'frozen_control', 'specialist_role' => 'frozen_control',
                'control_only' => true, 'data_lane' => 'volume', 'control_lane' => 'volume',
            ];
        }
        $materialized = app(ResearchAllocationPolicyService::class)->materializeNormalControlPairing(
            $repairPlan,
            (string) $generation->laboratory?->symbol,
            (string) $generation->laboratory?->timeframe,
            (int) $generation->id,
        );
        $contract = (array) data_get($materialized, 'contract', []);
        $desired = collect((array) data_get($contract, 'required_execution_lanes', []))
            ->first(fn (array $row): bool => ($row['key'] ?? '') === 'volume|differential_router');
        $pairingReady = $desired !== null
            && (int) data_get($contract, 'candidate_counts.volume|differential_router', 0) >= 1
            && collect((array) data_get($contract, 'materialized_controls', []))->contains(fn (array $row): bool => ($row['execution_lane'] ?? '') === 'volume' && ($row['family'] ?? '') === 'differential_router');

        return [
            'protocol' => self::PROTOCOL,
            'source_generation' => (int) $generation->generation,
            'source_generation_id' => (int) $generation->id,
            'source_status' => (string) $generation->status,
            'source_contract_allowed' => (bool) data_get($source, 'allowed', false),
            'source_missing_candidate_pairs' => (array) data_get($source, 'missing_candidate_pairs', []),
            'repair' => [
                'pair' => 'volume|differential_router',
                'candidate_control_same_generation' => true,
                'candidate_gene' => 'volume_lane',
                'immutable_source_preserved' => true,
                'materialization' => 'new_controlled_rescue_generation_only',
            ],
            'corrected_contract' => [
                'allowed' => $pairingReady,
                'required_pair' => $desired,
                'candidate_count' => (int) data_get($contract, 'candidate_counts.volume|differential_router', 0),
                'control_count' => collect((array) data_get($contract, 'materialized_controls', []))->where('execution_lane', 'volume')->where('family', 'differential_router')->count(),
                'missing_candidate_pairs' => (array) data_get($contract, 'missing_candidate_pairs', []),
                'volume_pair_repair' => data_get($contract, 'volume_pair_repair'),
            ],
            'dispatch_action' => $pairingReady ? 'operator_approval_required_for_controlled_rescue_apply' : 'repair_contract_invalid',
            'promotion_evidence' => false,
        ];
    }
}
