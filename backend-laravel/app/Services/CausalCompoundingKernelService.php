<?php

namespace App\Services;

use App\Models\AgentLearningMutationIntent;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use Illuminate\Support\Str;

/**
 * Completes a bounded causal proof cohort as one real twenty-seat population.
 *
 * Specialized proof arms own only the seats their protocol needs. Remaining
 * compute is never left empty and never becomes blind mutation fan-out: it is
 * materialized as exact frozen-baseline candidate/control pairs. The causal
 * baseline is evidence provenance, not a genetic parent.
 */
class CausalCompoundingKernelService
{
    public const PROTOCOL = 'causal_compounding_kernel_v2';

    public const POPULATION_SIZE = 20;

    /**
     * @return array{status:string,contract:array<string,mixed>,agents:array<int,LabAgent>,dispatches:array<int,array{agent:LabAgent,mode:string}>}
     */
    public function complete(
        LabGeneration $generation,
        ModelVersion $baseline,
        LabAgent $scopeAgent,
        string $dataHash,
        string $executionHash,
        string $target = 'profit_factor',
        array $protectedGenes = [],
    ): array {
        $generation->loadMissing('agents.modelVersion');
        $primaryCount = $generation->agents->count();
        if ($primaryCount > self::POPULATION_SIZE) {
            throw new \RuntimeException('COMPOUNDING_KERNEL_PRIMARY_BUDGET_EXCEEDED');
        }
        if ($dataHash === '' || $executionHash === '') {
            throw new \RuntimeException('COMPOUNDING_KERNEL_FROZEN_HASHES_MISSING');
        }

        $remaining = self::POPULATION_SIZE - $primaryCount;
        $desiredPairCount = intdiv($remaining, 2);
        $family = (string) $scopeAgent->strategy_family;
        $base = app(StrategyParameterSchemaService::class)->validate(
            $family,
            app(StrategyParameterSchemaService::class)->normalizeForGeneration($family, (array) $baseline->parameters),
        );
        $allowed = array_values(array_diff(
            array_intersect(array_keys($base), array_keys(app(StrategyParameterSchemaService::class)->schema($family))),
            array_values(array_unique(array_filter(array_map('strval', $protectedGenes)))),
        ));
        $usedGenes = [];
        $usedInterventions = [];
        $created = [];
        $dispatches = [];
        $pairs = [];

        for ($pairIndex = 1; $pairIndex <= $desiredPairCount; $pairIndex++) {
            $available = array_values(array_diff($allowed, $usedGenes));
            $selection = null;
            $interventionFingerprint = null;
            // Prefer a never-used gene, then permit a new bounded value on a
            // previously explored gene. A duplicate gene+value is not a new
            // experiment and therefore never consumes another pair.
            for ($attempt = 1; $attempt <= 24; $attempt++) {
                $candidateAllowed = $attempt === 1 && $available !== [] ? $available : $allowed;
                $proposal = app(CausalBlindedMutationSelectorService::class)->select(
                    $family,
                    $target,
                    $base,
                    self::PROTOCOL.'|'.$generation->id.'|pair|'.$pairIndex.'|attempt|'.$attempt,
                    null,
                    null,
                    [],
                    $candidateAllowed,
                );
                if ($proposal === null) {
                    continue;
                }
                $proposalFingerprint = hash('sha256', json_encode([
                    (string) $proposal['gene'],
                    $proposal['old_value'],
                    $proposal['value'],
                ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
                if (in_array($proposalFingerprint, $usedInterventions, true)) {
                    continue;
                }
                $selection = $proposal;
                $interventionFingerprint = $proposalFingerprint;
                break;
            }
            if ($selection === null) {
                break;
            }
            $gene = (string) $selection['gene'];
            $usedGenes[] = $gene;
            $usedGenes = array_values(array_unique($usedGenes));
            $usedInterventions[] = (string) $interventionFingerprint;
            $candidateParameters = $base;
            $candidateParameters[$gene] = $selection['value'];
            $candidateParameters = app(StrategyParameterSchemaService::class)->validate(
                $family,
                app(StrategyParameterSchemaService::class)->normalizeForGeneration($family, $candidateParameters),
            );
            $diff = $this->diff($base, $candidateParameters);
            if (count($diff) !== 1 || (string) array_key_first($diff) !== $gene) {
                throw new \RuntimeException('COMPOUNDING_KERNEL_SINGLE_INTERVENTION_INVARIANT_FAILED');
            }

            $pairKey = hash('sha256', json_encode([
                self::PROTOCOL,
                (int) $generation->id,
                (int) $baseline->id,
                $pairIndex,
                $family,
                $target,
                $gene,
                $selection['value'],
                $dataHash,
                $executionHash,
            ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
            $control = $this->createSeat(
                $generation,
                $baseline,
                $scopeAgent,
                $base,
                [],
                'control',
                $pairIndex,
                $pairKey,
                $target,
                $dataHash,
                $executionHash,
                null,
            );
            $candidate = $this->createSeat(
                $generation,
                $baseline,
                $scopeAgent,
                $candidateParameters,
                $diff,
                'candidate',
                $pairIndex,
                $pairKey,
                $target,
                $dataHash,
                $executionHash,
                $selection,
            );
            $created[] = $control;
            $created[] = $candidate;
            // A control is intentionally queued first. Exact pair identity is
            // still enforced by the learning resolver, so queue timing cannot
            // substitute an unrelated same-family control.
            $dispatches[] = ['agent' => $control, 'mode' => 'screen'];
            $dispatches[] = ['agent' => $candidate, 'mode' => 'screen'];
            $pairs[] = [
                'pair_key' => $pairKey,
                'control_agent_id' => (int) $control->id,
                'candidate_agent_id' => (int) $candidate->id,
                'causal_baseline_model_version_id' => (int) $baseline->id,
                'gene' => $gene,
                'old_value' => $selection['old_value'],
                'tested_value' => $selection['value'],
                'selector_hash' => $selection['selection_hash'],
                'status' => 'sealed_pending_screening',
                'promotion_evidence' => false,
            ];
        }

        $pairCount = count($pairs);
        $abstainCount = $remaining - ($pairCount * 2);
        $abstainAgents = [];
        for ($index = 1; $index <= $abstainCount; $index++) {
            $pairKey = hash('sha256', self::PROTOCOL.'|'.$generation->id.'|uncertainty-abstain|'.$index);
            $agent = $this->createSeat(
                $generation,
                $baseline,
                $scopeAgent,
                $base,
                [],
                'uncertainty_abstain',
                $pairCount + $index,
                $pairKey,
                $target,
                $dataHash,
                $executionHash,
                null,
            );
            $created[] = $agent;
            $abstainAgents[] = (int) $agent->id;
            $dispatches[] = ['agent' => $agent, 'mode' => 'screen'];
        }

        $generation->refresh();
        $actual = $generation->agents()->count();
        if ($actual !== self::POPULATION_SIZE) {
            throw new \RuntimeException('COMPOUNDING_KERNEL_POPULATION_INVARIANT_FAILED');
        }
        $contract = [
            'protocol' => self::PROTOCOL,
            'state' => 'sealed',
            'population_size' => self::POPULATION_SIZE,
            'primary_proof_seats' => $primaryCount,
            'paired_discovery_seats' => $pairCount * 2,
            'uncertainty_abstain_seats' => $abstainCount,
            'causal_baseline_model_version_id' => (int) $baseline->id,
            'genetic_parent_model_version_id' => null,
            'data_hash' => $dataHash,
            'execution_hash' => $executionHash,
            'pairs' => $pairs,
            'abstain_agent_ids' => $abstainAgents,
            'research_inbox_may_propose_only' => true,
            'proven_registry_required_for_inheritance' => true,
            'family_prior_direct_inheritance_forbidden' => true,
            'promotion_evidence' => false,
        ];
        $generation->update([
            'population_size' => self::POPULATION_SIZE,
            'trigger_context' => [
                ...((array) $generation->trigger_context),
                'causal_compounding_kernel' => $contract,
            ],
        ]);

        return [
            'status' => 'sealed',
            'contract' => $contract,
            'agents' => $created,
            'dispatches' => $dispatches,
        ];
    }

    /** @param array<string,mixed>|null $selection */
    private function createSeat(
        LabGeneration $generation,
        ModelVersion $baseline,
        LabAgent $scopeAgent,
        array $parameters,
        array $diff,
        string $role,
        int $pairIndex,
        string $pairKey,
        string $target,
        string $dataHash,
        string $executionHash,
        ?array $selection,
    ): LabAgent {
        $control = in_array($role, ['control', 'uncertainty_abstain'], true);
        $metadata = $this->researchMetadata($baseline);
        $pairContract = [
            'protocol' => self::PROTOCOL,
            'pair_key' => $pairKey,
            'pair_index' => $pairIndex,
            'role' => $role,
            'required_for_candidate' => ! $control,
            'same_generation' => true,
            'same_symbol_timeframe' => true,
            'same_strategy_family' => true,
            'same_parameter_baseline' => true,
            'single_intervention_required' => true,
            'same_execution_contract' => true,
            'strategy_family' => (string) $scopeAgent->strategy_family,
            'causal_baseline_model_version_id' => (int) $baseline->id,
            'data_hash' => $dataHash,
            'execution_hash' => $executionHash,
            'promotion_evidence' => false,
        ];
        $metadata['base_strategy'] = data_get($metadata, 'base_strategy', (string) $scopeAgent->strategy_family);
        $metadata['causal_baseline_model_version_id'] = (int) $baseline->id;
        $metadata['genetic_parent_model_version_id'] = null;
        $metadata['generation_target'] = $target;
        $metadata['control_pair_contract'] = $pairContract;
        $metadata['causal_compounding_kernel'] = [
            'protocol' => self::PROTOCOL,
            'role' => $role,
            'pair_key' => $pairKey,
            'pair_index' => $pairIndex,
            'selection' => $selection,
            'intent_sealed_before_agent_persistence' => true,
            'research_only' => true,
            'promotion_evidence' => false,
        ];
        $metadata['mutation_constructor_invariant'] = [
            'protocol' => 'agent_constructor_invariant_v1',
            'status' => 'passed',
            'control_only' => $control,
            'single_gene_required' => ! $control,
            'changed_parameter_keys' => array_keys($diff),
            'parameter_diff_count' => count($diff),
            'causal_baseline_model_version_id' => (int) $baseline->id,
            'genetic_parent_model_version_id' => null,
            'promotion_evidence' => false,
        ];
        if ($control) {
            $metadata['control_contract'] = [
                'protocol' => 'frozen_control_v2',
                'control_only' => true,
                'role' => 'control',
                'generation_id' => (int) $generation->id,
                'pair_key' => $pairKey,
                'causal_baseline_model_version_id' => (int) $baseline->id,
                'parameter_hash' => hash('sha256', json_encode($parameters, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)),
                'data_hash' => $dataHash,
                'execution_hash' => $executionHash,
                'status' => 'control_sealed_pending_replay',
                'promotion_evidence' => false,
            ];
        }

        $seat = $generation->agents()->count() + 1;
        $runtime = 'kernel_g'.$generation->generation.'_s'.$seat.'_'.Str::lower($role);
        $model = ModelVersion::create([
            'name' => $baseline->name.' kernel G'.$generation->generation.' S'.$seat,
            'strategy' => $runtime,
            'version' => $baseline->version.'-kernel-'.$generation->generation.'-'.$seat,
            'generation' => $generation->generation,
            'status' => 'testing',
            'description' => 'Causal Compounding Kernel paired research seat; research-only.',
            'change_log' => 'kernel '.$role.' pair '.$pairIndex,
            'parameters' => $parameters,
            'metadata' => $metadata,
            'evidence_status' => 'valid',
        ]);
        $intentService = app(CausalLearningMutationIntentService::class);
        $intentPlan = $intentService->plan(
            $generation,
            [
                'packet_id' => $pairKey,
                'status' => 'memory_abstained',
                'positive_lessons' => [],
                'harmful_lessons' => [],
                'uncertainty_lessons' => [],
                'promotion_evidence' => false,
            ],
            (string) $scopeAgent->strategy_family,
            $target,
            (array) $baseline->parameters,
            $parameters,
            $diff,
            $control ? 'frozen_control' : 'blinded',
        );
        $intent = $intentService->seal($intentPlan, $generation, $model);
        if (! $intent instanceof AgentLearningMutationIntent) {
            throw new \RuntimeException('COMPOUNDING_KERNEL_INTENT_SEAL_FAILED');
        }
        $agent = LabAgent::create([
            'lab_generation_id' => $generation->id,
            'model_version_id' => $model->id,
            'parent_a_model_version_id' => null,
            'parent_b_model_version_id' => null,
            'symbol' => strtoupper((string) $scopeAgent->symbol),
            'timeframe' => strtoupper((string) $scopeAgent->timeframe),
            'strategy_family' => (string) $scopeAgent->strategy_family,
            'origin' => $control ? 'compounding_discovery_control' : 'compounding_discovery_candidate',
            'lifecycle_status' => 'queued',
            'parameter_diff' => $diff,
            'decision_reason' => $control
                ? 'Exact non-genetic frozen causal baseline for one compounding discovery pair.'
                : 'One pre-registered blinded gene intervention against its exact frozen pair control.',
        ]);
        $binding = $intentService->bind($intent, $agent);
        if (data_get($binding, 'status') !== 'bound') {
            throw new \RuntimeException('COMPOUNDING_KERNEL_INTENT_BIND_FAILED');
        }

        return $agent->fresh('modelVersion');
    }

    /** @return array<string,mixed> */
    private function researchMetadata(ModelVersion $baseline): array
    {
        $metadata = (array) $baseline->metadata;
        foreach (['skill_mentor', 'evolution_stage', 'elite_agent_passport', 'learning_lane',
            'learning_receipt', 'causal_learning_cohort', 'causal_learning_experiment',
            'authority_incubator', 'authority_descendant', 'parent_foundry',
            'edge_genesis', 'edge_genesis_attribution', 'skill_cartridge_transplant',
            'skill_cartridge_interaction', 'academy_experiment',
            'control_contract', 'control_pair_contract', 'causal_compounding_kernel',
            'mutation_constructor_invariant'] as $key) {
            unset($metadata[$key]);
        }

        return $metadata;
    }

    /** @return array<string,array{old:mixed,new:mixed}> */
    private function diff(array $old, array $new): array
    {
        $diff = [];
        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $key) {
            if (json_encode($old[$key] ?? null, JSON_PRESERVE_ZERO_FRACTION)
                !== json_encode($new[$key] ?? null, JSON_PRESERVE_ZERO_FRACTION)) {
                $diff[$key] = ['old' => $old[$key] ?? null, 'new' => $new[$key] ?? null];
            }
        }

        return $diff;
    }
}
