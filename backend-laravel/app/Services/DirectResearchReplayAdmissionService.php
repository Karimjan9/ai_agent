<?php

namespace App\Services;

use App\Models\LabAgent;
use Illuminate\Support\Facades\DB;

/**
 * Admission boundary for research cohorts that are born directly into a
 * frozen full replay. They do not bypass evidence: their own immutable
 * ledger/hash contract replaces ordinary screening, and none of these lanes
 * can create paper, champion or parent authority by itself.
 */
class DirectResearchReplayAdmissionService
{
    /** @return array{applicable:bool,allowed:bool,reason_codes:array<int,string>,protocol:string,promotion_evidence:false} */
    public function inspect(LabAgent $agent): array
    {
        $agent->loadMissing('modelVersion', 'generation');
        $metadata = (array) ($agent->modelVersion?->metadata ?? []);

        if (data_get($metadata, 'edge_genesis.protocol') === DependencyAwareEdgeGenesisFoundryService::PROTOCOL) {
            return $this->edge($agent, $metadata);
        }
        if (data_get($metadata, 'authority_incubator.protocol') === EvolutionaryAuthorityFoundryService::PROTOCOL) {
            return $this->incubator($agent, $metadata);
        }
        if (data_get($metadata, 'authority_descendant.protocol') === EvolutionaryAuthorityFoundryService::PROTOCOL) {
            return $this->descendant($agent, $metadata);
        }
        if (data_get($metadata, 'skill_cartridge_transplant.protocol') === CanonicalSkillCartridgeService::PROTOCOL) {
            return $this->transplant($agent, $metadata);
        }
        if (data_get($metadata, 'skill_cartridge_interaction.protocol') === CanonicalSkillCartridgeService::PROTOCOL) {
            return $this->interaction($agent, $metadata);
        }

        return $this->result(false, false, []);
    }

    private function edge(LabAgent $agent, array $metadata): array
    {
        $reasons = [];
        $preflight = app(DependencyAwareEdgeGenesisFoundryService::class)->preflight($agent);
        if (! (bool) data_get($preflight, 'allowed', false)) {
            $reasons[] = (string) data_get($preflight, 'status', 'EDGE_PREFLIGHT_FAILED');
        }
        if (! in_array((string) $agent->generation?->trigger_type, ['edge_genesis', 'edge_component_attribution'], true)) {
            $reasons[] = 'EDGE_GENERATION_SCOPE_MISMATCH';
        }
        $trial = DB::table('edge_genesis_trials')->where('lab_agent_id', $agent->id)
            ->where('model_version_id', $agent->model_version_id)->first();
        if (! $trial) {
            return $this->result(true, false, [...$reasons, 'EDGE_TRIAL_LEDGER_MISSING']);
        }
        $passport = DB::table('edge_genesis_passports')->where('id', $trial->edge_genesis_passport_id)->first();
        if (! $passport) {
            $reasons[] = 'EDGE_PASSPORT_MISSING';
        }
        $contract = (array) data_get($metadata, data_get($metadata, 'edge_genesis_attribution.protocol') === DependencyAwareEdgeGenesisFoundryService::PROTOCOL
            ? 'edge_genesis_attribution' : 'edge_genesis', []);
        if ($passport && ((string) data_get($contract, 'data_hash') === ''
            || ! hash_equals((string) $passport->data_hash, (string) data_get($contract, 'data_hash')))) {
            $reasons[] = 'EDGE_DATA_HASH_MISMATCH';
        }
        if ($passport && ((string) data_get($contract, 'execution_hash') === ''
            || ! hash_equals((string) $passport->execution_hash, (string) data_get($contract, 'execution_hash')))) {
            $reasons[] = 'EDGE_EXECUTION_HASH_MISMATCH';
        }
        if (! in_array((string) $trial->status, ['queued', 'running', 'edge_progressing'], true)) {
            $reasons[] = 'EDGE_TRIAL_NOT_REPLAYABLE';
        }

        return $this->result(true, $reasons === [], $reasons);
    }

