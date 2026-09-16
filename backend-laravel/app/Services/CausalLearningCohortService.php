<?php

namespace App\Services;

use App\Models\AgentLearningCausalExperiment;
use App\Models\AgentLearningMutationIntent;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use Illuminate\Support\Facades\Schema;

/** Binds constructed agents to the pre-registered counterfactual triplet. */
class CausalLearningCohortService
{
    /** The single intervention arm in a causal counterfactual triplet. */
    public const GUIDED_ROLES = [
        'memory_guided',
        'hypothesis_guided',
        'repair_guided',
    ];

    /** Every role that must receive the same serialized full replay. */
    public const COUNTERFACTUAL_ROLES = [
        ...self::GUIDED_ROLES,
        'blinded',
        'frozen_control',
    ];

    /**
     * Resolve exactly one guided role and fail closed if a malformed cohort
     * declares none or more than one. The hypothesis arm is first-class: it
     * earns no inherited authority, but it still requires the same replay as
     * memory- and repair-guided interventions.
     *
     * @param  iterable<int|string,string>  $roles
     */
    public function resolveGuidedRole(iterable $roles): ?string
    {
        $guided = collect($roles)
            ->map(fn (mixed $role): string => (string) $role)
            ->filter(fn (string $role): bool => in_array($role, self::GUIDED_ROLES, true))
            ->unique()
            ->values();

        return $guided->count() === 1 ? (string) $guided->first() : null;
    }

