<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\LabGeneration;

/**
 * Single fail-closed admission contract between population construction and
 * every screening dispatcher.
 */
class GenerationConstructionAdmissionService
{
    /** @return array<string, mixed> */
    public function inspect(LabGeneration $generation): array
    {
        $generation = $generation->fresh(['agents.modelVersion']);
        $context = (array) ($generation->trigger_context ?? []);
        $plan = array_values((array) data_get($context, 'generation_plan', []));

        // Historical generations predate the immutable plan. Their existing
        // lifecycle remains readable/resumable, but an explicit technical
        // quarantine is never dispatchable.
        if ($plan === []) {
            $allowed = (string) $generation->status !== 'technical_quarantine';

            return [
                'protocol' => 'generation_construction_admission_v1',
                'allowed' => $allowed,
                'reason_codes' => $allowed ? [] : ['GENERATION_TECHNICAL_QUARANTINE'],
                'legacy_plan' => true,
                'promotion_evidence' => false,
            ];
        }

        $plannedSlots = count($plan);
        $actualAgents = $generation->agents->count();
        $slotPattern = '/_g'.preg_quote((string) $generation->generation, '/').'_a(\d+)$/';
        $completedSlots = $generation->agents
            ->map(fn (LabAgent $agent): ?int => preg_match($slotPattern, (string) $agent->modelVersion?->strategy, $match) === 1
                ? (int) $match[1]
                : null)
            ->filter(fn (?int $slot): bool => $slot !== null)
            ->unique()
            ->values();
        $auditPlanned = (int) data_get($context, 'constructor_audit.planned_slots', 0);
        $auditCreated = (int) data_get($context, 'constructor_audit.created_agents', 0);
        $lineageAllowed = data_get($context, 'lineage_continuation_contract.allowed');
        $abortKeys = collect([
            'constructor_contract_abort',
            'shadow_research_constructor_abort',
            'controlled_rescue_constructor_abort',
        ])->filter(fn (string $key): bool => is_array(data_get($context, $key)))->values()->all();

        $reasons = [];
        if ((string) $generation->status === 'technical_quarantine') {
            $reasons[] = 'GENERATION_TECHNICAL_QUARANTINE';
        }
        if ($actualAgents !== $plannedSlots) {
            $reasons[] = 'POPULATION_COUNT_MISMATCH';
        }
        if ($completedSlots->count() !== $plannedSlots) {
            $reasons[] = 'PLANNED_SLOT_COVERAGE_INCOMPLETE';
        }
        if ($generation->agents->contains(fn (LabAgent $agent): bool => ! $agent->model_version_id)) {
            $reasons[] = 'MODEL_VERSION_COVERAGE_INCOMPLETE';
        }
        if ($auditPlanned !== $plannedSlots || $auditCreated !== $plannedSlots) {
            $reasons[] = 'CONSTRUCTOR_AUDIT_INCOMPLETE';
        }
        if ($abortKeys !== []) {
            $reasons[] = 'CONSTRUCTOR_ABORT_ACTIVE';
        }
        if ($lineageAllowed !== true) {
            $reasons[] = 'LINEAGE_CONTINUATION_NOT_ADMITTED';
        }
        $selectorProtocol = (string) data_get(
            $context,
            'adaptive_evolution_policy.causal_learning_counterfactual_cohort.blinded_selector.protocol',
            '',
        );
        $immutableContract = app(ImmutableGenerationContractService::class)->validate($generation);
        if ($selectorProtocol !== '' && $selectorProtocol !== CausalBlindedMutationSelectorService::PROTOCOL) {
            $reasons[] = 'CAUSAL_SELECTOR_PROTOCOL_SUPERSEDED';
        }
        if (! (bool) ($immutableContract['valid'] ?? false)) {
            $reasons = [
                ...$reasons,
                ...(array) ($immutableContract['reason_codes'] ?? ['IMMUTABLE_GENERATION_CONTRACT_INVALID']),
            ];
        }

        return [
            'protocol' => 'generation_construction_admission_v1',
            'allowed' => $reasons === [],
            'reason_codes' => array_values(array_unique($reasons)),
            'generation_id' => (int) $generation->id,
            'generation' => (int) $generation->generation,
            'planned_slots' => $plannedSlots,
            'actual_agents' => $actualAgents,
            'completed_slots' => $completedSlots->all(),
            'constructor_abort_keys' => $abortKeys,
            'lineage_allowed' => $lineageAllowed === true,
            'causal_selector_protocol' => $selectorProtocol !== '' ? $selectorProtocol : null,
            'required_causal_selector_protocol' => CausalBlindedMutationSelectorService::PROTOCOL,
            'immutable_generation_contract' => $immutableContract,
            'promotion_evidence' => false,
        ];
    }
}
