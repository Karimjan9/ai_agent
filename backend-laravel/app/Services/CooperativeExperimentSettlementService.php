<?php

namespace App\Services;

use App\Models\CandidateGateDecision;
use App\Models\CooperativeExperimentSettlement;
use App\Models\CooperativeModuleSpeciesMember;
use App\Models\ContextualSpecialistCapsule;
use App\Models\LabAgent;
use Illuminate\Support\Facades\Schema;

/** Closes pair/factorial/transfer/descendant blocks from their actual screening arms. */
class CooperativeExperimentSettlementService
{
    public const PROTOCOL = 'cooperative_experiment_settlement_v1';

    /** @return array<string,mixed> */
    public function observe(LabAgent $agent): array
    {
        $block = (array) data_get($agent->modelVersion?->metadata, 'cooperative_experiment_block', []);
        $blockKey = (string) data_get($block, 'block_key', '');
        if ($blockKey === '' || ! Schema::hasTable('cooperative_experiment_settlements')) {
            return ['protocol' => self::PROTOCOL, 'status' => $blockKey === '' ? 'not_applicable' : 'migration_pending'];
        }
        $agents = $agent->generation->agents()->with('modelVersion')->get()->filter(fn (LabAgent $row): bool =>
            (string) data_get($row->modelVersion?->metadata, 'cooperative_experiment_block.block_key', '') === $blockKey
        );
        $armResults = [];
        foreach ($agents as $row) {
            $arm = (string) data_get($row->modelVersion?->metadata, 'cooperative_experiment_block.arm', '');
            $decision = CandidateGateDecision::query()->where('lab_agent_id', $row->id)->where('stage', 'screening')->latest('id')->first();
            if ($arm === '' || ! $decision) continue;
            $metrics = (array) $decision->metrics;
            $armResults[$arm] = [
                'lab_agent_id' => $row->id, 'model_version_id' => $row->model_version_id,
                'decision_id' => $decision->id, 'decision' => $decision->decision,
                'after_cost_value' => $this->value($metrics),
                'pareto_vector' => data_get($metrics, 'contextual_capsule_archive.pareto_vector'),
                'context_cell_key' => data_get($row->modelVersion?->metadata, 'cooperative_experiment_block.context_cell_key', data_get($row->modelVersion?->metadata, 'cooperative_evolution_capsule.context_cell_hash')),
            ];
        }
        $required = array_values((array) data_get($block, 'required_arms', []));
        $complete = $required !== [] && collect($required)->every(fn (string $arm): bool => array_key_exists($arm, $armResults));
        $effects = $this->effects((string) data_get($block, 'block_type', ''), $armResults);
        $positive = $complete && (float) data_get($effects, 'whole_capsule_effect', data_get($effects, 'candidate_delta', 0)) > 0;
        $status = ! $complete ? 'waiting_for_arms' : ($positive ? 'settled_positive_signal' : 'settled_negative_or_null');
        $settlementKey = hash('sha256', implode('|', [self::PROTOCOL, $agent->lab_generation_id, $blockKey]));
        $row = CooperativeExperimentSettlement::query()->updateOrCreate(['settlement_key' => $settlementKey], [
            'block_key' => $blockKey, 'lab_generation_id' => $agent->lab_generation_id,
            'block_type' => (string) data_get($block, 'block_type', 'unknown'),
            'context_cell_key' => (string) data_get($agent->modelVersion?->metadata, 'cooperative_evolution_capsule.context_cell_hash', '') ?: null,
            'arm_results' => $armResults, 'component_effects' => $effects,
            'pareto_vectors' => collect($armResults)->mapWithKeys(fn (array $result, string $arm): array => [$arm => $result['pareto_vector']])->all(),
            'outcome_status' => $status, 'evidence_complete' => $complete, 'promotion_evidence' => false,
        ]);
        if ($complete) {
            $this->settleModuleSpecies($agents, (string) data_get($block, 'block_type', ''), $effects, $row->id);
            $bundleGraph = app(ContextualInstrumentBundleGraphService::class)->record($row, $agents, $effects);
            app(ResearchIdeaInboxService::class)->settle($blockKey, [
                'settlement_id' => $row->id, 'settlement_key' => $settlementKey, 'outcome_status' => $status,
                'component_effects' => $effects, 'instrument_bundle_graph' => $bundleGraph,
            ]);
        }
        return ['protocol' => self::PROTOCOL, 'status' => $status, 'settlement_id' => $row->id,
            'evidence_complete' => $complete, 'component_effects' => $effects,
            'instrument_bundle_graph' => $bundleGraph ?? null, 'promotion_evidence' => false];
    }