    /**
     * Canonical admission for the expensive counterfactual replay.  A causal
     * confirmation deliberately replays all three arms even when their
     * screening strategy gate failed; selecting only the winning screen arm
     * would create survivorship bias.  The exception is fail-closed and is
     * valid only when the complete, immutable triplet contract is present.
     *
     * @return array{applicable: bool, allowed: bool, reason_codes: array<int, string>, experiment_id: ?int}
     */
    public function fullReplayAdmission(LabAgent $agent, LabImmutableEvidenceService $immutableEvidence): array
    {
        $agent->loadMissing('generation', 'modelVersion');
        $generation = $agent->generation;
        $contract = (array) data_get($agent->modelVersion?->metadata, 'causal_learning_cohort', []);
        $schemaAvailable = Schema::hasTable('agent_learning_causal_experiments');
        $referencedExperiment = $schemaAvailable && $generation
            ? AgentLearningCausalExperiment::query()
                ->where('lab_generation_id', $generation->id)
                ->where(function ($query) use ($agent): void {
                    $query->where('guided_agent_id', $agent->id)
                        ->orWhere('blinded_agent_id', $agent->id)
                        ->orWhere('control_agent_id', $agent->id);
                })
                ->latest('id')
                ->first()
            : null;

        // A learning-confirmation generation can also contain independent
        // repair/control, novelty and continuity blocks. Membership in that
        // generation must not turn every seat into a causal triplet arm: the
        // triplet gate applies only to an explicitly contracted or durably
        // referenced arm. A referenced arm whose metadata was lost remains
        // applicable and therefore fails closed below.
        $applicable = $contract !== [] || $referencedExperiment !== null;
        if (! $applicable) {
            return ['applicable' => false, 'allowed' => false, 'reason_codes' => [], 'experiment_id' => null];
        }

        $reasons = [];
        if (! $schemaAvailable) {
            $reasons[] = 'CAUSAL_EXPERIMENT_SCHEMA_MISSING';
        }
        if (! $generation || $generation->trigger_type !== 'learning_confirmation') {
            $reasons[] = 'CAUSAL_GENERATION_TYPE_INVALID';
        }
        if (data_get($generation?->trigger_context, 'adaptive_evolution_policy.causal_learning_counterfactual_cohort.status') !== 'materialized') {
            $reasons[] = 'CAUSAL_COHORT_NOT_MATERIALIZED';
        }
        if (data_get($contract, 'protocol') !== CausalLearningCohortPlannerService::PROTOCOL) {
            $reasons[] = 'CAUSAL_COHORT_PROTOCOL_INVALID';
        }
        $role = (string) data_get($contract, 'role');
        if (! in_array($role, self::COUNTERFACTUAL_ROLES, true)) {
            $reasons[] = 'CAUSAL_COHORT_ROLE_INVALID';
        }

        $experimentId = (int) data_get($contract, 'experiment_id', 0);
        $experiment = $schemaAvailable && $experimentId > 0
            ? AgentLearningCausalExperiment::query()->find($experimentId)
            : null;
        if (! $experiment || (int) $experiment->lab_generation_id !== (int) $generation?->id) {
            $reasons[] = 'CAUSAL_EXPERIMENT_MISSING_OR_MISMATCHED';
        } else {
            if (! in_array((string) $experiment->status, ['ready_for_replay', 'outcomes_pending', 'provisional'], true)
                || data_get($experiment->evidence, 'construction_validation.status') !== 'ready_for_replay') {
                $reasons[] = 'CAUSAL_EXPERIMENT_NOT_REPLAYABLE';
            }
            $roleAgentId = match ($role) {
                'memory_guided', 'hypothesis_guided', 'repair_guided' => $experiment->guided_agent_id,
                'blinded' => $experiment->blinded_agent_id,
                'frozen_control' => $experiment->control_agent_id,
                default => null,
            };
            if ((int) $roleAgentId !== (int) $agent->id) {
                $reasons[] = 'CAUSAL_EXPERIMENT_ROLE_AGENT_MISMATCH';
            }
            if (in_array((string) data_get($experiment->evidence, 'experiment_kind'), [
                'causal_repair', 'causal_architecture_escape', 'causal_architecture_interaction',
            ], true)) {
                $reasons = [
                    ...$reasons,
                    ...$this->blindedFeasibilityReasons((array) data_get($experiment->evidence, 'blinded_selector', [])),
                ];
            }

            $tripletIds = array_values(array_filter([
                $experiment->guided_agent_id,
                $experiment->blinded_agent_id,
                $experiment->control_agent_id,
            ], fn ($id): bool => (int) $id > 0));
            if (count(array_unique(array_map('intval', $tripletIds))) !== 3) {
                $reasons[] = 'CAUSAL_COHORT_TRIPLET_INCOMPLETE';
            } else {
                foreach ($tripletIds as $tripletId) {
                    $screeningRun = LabEvaluationRun::query()
                        ->where('lab_generation_id', $generation?->id)
                        ->where('lab_agent_id', $tripletId)
                        ->where('phase', 'screening')
                        ->where('status', 'completed')
                        ->latest('id')
                        ->first();
                    if (! $screeningRun || ! $immutableEvidence->learningEligibility($screeningRun)['complete']) {
                        $reasons[] = 'CAUSAL_COHORT_SCREENING_EVIDENCE_INCOMPLETE';
                        break;
                    }
                }
                if (! in_array('CAUSAL_COHORT_SCREENING_EVIDENCE_INCOMPLETE', $reasons, true)
                    && in_array((string) data_get($experiment->evidence, 'experiment_kind'), [
                        'causal_repair', 'causal_architecture_escape', 'causal_architecture_interaction',
                    ], true)) {
                    $behaviorPreflight = app(CausalScreeningBehaviorPreflightService::class)->assess($experiment);
                    $reasons = [...$reasons, ...(array) ($behaviorPreflight['reason_codes'] ?? [])];
                }
            }
        }

        return [
            'applicable' => true,
            'allowed' => $reasons === [],
            'reason_codes' => array_values(array_unique($reasons)),
            'experiment_id' => $experiment?->id,
            'screening_behavior_preflight' => $behaviorPreflight ?? null,
        ];
    }