    private function incubator(LabAgent $agent, array $metadata): array
    {
        $contract = (array) data_get($metadata, 'authority_incubator', []);
        $reasons = [];
        if ($agent->generation?->trigger_type !== 'authority_incubator') {
            $reasons[] = 'INCUBATOR_GENERATION_SCOPE_MISMATCH';
        }
        if (! in_array((string) data_get($contract, 'arm'), EvolutionaryAuthorityFoundryService::INCUBATOR_ARMS, true)) {
            $reasons[] = 'INCUBATOR_ARM_INVALID';
        }
        if (! filled(data_get($contract, 'data_hash')) || ! filled(data_get($contract, 'execution_hash'))) {
            $reasons[] = 'INCUBATOR_FROZEN_HASHES_MISSING';
        }
        $capsule = (array) data_get($contract, 'trait_capsule', []);
        $capsuleAssessment = app(ContextualCausalTraitCapsuleService::class)->assess(
            $capsule,
            (string) data_get($contract, 'confirmed_gene', ''),
        );
        if (! (bool) data_get($capsuleAssessment, 'valid', false)
            || ! filled(data_get($contract, 'trait_capsule_hash'))
            || ! hash_equals((string) data_get($contract, 'trait_capsule_hash'), (string) data_get($capsule, 'capsule_hash', ''))) {
            $reasons[] = 'INCUBATOR_CONTEXTUAL_TRAIT_CAPSULE_INVALID';
        }
        $exists = DB::table('skill_incubation_trials')->where('child_model_version_id', $agent->model_version_id)
            ->where('arm', data_get($contract, 'arm'))->exists();
        if (! $exists) {
            $reasons[] = 'INCUBATOR_TRIAL_LEDGER_MISSING';
        }

        return $this->result(true, $reasons === [], $reasons);
    }

    private function transplant(LabAgent $agent, array $metadata): array
    {
        $contract = (array) data_get($metadata, 'skill_cartridge_transplant', []);
        $reasons = [];
        if ($agent->generation?->trigger_type !== 'skill_cartridge_transplant') {
            $reasons[] = 'CARTRIDGE_GENERATION_SCOPE_MISMATCH';
        }
        if (! filled(data_get($contract, 'data_hash')) || ! filled(data_get($contract, 'execution_hash'))) {
            $reasons[] = 'CARTRIDGE_FROZEN_HASHES_MISSING';
        }
        $trial = DB::table('skill_cartridge_transplant_trials')->where('child_model_version_id', $agent->model_version_id)->first();
        if (! $trial || ! in_array((string) $trial->status, ['queued', 'running'], true)) {
            $reasons[] = 'CARTRIDGE_TRIAL_NOT_REPLAYABLE';
        }
        $confirmation = (array) data_get($contract, 'confirmation_contract', []);
        // Pre-v2 rows are immutable historical trials. Keep their already
        // sealed replay admissible; every newly materialized cohort carries
        // the stricter contract below.
        if ($confirmation !== [] && data_get($confirmation, 'protocol') !== 'bounded_skill_cartridge_confirmation_v1') {
            $reasons[] = 'CARTRIDGE_CONFIRMATION_CONTRACT_INVALID';
        } elseif ($confirmation !== [] && ! in_array((string) data_get($confirmation, 'mode'), [
            'frozen_baseline', 'exact_replication', 'independent_exact_replication', 'negative_control', 'memory_blinded_autonomous',
        ], true)) {
            $reasons[] = 'CARTRIDGE_CONFIRMATION_ARM_INVALID';
        } elseif ($confirmation !== []) {
            $hashesMatch = hash_equals((string) data_get($contract, 'data_hash'), (string) data_get($confirmation, 'data_hash', ''))
                && hash_equals((string) data_get($contract, 'execution_hash'), (string) data_get($confirmation, 'execution_hash', ''));
            if (! $hashesMatch) {
                $reasons[] = 'CARTRIDGE_CONFIRMATION_HASH_SCOPE_MISMATCH';
            }
        }

        return $this->result(true, $reasons === [], $reasons);
    }