    /** @return array<string,float|int|null> */
    private function effects(string $type, array $arms): array
    {
        $v = fn (string $arm): ?float => isset($arms[$arm]) ? (float) $arms[$arm]['after_cost_value'] : null;
        if ($type === 'factorial') {
            $control = $v('control'); $a = $v('a_only'); $b = $v('b_only'); $ab = $v('a_plus_b');
            return ['component_a_marginal_effect' => $this->delta($a, $control),
                'component_b_marginal_effect' => $this->delta($b, $control),
                'interaction_effect' => in_array(null, [$control, $a, $b, $ab], true) ? null : round($ab - $a - $b + $control, 6),
                'whole_capsule_effect' => $this->delta($ab, $control)];
        }
        if ($type === 'transfer') {
            return ['source_context_effect' => $this->delta($v('source_candidate'), $v('source_control')),
                'target_context_effect' => $this->delta($v('target_candidate'), $v('target_control')),
                'whole_capsule_effect' => $this->delta($v('target_candidate'), $v('target_control')),
                'cross_context_authority_granted' => 0];
        }
        if ($type === 'descendant') {
            return ['parent_effect' => $this->delta($v('parent_reference'), $v('parent_control')),
                'child_effect' => $this->delta($v('child_challenge'), $v('descendant_control')),
                'inheritance_effect' => $this->delta($v('child_challenge'), $v('parent_reference')),
                'whole_capsule_effect' => $this->delta($v('child_challenge'), $v('descendant_control'))];
        }
        $control = $v('exact_frozen_control');
        $candidate = $v('candidate') ?? $v('guard_challenge');
        return ['candidate_delta' => $this->delta($candidate, $control), 'whole_capsule_effect' => $this->delta($candidate, $control)];
    }

    private function value(array $metrics): float
    {
        return round((float) data_get($metrics, 'after_cost_expectancy_r', data_get($metrics, 'expectancy_r', data_get($metrics, 'net_profit_percent', 0))), 6);
    }

    private function delta(?float $candidate, ?float $control): ?float
    {
        return $candidate === null || $control === null ? null : round($candidate - $control, 6);
    }

    private function settleModuleSpecies($agents, string $type, array $effects, int $settlementId): void
    {
        if (! Schema::hasTable('cooperative_module_species_members')) return;
        foreach ($agents as $agent) {
            $arm = (string) data_get($agent->modelVersion?->metadata, 'cooperative_experiment_block.arm', '');
            $delta = match (true) {
                $type === 'factorial' && $arm === 'a_only' => data_get($effects, 'component_a_marginal_effect'),
                $type === 'factorial' && $arm === 'b_only' => data_get($effects, 'component_b_marginal_effect'),
                $type === 'factorial' && $arm === 'a_plus_b' => data_get($effects, 'whole_capsule_effect'),
                $type === 'transfer' && str_starts_with($arm, 'source_candidate') => data_get($effects, 'source_context_effect'),
                $type === 'transfer' && str_starts_with($arm, 'target_candidate') => data_get($effects, 'target_context_effect'),
                $type === 'descendant' && $arm === 'parent_reference' => data_get($effects, 'parent_effect'),
                $type === 'descendant' && $arm === 'child_challenge' => data_get($effects, 'child_effect'),
                in_array($arm, ['candidate', 'guard_challenge'], true) => data_get($effects, 'candidate_delta'),
                default => null,
            };
            if ($delta === null) continue;
            $authority = (float) $delta > 0 ? 'repair_credit' : 'information_credit';
            $status = (float) $delta > 0 ? 'beneficial_local_observation' : ((float) $delta < 0 ? 'harmful_local_observation' : 'neutral_local_observation');
            CooperativeModuleSpeciesMember::query()->where('lab_agent_id', $agent->id)->get()->each(function (CooperativeModuleSpeciesMember $member) use ($settlementId, $delta, $authority, $status): void {
                $member->update(['evidence' => [...(array) $member->evidence,
                    'latest_block_settlement_id' => $settlementId, 'local_control_relative_delta' => $delta,
                    'global_inheritance_allowed' => false, 'promotion_evidence' => false],
                    'authority_level' => $authority, 'status' => $status]);
            });
            if ((float) $delta < 0 && Schema::hasTable('contextual_specialist_capsules')) {
                ContextualSpecialistCapsule::query()->where('lab_agent_id', $agent->id)
                    ->where('status', '!=', 'elite')->update(['status' => 'anti_skill']);
            }
        }
    }
}