    /** Terminalize a pre-replay triplet that failed immutable queue admission. */
    public function invalidateGeneration(LabGeneration $generation, array $reasonCodes): int
    {
        if (! Schema::hasTable('agent_learning_causal_experiments')) {
            return 0;
        }

        $experiments = AgentLearningCausalExperiment::query()
            ->where('lab_generation_id', $generation->id)
            ->whereIn('status', ['awaiting_counterfactuals', 'ready_for_replay'])
            ->get();
        foreach ($experiments as $experiment) {
            $experiment->update([
                'status' => 'invalid_counterfactual_contract',
                'evidence' => [
                    ...((array) $experiment->evidence),
                    'queue_admission_validation' => [
                        'status' => 'invalid_counterfactual_contract',
                        'reason_codes' => array_values(array_unique(array_map('strval', $reasonCodes))),
                        'invalidated_at' => now()->utc()->toIso8601String(),
                        'strategy_verdict' => 'withheld',
                        'promotion_evidence' => false,
                    ],
                    'promotion_evidence' => false,
                ],
            ]);
            app(CausalRepairFrontierService::class)->releaseInvalidChild($experiment->fresh(), $reasonCodes);
            foreach (array_filter([
                $experiment->guided_agent_id,
                $experiment->blinded_agent_id,
                $experiment->control_agent_id,
            ]) as $agentId) {
                $agent = LabAgent::query()->with('modelVersion')->find($agentId);
                if (! $agent?->modelVersion) {
                    continue;
                }
                if (! in_array((string) $agent->lifecycle_status, [
                    'rejected', 'stagnated', 'retired', 'technical_quarantine',
                ], true)) {
                    $agent->update([
                        'lifecycle_status' => 'technical_quarantine',
                        'decision_reason' => 'Causal triplet replay admission failed; strategy verdict withheld: '.implode(',', $reasonCodes).'.',
                    ]);
                }
                $metadata = (array) $agent->modelVersion->metadata;
                data_set($metadata, 'causal_learning_cohort.status', 'invalid_counterfactual_contract');
                data_set($metadata, 'causal_learning_cohort.queue_admission_reason_codes', $reasonCodes);
                $agent->modelVersion->update(['metadata' => $metadata]);
            }
        }

        if ($experiments->isNotEmpty()) {
            $generation->update([
                'status' => 'technical_quarantine',
                'completed_at' => now(),
            ]);
        }

        return $experiments->count();
    }

    /** @return array<string, mixed> */
    public function enroll(LabAgent $agent, AgentLearningMutationIntent|array $intent, ?array $niche): array
    {
        $contract = (array) data_get($niche, 'causal_learning_cohort', []);
        if ($contract === [] || ! Schema::hasTable('agent_learning_causal_experiments')) {
            return ['status' => 'not_applicable', 'promotion_evidence' => false];
        }
        $role = (string) data_get($contract, 'role');
        $field = match ($role) {
            'memory_guided', 'hypothesis_guided', 'repair_guided' => 'guided_agent_id',
            'blinded' => 'blinded_agent_id',
            'frozen_control' => 'control_agent_id',
            default => null,
        };
        if ($field === null) {
            return ['status' => 'invalid_role', 'promotion_evidence' => false];
        }
        $experiment = AgentLearningCausalExperiment::query()->firstOrCreate(
            ['experiment_key' => (string) data_get($contract, 'experiment_key')],
            [
                'lab_generation_id' => $agent->lab_generation_id,
                'symbol' => $agent->symbol,
                'timeframe' => $agent->timeframe,
                'strategy_family' => $agent->strategy_family,
                'target' => data_get($agent->modelVersion?->metadata, 'generation_target'),
                'gene_key' => (string) data_get($contract, 'gene'),
                'source_lesson_id' => data_get($contract, 'source_lesson_id'),
                'status' => 'awaiting_counterfactuals',
                'evidence' => [
                    'protocol' => CausalLearningCohortPlannerService::PROTOCOL,
                    'confirmation_evidence_protocol' => CausalLearningConfirmationService::EVIDENCE_PROTOCOL,
                    'experiment_kind' => (string) data_get($contract, 'experiment_kind', 'memory_confirmation'),
                    'source_causal_experiment_id' => (int) data_get($contract, 'source_causal_experiment_id', 0) ?: null,
                    'repair_lineage' => in_array((string) data_get($contract, 'experiment_kind'), [
                        'causal_repair', 'causal_architecture_escape', 'causal_architecture_interaction',
                    ], true) ? [
                        'protocol' => CausalRepairFrontierService::PROTOCOL,
                        'depth' => (int) data_get($contract, 'repair_depth', 1),
                        'attempted_genes' => (array) data_get($contract, 'attempted_genes', []),
                        'promotion_evidence' => false,
                    ] : null,
                    'architecture_lineage' => in_array((string) data_get($contract, 'experiment_kind'), [
                        'causal_architecture_escape', 'causal_architecture_interaction',
                    ], true) ? [
                        'protocol' => (string) data_get($contract, 'experiment_kind') === 'causal_architecture_interaction'
                            ? CausalRepairFrontierService::ARCHITECTURE_INTERACTION_PROTOCOL
                            : CausalRepairFrontierService::ARCHITECTURE_PROTOCOL,
                        'depth' => (int) data_get($contract, 'architecture_depth', 1),
                        'attempted_genes' => (array) data_get($contract, 'attempted_architecture_genes', []),
                        'interaction_depth' => (int) data_get($contract, 'interaction_depth', 0),
                        'interaction_components' => (array) data_get($contract, 'interaction_components', []),
                        'structural_operation' => data_get($contract, 'structural_operation'),
                        'promotion_evidence' => false,
                    ] : null,
                    'source_pair_id' => (int) data_get($contract, 'source_pair_id', 0),
                    'source_authority' => (string) data_get($contract, 'source_authority', 'canonical_causal_source'),
                    'source_authority_blockers' => (array) data_get($contract, 'source_authority_blockers', []),
                    'legacy_hypothesis_grants_credit' => false,
                    'root_source_pair_id' => (int) data_get($contract, 'root_source_pair_id', data_get($contract, 'source_pair_id', 0)),
                    'source_control_agent_id' => (int) data_get($contract, 'source_control_agent_id', 0),
                    'baseline_model_version_id' => (int) data_get($contract, 'baseline_model_version_id', 0),
                    'baseline_policy' => (string) data_get($contract, 'baseline_policy', 'original_frozen_control'),
                    'research_ratchet' => (array) data_get($contract, 'research_ratchet', []),
                    'baseline_old_value' => data_get($contract, 'baseline_old_value'),
                    'construction_protocol' => (string) data_get($contract, 'construction_protocol', ''),
                    'activation_screen' => (array) data_get($contract, 'activation_screen', []),
                    'blinded_selector' => (array) data_get($contract, 'blinded_selector', []),
                    'roles' => [],
                    'promotion_evidence' => false,
                ],
            ],
        );
        $evidence = (array) $experiment->evidence;
        $evidence['roles'][$role] = [
            'agent_id' => (int) $agent->id,
            'model_version_id' => (int) $agent->model_version_id,
            'intent_id' => $intent instanceof AgentLearningMutationIntent ? (int) $intent->id : null,
            'influence_type' => $intent instanceof AgentLearningMutationIntent ? $intent->influence_type : 'unavailable',
            'parent_a_model_version_id' => $agent->parent_a_model_version_id,
            'parameter_diff_hash' => $this->hash((array) $agent->parameter_diff),
            'dataset_hash' => $agent->generation?->data_fingerprint,
        ];
        $experiment->update([$field => $agent->id, 'evidence' => $evidence]);
        $experiment = $experiment->fresh();
        $status = $this->validate($experiment);
        $experiment->update(['status' => $status['status'], 'evidence' => [
            ...((array) $experiment->evidence),
            'construction_validation' => $status,
            'promotion_evidence' => false,
        ]]);
        app(CausalRepairFrontierService::class)->linkDispatched($experiment->fresh());
        $metadata = (array) $agent->modelVersion?->metadata;
        if ($agent->modelVersion) {
            $metadata['causal_learning_cohort'] = [
                'protocol' => CausalLearningCohortPlannerService::PROTOCOL,
                'experiment_id' => (int) $experiment->id,
                'experiment_key' => $experiment->experiment_key,
                'role' => $role,
                'status' => $status['status'],
                'promotion_evidence' => false,
            ];
            $agent->modelVersion->update(['metadata' => $metadata]);
        }

        return ['experiment_id' => (int) $experiment->id, 'role' => $role, ...$status];
    }