    private function descendant(LabAgent $agent, array $metadata): array
    {
        $contract = (array) data_get($metadata, 'authority_descendant', []);
        $reasons = [];
        $arm = (string) data_get($contract, 'arm');
        $allowedArms = [
            'mentor_control',
            ...EvolutionaryAuthorityFoundryService::DESCENDANT_ARMS,
            ...EvolutionaryAuthorityFoundryService::DESCENDANT_ABLATION_ARMS,
        ];
        if ($agent->generation?->trigger_type !== 'authority_descendant') {
            $reasons[] = 'DESCENDANT_GENERATION_SCOPE_MISMATCH';
        }
        if (! in_array($arm, $allowedArms, true)) {
            $reasons[] = 'DESCENDANT_ARM_INVALID';
        }
        if ((int) data_get($contract, 'mentor_model_version_id', 0) <= 0) {
            $reasons[] = 'DESCENDANT_MENTOR_MISSING';
        }
        if ((int) data_get($contract, 'causal_baseline_model_version_id', 0) <= 0) {
            $reasons[] = 'DESCENDANT_CAUSAL_BASELINE_MISSING';
        }
        if (! filled(data_get($contract, 'data_hash')) || ! filled(data_get($contract, 'execution_hash'))) {
            $reasons[] = 'DESCENDANT_FROZEN_HASHES_MISSING';
        }
        $capsule = (array) data_get($contract, 'trait_capsule', []);
        $capsuleAssessment = app(ContextualCausalTraitCapsuleService::class)->assess(
            $capsule,
            (string) data_get($contract, 'confirmed_gene', ''),
        );
        if (! (bool) data_get($capsuleAssessment, 'valid', false)
            || ! filled(data_get($contract, 'trait_capsule_hash'))
            || ! hash_equals((string) data_get($contract, 'trait_capsule_hash'), (string) data_get($capsule, 'capsule_hash', ''))) {
            $reasons[] = 'DESCENDANT_CONTEXTUAL_TRAIT_CAPSULE_INVALID';
        }
        if ($arm === 'mentor_control' && count((array) $agent->parameter_diff) !== 0) {
            $reasons[] = 'DESCENDANT_MENTOR_CONTROL_NOT_FROZEN';
        }
        if (in_array($arm, EvolutionaryAuthorityFoundryService::DESCENDANT_ARMS, true)
            && count((array) $agent->parameter_diff) !== 1) {
            $reasons[] = 'DESCENDANT_SINGLE_OTHER_GENE_INVARIANT_FAILED';
        }
        if (in_array($arm, EvolutionaryAuthorityFoundryService::DESCENDANT_ABLATION_ARMS, true)
            && ! (bool) data_get($contract, 'trait_ablated', false)) {
            $reasons[] = 'DESCENDANT_MATCHED_ABLATION_CONTRACT_MISSING';
        }

        return $this->result(true, $reasons === [], $reasons);
    }

    private function interaction(LabAgent $agent, array $metadata): array
    {
        $contract = (array) data_get($metadata, 'skill_cartridge_interaction', []);
        $reasons = [];
        if ($agent->generation?->trigger_type !== 'skill_cartridge_interaction') {
            $reasons[] = 'INTERACTION_GENERATION_SCOPE_MISMATCH';
        }
        if (! filled(data_get($contract, 'data_hash')) || ! filled(data_get($contract, 'execution_hash'))) {
            $reasons[] = 'INTERACTION_FROZEN_HASHES_MISSING';
        }
        $row = DB::table('skill_cartridge_interactions')->where('interaction_key', data_get($contract, 'interaction_key'))->first();
        $armModels = $row ? (array) data_get(json_decode((string) $row->evidence, true), 'arm_model_version_ids', []) : [];
        if (! $row || ! in_array((int) $agent->model_version_id, array_map('intval', array_values($armModels)), true)
            || ! in_array((string) $row->status, ['queued', 'running'], true)) {
            $reasons[] = 'INTERACTION_TRIAL_NOT_REPLAYABLE';
        }

        return $this->result(true, $reasons === [], $reasons);
    }

    /** @param array<int,string> $reasons */
    private function result(bool $applicable, bool $allowed, array $reasons): array
    {
        return ['protocol' => 'direct_research_full_replay_admission_v1', 'applicable' => $applicable,
            'allowed' => $allowed, 'reason_codes' => array_values(array_unique($reasons)), 'promotion_evidence' => false];
    }
}