    /** @return array<string, mixed> */
    private function validate(AgentLearningCausalExperiment $experiment): array
    {
        if (! $experiment->guided_agent_id || ! $experiment->blinded_agent_id || ! $experiment->control_agent_id) {
            return ['status' => 'awaiting_counterfactuals', 'promotion_evidence' => false];
        }
        $agents = LabAgent::query()->whereIn('id', [
            $experiment->guided_agent_id, $experiment->blinded_agent_id, $experiment->control_agent_id,
        ])->with('modelVersion')->get()->keyBy('id');
        $guided = $agents->get($experiment->guided_agent_id);
        $blinded = $agents->get($experiment->blinded_agent_id);
        $control = $agents->get($experiment->control_agent_id);
        $guidedIntent = AgentLearningMutationIntent::query()->where('lab_agent_id', $guided?->id)->first();
        $blindedIntent = AgentLearningMutationIntent::query()->where('lab_agent_id', $blinded?->id)->first();
        $reasons = [];
        if (! $guided || ! $blinded || ! $control) {
            $reasons[] = 'COHORT_AGENT_MISSING';
        }
        $repair = in_array((string) data_get($experiment->evidence, 'experiment_kind'), [
            'causal_repair', 'causal_architecture_escape', 'causal_architecture_interaction',
        ], true);
        $kind = (string) data_get($experiment->evidence, 'experiment_kind');
        $hypothesisReproduction = $kind === 'legacy_hypothesis_reproduction';
        $architecture = in_array($kind, [
            'causal_architecture_escape', 'causal_architecture_interaction',
        ], true);
        if ($repair) {
            if ($guidedIntent?->influence_type !== 'causal_repair_guided'
                || (array) $guidedIntent?->causally_applied_lesson_ids !== []
                || (int) data_get($experiment->evidence, 'source_causal_experiment_id', 0) <= 0) {
                $reasons[] = 'GUIDED_CAUSAL_REPAIR_FRONTIER_MISSING';
            }
            if ($architecture
                && (! in_array((string) $experiment->gene_key, CausalRepairFrontierService::ARCHITECTURE_GENES, true)
                    || data_get($experiment->evidence, 'architecture_lineage.protocol') !== ($kind === 'causal_architecture_interaction'
                        ? CausalRepairFrontierService::ARCHITECTURE_INTERACTION_PROTOCOL
                        : CausalRepairFrontierService::ARCHITECTURE_PROTOCOL)
                    || (int) data_get($experiment->evidence, 'architecture_lineage.depth', 0) < 1
                    || ! in_array(
                        (string) $experiment->gene_key,
                        (array) data_get($experiment->evidence, 'architecture_lineage.attempted_genes', []),
                        true,
                    ))) {
                $reasons[] = 'GUIDED_CAUSAL_ARCHITECTURE_LINEAGE_INVALID';
            }
            if ($kind === 'causal_architecture_interaction'
                && ((int) data_get($experiment->evidence, 'architecture_lineage.interaction_depth', 0) !== 1
                    || (array) data_get($experiment->evidence, 'architecture_lineage.interaction_components', []) !== [
                        'state_machine_variant', 'regime_classifier_variant',
                    ])) {
                $reasons[] = 'GUIDED_CAUSAL_ARCHITECTURE_INTERACTION_INVALID';
            }
        } elseif ($hypothesisReproduction) {
            if ($guidedIntent?->influence_type !== 'hypothesis_guided'
                || (array) $guidedIntent?->causally_applied_lesson_ids !== []
                || ! in_array((int) $experiment->source_lesson_id, array_map(
                    'intval',
                    (array) $guidedIntent?->selected_lesson_ids,
                ), true)
                || (string) data_get($experiment->evidence, 'source_authority') !== 'legacy_hypothesis_only') {
                $reasons[] = 'GUIDED_LEGACY_HYPOTHESIS_CONTRACT_INVALID';
            }
        } elseif ($guidedIntent?->influence_type !== 'memory_guided'
            || ! in_array((int) $experiment->source_lesson_id, (array) $guidedIntent?->causally_applied_lesson_ids, true)) {
            $reasons[] = 'GUIDED_CAUSAL_MEMORY_MISSING';
        }
        if ($blindedIntent?->influence_type !== 'blinded_counterfactual') {
            $reasons[] = 'BLINDED_INTENT_INVALID';
        }
        if ((array) $control?->parameter_diff !== []) {
            $reasons[] = 'CONTROL_NOT_FROZEN';
        }
        if (count((array) $guided?->parameter_diff) !== 1
            || (string) array_key_first((array) $guided?->parameter_diff) !== (string) $experiment->gene_key) {
            $reasons[] = 'GUIDED_MUTATION_NOT_EXACT_SOURCE_GENE';
        }
        $blindedSelector = (array) data_get($experiment->evidence, 'blinded_selector', []);
        $blindedGene = (string) data_get($blindedSelector, 'gene', '');
        if (data_get($experiment->evidence, 'construction_protocol') !== CausalRepairFrontierService::CONSTRUCTION_PROTOCOL
            || data_get($blindedSelector, 'protocol') !== CausalBlindedMutationSelectorService::PROTOCOL
            || (int) data_get($blindedSelector, 'memory_inputs', -1) !== 0) {
            $reasons[] = 'BLINDED_SELECTOR_NOT_PRE_REGISTERED';
        }
        if ($repair) {
            $reasons = [...$reasons, ...$this->blindedFeasibilityReasons($blindedSelector)];
        }
        if (count((array) $blinded?->parameter_diff) !== 1
            || (string) array_key_first((array) $blinded?->parameter_diff) !== $blindedGene
            || $this->different(data_get($blinded?->parameter_diff, $blindedGene.'.new'), data_get($blindedSelector, 'value'))) {
            $reasons[] = 'BLINDED_SELECTOR_NOT_SINGLE_GENE';
        }
        if ((array) $blindedIntent?->causally_applied_lesson_ids !== []) {
            $reasons[] = 'BLINDED_SELECTOR_CONSUMED_MEMORY';
        }
        if ($guided?->parent_a_model_version_id !== $blinded?->parent_a_model_version_id
            || $guided?->parent_a_model_version_id !== $control?->parent_a_model_version_id) {
            $reasons[] = 'PARENT_MISMATCH';
        }
        $baselineModelId = (int) data_get($experiment->evidence, 'baseline_model_version_id', 0);
        if ($baselineModelId <= 0 || (int) $guided?->parent_a_model_version_id !== $baselineModelId) {
            $reasons[] = 'SOURCE_BASELINE_MODEL_MISMATCH';
        }
        $baselineParameters = (array) ModelVersion::query()->find($baselineModelId)?->parameters;
        if ($baselineParameters === []) {
            $reasons[] = 'SOURCE_BASELINE_PARAMETERS_MISSING';
        } else {
            $guidedExactDiff = $this->parameterDiff($baselineParameters, (array) $guided?->modelVersion?->parameters);
            $blindedExactDiff = $this->parameterDiff($baselineParameters, (array) $blinded?->modelVersion?->parameters);
            $controlExactDiff = $this->parameterDiff($baselineParameters, (array) $control?->modelVersion?->parameters);
            if (count($guidedExactDiff) !== 1
                || (string) array_key_first($guidedExactDiff) !== (string) $experiment->gene_key) {
                $reasons[] = 'GUIDED_MODEL_NOT_EXACT_SOURCE_INTERVENTION';
            }
            if (count($blindedExactDiff) !== 1
                || (string) array_key_first($blindedExactDiff) !== $blindedGene) {
                $reasons[] = 'BLINDED_MODEL_NOT_EXACT_SOURCE_INTERVENTION';
            }
            if ($controlExactDiff !== []) {
                $reasons[] = 'CONTROL_MODEL_NOT_EXACT_SOURCE_BASELINE';
            }
        }

        return [
            'status' => $reasons === [] ? 'ready_for_replay' : 'invalid_counterfactual_contract',
            'reason_codes' => $reasons,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<int, string> */
    private function blindedFeasibilityReasons(array $selector): array
    {
        $screen = (array) data_get($selector, 'feasibility_screen', []);
        $gene = (string) data_get($selector, 'gene', '');
        $reasons = [];
        if (data_get($selector, 'protocol') !== CausalBlindedMutationSelectorService::PROTOCOL
            || data_get($screen, 'protocol') !== CausalParameterActivationService::MASK_PROTOCOL) {
            $reasons[] = 'BLINDED_SELECTOR_FEASIBILITY_MASK_MISSING';
        }
        if ((string) data_get($screen, 'selected_gene', '') !== $gene) {
            $reasons[] = 'BLINDED_SELECTOR_FEASIBILITY_GENE_MISMATCH';
        }
        if ((string) data_get($screen, 'status', '') === 'unsupported') {
            $reasons[] = 'BLINDED_SELECTOR_CONTEXT_UNSUPPORTED';
        }
        if ((bool) data_get($screen, 'performance_credit', true)
            || (bool) data_get($screen, 'promotion_evidence', true)) {
            $reasons[] = 'BLINDED_SELECTOR_FEASIBILITY_AUTHORITY_INVALID';
        }

        return array_values(array_unique($reasons));
    }

    private function hash(array $payload): string
    {
        ksort($payload);

        return hash('sha512', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** @return array<string, array{old:mixed,new:mixed}> */
    private function parameterDiff(array $old, array $new): array
    {
        $keys = array_values(array_unique([...array_keys($old), ...array_keys($new)]));
        $diff = [];
        foreach ($keys as $key) {
            $oldExists = array_key_exists($key, $old);
            $newExists = array_key_exists($key, $new);
            if ($oldExists !== $newExists || ($oldExists && $this->different($old[$key], $new[$key]))) {
                $diff[$key] = ['old' => $oldExists ? $old[$key] : null, 'new' => $newExists ? $new[$key] : null];
            }
        }

        return $diff;
    }

    private function different(mixed $left, mixed $right): bool
    {
        if (is_numeric($left) && is_numeric($right)) {
            return abs((float) $left - (float) $right) > 0.000000001;
        }

        return json_encode($left, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)
            !== json_encode($right, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}
