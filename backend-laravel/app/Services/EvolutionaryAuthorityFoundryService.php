<?php

namespace App\Services;

use App\Jobs\EvaluateLabAgentJob;
use App\Models\CandidateGateDecision;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\LabLearningLanePair;
use App\Models\LabSkillZooEntry;
use App\Models\ModelVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The only bridge from a confirmed causal component to evolutionary authority.
 * Research evidence remains useful, but it is never silently upgraded to a
 * parent, paper candidate, or champion claim.
 */
class EvolutionaryAuthorityFoundryService
{
    public const PROTOCOL = 'evolutionary_authority_foundry_v1';

    public const INCUBATOR_ARMS = ['frozen_control', 'single_gene_child', 'memory_blinded_child', 'skill_ablation', 'weakest_gate_repair'];

    public const DESCENDANT_ARMS = ['other_gene_child_a', 'other_gene_child_b'];

    public const DESCENDANT_ABLATION_ARMS = ['other_gene_ablation_a', 'other_gene_ablation_b'];

    public const DESCENDANT_COHORT_LIMIT = 3;

    public function __construct(
        private ParentContextTrustService $parentTrust,
        private GateMarginService $gateMargins,
        private CausalCompoundingKernelService $compoundingKernel,
        private CanonicalSkillCartridgeService $cartridges,
        private ContextualCausalTraitCapsuleService $traitCapsules,
    ) {}

    /**
     * Materialize the clean five-arm cohort from a confirmed skill. The
     * original research arm is never reused as a genetic parent; all rows are
     * explicitly research-only until the full Foundry ladder is earned.
     *
     * @return array<string,mixed>
     */
    public function materializeIncubator(LabAgent $mentor): array
    {
        if (! Schema::hasTable('skill_incubation_trials')) {
            return $this->unavailable();
        }
        $mentor->loadMissing('modelVersion', 'generation.laboratory');
        $model = $mentor->modelVersion;
        $gene = (string) data_get($model?->metadata, 'skill_mentor.parameter_key');
        if (! $model || data_get($model->metadata, 'skill_mentor.status') !== 'confirmed' || $gene === '') {
            return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason_code' => 'CONFIRMED_SKILL_MISSING', 'promotion_evidence' => false];
        }
        $existing = DB::table('skill_incubation_trials')->where('mentor_model_version_id', $model->id)
            ->whereIn('status', ['queued', 'running', 'passed'])->exists();
        if ($existing) {
            return ['protocol' => self::PROTOCOL, 'status' => 'already_materialized', 'promotion_evidence' => false];
        }
        $baselineContract = $this->resolveCausalBaseline($mentor, $model, $gene);
        if ($baselineContract === null) {
            return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason_code' => 'VERIFIED_CAUSAL_BASELINE_MISSING', 'promotion_evidence' => false];
        }
        $capsuleResolution = $this->cartridges->traitCapsuleForMentor($model, $mentor, $gene);
        if (! (bool) data_get($capsuleResolution, 'valid', false)) {
            return ['protocol' => self::PROTOCOL, 'status' => 'blocked',
                'reason_code' => 'CONTEXTUAL_TRAIT_CAPSULE_REQUIRED',
                'capsule_reasons' => (array) data_get($capsuleResolution, 'reason_codes', []),
                'promotion_evidence' => false];
        }
        $traitCapsule = (array) data_get($capsuleResolution, 'capsule', []);
        /** @var ModelVersion $baseline */
        $baseline = $baselineContract['model'];
        $lab = $mentor->generation?->laboratory;
        if (! $lab) {
            return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason_code' => 'LABORATORY_MISSING', 'promotion_evidence' => false];
        }
        $baseParameters = (array) $baseline->parameters;
        $skillParameters = (array) $model->parameters;
        if (! array_key_exists($gene, $baseParameters) || ! array_key_exists($gene, $skillParameters)) {
            return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason_code' => 'CONFIRMED_GENE_NOT_REPRODUCIBLE', 'promotion_evidence' => false];
        }
        $dataHash = (string) $baselineContract['data_hash'];
        $executionHash = (string) $baselineContract['execution_hash'];
        if ($dataHash === '' || $executionHash === '') {
            return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason_code' => 'FROZEN_DATA_OR_EXECUTION_HASH_MISSING', 'promotion_evidence' => false];
        }
        if ((int) data_get($traitCapsule, 'frozen_dependencies.causal_baseline_model_version_id', 0) !== (int) $baseline->id
            || ! hash_equals($dataHash, (string) data_get($traitCapsule, 'frozen_dependencies.data_hash', ''))
            || ! hash_equals($executionHash, (string) data_get($traitCapsule, 'frozen_dependencies.execution_hash', ''))) {
            return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason_code' => 'TRAIT_CAPSULE_BASELINE_CONTRACT_MISMATCH', 'promotion_evidence' => false];
        }

        return DB::transaction(function () use ($mentor, $model, $baseline, $baselineContract, $lab, $gene, $baseParameters, $skillParameters, $dataHash, $executionHash, $traitCapsule): array {
            $generation = LabGeneration::create([
                'ai_laboratory_id' => $lab->id, 'generation' => ((int) $lab->generations()->max('generation')) + 1,
                'trigger_type' => 'authority_incubator', 'trigger_context' => ['protocol' => self::PROTOCOL, 'mentor_model_version_id' => $model->id,
                    'baseline_source' => $baselineContract['source'], 'baseline_pair_id' => $baselineContract['pair_id'],
                    'trait_capsule_hash' => data_get($traitCapsule, 'capsule_hash'),
                    'activation_context' => data_get($traitCapsule, 'activation_context'),
                    'instrument_bundle' => data_get($traitCapsule, 'instrument_bundle'),
                    'data_hash' => $dataHash, 'execution_hash' => $executionHash, 'promotion_evidence' => false],
                'data_fingerprint' => $dataHash, 'population_size' => CausalCompoundingKernelService::POPULATION_SIZE, 'status' => 'queued', 'started_at' => now(),
            ]);
            $agents = [];
            foreach (self::INCUBATOR_ARMS as $armIndex => $arm) {
                $parameters = $this->parametersForArm($arm, $baseParameters, $skillParameters, $gene);
                if ($parameters === null) {
                    throw new \RuntimeException('INCUBATOR_ARM_CANNOT_MATERIALIZE: '.$arm);
                }
                $diff = $this->diff($baseParameters, $parameters);
                $metadata = $this->researchChildMetadata($model, 'authority_incubator', [
                    'protocol' => self::PROTOCOL, 'arm' => $arm, 'fold_stage' => 'preflight',
                    'mentor_model_version_id' => $model->id, 'baseline_model_version_id' => $baseline->id,
                    'causal_baseline_model_version_id' => $baseline->id, 'genetic_parent_model_version_id' => null, 'confirmed_gene' => $gene,
                    'trait_capsule' => $traitCapsule, 'trait_capsule_hash' => data_get($traitCapsule, 'capsule_hash'),
                    'base_strategy' => data_get($model->metadata, 'base_strategy', $mentor->strategy_family),
                    'baseline_source' => $baselineContract['source'], 'baseline_pair_id' => $baselineContract['pair_id'],
                    'data_hash' => $dataHash, 'execution_hash' => $executionHash, 'research_only' => true, 'promotion_evidence' => false,
                ]);
                $runtimeLabel = 'authority_i'.$model->id.'_g'.$generation->generation.'_a'.($armIndex + 1);
                $child = ModelVersion::create(['name' => $model->name.' authority G'.$generation->generation.' '.$arm, 'strategy' => $runtimeLabel,
                    'version' => $model->version.'-authority-'.$generation->generation.'-'.$arm, 'generation' => $generation->generation, 'status' => 'testing',
                    'description' => 'Clean authority-incubator child; research-only.', 'change_log' => 'authority incubator '.$arm,
                    'parameters' => $parameters, 'metadata' => $metadata, 'evidence_status' => 'valid']);
                $agent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $child->id,
                    // A verified causal control is experimental provenance,
                    // never a genetic parent. The explicit baseline ID lives
                    // in the immutable authority contract above.
                    'parent_a_model_version_id' => null, 'parent_b_model_version_id' => null,
                    'symbol' => $mentor->symbol, 'timeframe' => $mentor->timeframe,
                    'strategy_family' => $mentor->strategy_family, 'origin' => 'authority_incubator', 'lifecycle_status' => 'full_queued',
                    'parameter_diff' => $diff, 'decision_reason' => 'Five-arm authority incubator preflight; research-only.']);
                $this->recordIncubationArm($mentor, ['arm' => $arm, 'child_model_version_id' => $child->id, 'fold_stage' => 'preflight',
                    'status' => 'queued', 'data_hash' => $dataHash, 'execution_hash' => $executionHash,
                    'trait_capsule_hash' => data_get($traitCapsule, 'capsule_hash'),
                    'activation_context_hash' => data_get($traitCapsule, 'activation_context.context_hash'),
                    'instrument_bundle_hash' => data_get($traitCapsule, 'instrument_bundle.bundle_hash'),
                    'single_component_change' => $arm === 'frozen_control' ? $diff === [] : count($diff) === 1,
                    'target_gate_improved' => false, 'non_target_regression' => false, 'window_keys' => []]);
                $agents[] = $agent;
            }
            $kernel = $this->compoundingKernel->complete(
                $generation,
                $baseline,
                $mentor,
                $dataHash,
                $executionHash,
                (string) data_get($model->metadata, 'skill_mentor.target', 'profit_factor'),
                [$gene],
            );
            foreach ($agents as $agent) {
                EvaluateLabAgentJob::dispatch($agent->id, $agent->symbol, 'full')->afterCommit();
            }
            foreach ($kernel['dispatches'] as $dispatch) {
                EvaluateLabAgentJob::dispatch(
                    $dispatch['agent']->id,
                    $dispatch['agent']->symbol,
                    $dispatch['mode'],
                )->afterCommit();
            }

            return ['protocol' => self::PROTOCOL, 'status' => 'queued', 'generation_id' => $generation->id,
                'agent_ids' => collect([...$agents, ...$kernel['agents']])->pluck('id')->all(),
                'population_size' => CausalCompoundingKernelService::POPULATION_SIZE,
                'compounding_kernel' => $kernel['contract'], 'promotion_evidence' => false];
        });
    }

    public function hasVerifiedCausalBaseline(LabAgent $mentor): bool
    {
        $mentor->loadMissing('modelVersion');
        $gene = (string) data_get($mentor->modelVersion?->metadata, 'skill_mentor.parameter_key');

        return $mentor->modelVersion !== null && $gene !== ''
            && $this->resolveCausalBaseline($mentor, $mentor->modelVersion, $gene) !== null;
    }

    /**
     * Create a frozen mentor control plus two independently selected children
     * and their matched trait-ablated siblings. The child may earn heredity
     * credit only when T+M beats the same M without T; merely beating the
     * mentor is not evidence that the confirmed trait caused the improvement.
     *
     * @return array<string,mixed>
     */
    public function materializeDescendantCohort(ModelVersion $mentor, ?LabAgent $scopeAgent = null): array
    {
        if (! Schema::hasTable('descendant_value_trials')) {
            return $this->unavailable();
        }
        $scopeAgent = LabAgent::query()->where('model_version_id', $mentor->id)
            ->when($scopeAgent, fn ($query) => $query->where('symbol', $scopeAgent->symbol)->where('timeframe', $scopeAgent->timeframe))
            ->latest('id')->first() ?: $scopeAgent;
        $scopeAgent?->loadMissing('generation.laboratory');
        $lab = $scopeAgent?->generation?->laboratory;
        $gene = (string) data_get($mentor->metadata, 'skill_mentor.parameter_key');
        $target = (string) data_get($mentor->metadata, 'skill_mentor.target', 'profit_factor');
        if (! $lab || $gene === '' || data_get($mentor->metadata, 'skill_mentor.status') !== 'confirmed') {
            return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason_code' => 'DESCENDANT_MENTOR_SCOPE_MISSING', 'promotion_evidence' => false];
        }
        $authority = $this->refreshAuthority($mentor, $scopeAgent);
        if (! in_array(data_get($authority, 'stage'), ['skill_mentor', 'breeder_candidate', 'eligible_parent'], true)) {
            return [...$authority, 'status' => 'blocked', 'reason_code' => 'INCUBATION_AUTHORITY_REQUIRED', 'promotion_evidence' => false];
        }
        $traitCapsule = (array) data_get($authority, 'evidence.trait_capsule', []);
        $capsuleAssessment = $this->traitCapsules->assess($traitCapsule, $gene);
        if (! (bool) data_get($capsuleAssessment, 'valid', false)) {
            return [...$authority, 'status' => 'blocked', 'reason_code' => 'DESCENDANT_CONTEXTUAL_TRAIT_CAPSULE_INVALID',
                'capsule_reasons' => (array) data_get($capsuleAssessment, 'reason_codes', []), 'promotion_evidence' => false];
        }
        if (data_get($authority, 'stage') === 'eligible_parent') {
            return [...$authority, 'status' => 'already_proven', 'promotion_evidence' => false];
        }
        $existing = LabGeneration::query()->where('ai_laboratory_id', $lab->id)->where('trigger_type', 'authority_descendant')->get()
            ->filter(fn (LabGeneration $generation): bool => (int) data_get($generation->trigger_context, 'mentor_model_version_id') === (int) $mentor->id)
            ->sortByDesc('id')->values();
        $active = $existing->first(fn (LabGeneration $generation): bool => in_array($generation->status, [
            'draft', 'queued', 'training', 'screening', 'full_queued', 'full_validation',
        ], true));
        if ($active) {
            return [...$authority, 'status' => 'already_materialized', 'generation_id' => $active->id, 'promotion_evidence' => false];
        }
        if ($existing->count() >= self::DESCENDANT_COHORT_LIMIT) {
            return [...$authority, 'status' => 'search_exhausted', 'reason_code' => 'BOUNDED_DESCENDANT_SEARCH_EXHAUSTED',
                'cohorts_completed' => $existing->count(), 'promotion_evidence' => false];
        }

        $incubation = DB::table('skill_incubation_trials')->where('mentor_model_version_id', $mentor->id)
            ->where('fold_stage', 'final')->where('status', 'passed')->get();
        $dataHashes = $incubation->pluck('data_hash')->filter()->unique()->values();
        $executionHashes = $incubation->pluck('execution_hash')->filter()->unique()->values();
        if ($dataHashes->count() !== 1 || $executionHashes->count() !== 1) {
            return [...$authority, 'status' => 'blocked', 'reason_code' => 'DESCENDANT_FROZEN_CONTRACT_AMBIGUOUS', 'promotion_evidence' => false];
        }

        $base = (array) $mentor->parameters;
        $causalBaseline = $this->resolveCausalBaseline($scopeAgent, $mentor, $gene);
        if ($causalBaseline === null) {
            return [...$authority, 'status' => 'blocked', 'reason_code' => 'DESCENDANT_CAUSAL_ABLATION_BASELINE_MISSING', 'promotion_evidence' => false];
        }
        $ablatedBase = (array) $causalBaseline['model']->parameters;
        $schemaService = app(StrategyParameterSchemaService::class);
        $runtimeFamily = (string) $scopeAgent->strategy_family;
        $testedGenes = $existing->flatMap(fn (LabGeneration $generation): array => (array) data_get($generation->trigger_context, 'mutated_genes', []))->filter()->unique()->values()->all();
        $allowed = array_values(array_diff(
            array_intersect(array_keys($base), array_keys($schemaService->schema($runtimeFamily))),
            [$gene, ...$testedGenes],
        ));
        $selections = [];
        foreach (self::DESCENDANT_ARMS as $index => $arm) {
            $selection = app(CausalBlindedMutationSelectorService::class)->select(
                $runtimeFamily, $target, $base, self::PROTOCOL.'|'.$mentor->id.'|'.$arm, null, null, [], $allowed,
            );
            if (! $selection) {
                return [...$authority, 'status' => 'blocked', 'reason_code' => 'INDEPENDENT_OTHER_GENE_UNAVAILABLE', 'promotion_evidence' => false];
            }
            $parameters = $base;
            $parameters[$selection['gene']] = $selection['value'];
            $parameters = $schemaService->normalizeForGeneration($runtimeFamily, $parameters);
            try {
                $parameters = $schemaService->validate($runtimeFamily, $parameters);
            } catch (\InvalidArgumentException) {
                return [...$authority, 'status' => 'blocked', 'reason_code' => 'DESCENDANT_SCHEMA_INVALID', 'promotion_evidence' => false];
            }
            $diff = $this->diff($base, $parameters);
            if (count($diff) !== 1 || array_key_first($diff) !== $selection['gene']) {
                return [...$authority, 'status' => 'blocked', 'reason_code' => 'DESCENDANT_SINGLE_OTHER_GENE_INVARIANT_FAILED', 'promotion_evidence' => false];
            }
            $ablationParameters = $ablatedBase;
            $ablationParameters[$selection['gene']] = $selection['value'];
            $ablationParameters = $schemaService->normalizeForGeneration($runtimeFamily, $ablationParameters);
            try {
                $ablationParameters = $schemaService->validate($runtimeFamily, $ablationParameters);
            } catch (\InvalidArgumentException) {
                return [...$authority, 'status' => 'blocked', 'reason_code' => 'DESCENDANT_ABLATION_SCHEMA_INVALID', 'promotion_evidence' => false];
            }
            $ablationDiff = $this->diff($ablatedBase, $ablationParameters);
            if (count($ablationDiff) !== 1 || array_key_first($ablationDiff) !== $selection['gene']) {
                return [...$authority, 'status' => 'blocked', 'reason_code' => 'DESCENDANT_MATCHED_ABLATION_INVARIANT_FAILED', 'promotion_evidence' => false];
            }
            $suffix = str_ends_with($arm, '_a') ? 'a' : 'b';
            $selections[$arm] = ['selection' => $selection, 'parameters' => $parameters, 'diff' => $diff,
                'paired_ablation_arm' => 'other_gene_ablation_'.$suffix];
            $selections['other_gene_ablation_'.$suffix] = ['selection' => $selection, 'parameters' => $ablationParameters,
                'diff' => $this->diff($base, $ablationParameters), 'paired_child_arm' => $arm, 'trait_ablated' => true];
            $allowed = array_values(array_diff($allowed, [$selection['gene']]));
        }

        $trustContext = $this->parentTrust->context([
            ...(array) data_get($traitCapsule, 'activation_context.predicate', []),
            // ParentContextTrust currently stores a bounded subset; the full
            // canonical predicate remains sealed in the capsule itself.
            'cost_stress' => data_get($traitCapsule, 'activation_context.predicate.spread_liquidity_state', 'normal'),
        ]);

        /** @var ModelVersion $discoveryBaseline */
        $discoveryBaseline = $causalBaseline['model'];

        return DB::transaction(function () use ($mentor, $scopeAgent, $lab, $gene, $target, $base, $selections, $dataHashes, $executionHashes, $existing, $testedGenes, $trustContext, $discoveryBaseline, $traitCapsule): array {
            $dataHash = (string) $dataHashes->first();
            $executionHash = (string) $executionHashes->first();
            $generation = LabGeneration::create([
                'ai_laboratory_id' => $lab->id,
                'generation' => ((int) $lab->generations()->max('generation')) + 1,
                'trigger_type' => 'authority_descendant',
                'trigger_context' => ['protocol' => self::PROTOCOL, 'mentor_model_version_id' => $mentor->id,
                    'confirmed_gene' => $gene, 'target' => $target, 'data_hash' => $dataHash,
                    'execution_hash' => $executionHash, 'cohort_attempt' => $existing->count() + 1,
                    'prior_tested_genes' => $testedGenes,
                    'mutated_genes' => collect($selections)->pluck('selection.gene')->values()->all(),
                    'parent_trust_context' => $trustContext,
                    'trait_capsule_hash' => data_get($traitCapsule, 'capsule_hash'),
                    'activation_context' => data_get($traitCapsule, 'activation_context'),
                    'instrument_bundle' => data_get($traitCapsule, 'instrument_bundle'),
                    'research_only' => true, 'promotion_evidence' => false],
                'data_fingerprint' => $dataHash, 'population_size' => CausalCompoundingKernelService::POPULATION_SIZE,
                'status' => 'queued', 'started_at' => now(),
            ]);
            $arms = ['mentor_control' => ['parameters' => $base, 'diff' => [], 'selection' => null], ...$selections];
            $agents = [];
            foreach ($arms as $arm => $definition) {
                $contract = ['protocol' => self::PROTOCOL, 'arm' => $arm, 'mentor_model_version_id' => $mentor->id,
                    'causal_baseline_model_version_id' => $discoveryBaseline->id,
                    'genetic_parent_model_version_id' => $mentor->id,
                    'base_strategy' => data_get($mentor->metadata, 'base_strategy', $scopeAgent->strategy_family),
                    'confirmed_gene' => $gene, 'mutated_gene' => data_get($definition, 'selection.gene'),
                    'trait_capsule' => $traitCapsule, 'trait_capsule_hash' => data_get($traitCapsule, 'capsule_hash'),
                    'trait_ablated' => (bool) data_get($definition, 'trait_ablated', false),
                    'paired_ablation_arm' => data_get($definition, 'paired_ablation_arm'),
                    'paired_child_arm' => data_get($definition, 'paired_child_arm'),
                    'target' => $target, 'data_hash' => $dataHash, 'execution_hash' => $executionHash,
                    'parent_trust_context' => $trustContext,
                    'selection' => $definition['selection'], 'research_only' => true, 'promotion_evidence' => false];
                $child = ModelVersion::create([
                    'name' => $mentor->name.' descendant G'.$generation->generation.' '.$arm,
                    'strategy' => 'authority_d'.$mentor->id.'_g'.$generation->generation.'_'.str_replace('_', '', $arm),
                    'version' => $mentor->version.'-descendant-'.$generation->generation.'-'.$arm,
                    'generation' => $generation->generation, 'status' => 'testing',
                    'description' => 'Prospective authority descendant; research-only until settled.',
                    'change_log' => 'authority descendant '.$arm, 'parameters' => $definition['parameters'],
                    'metadata' => $this->researchChildMetadata($mentor, 'authority_descendant', $contract), 'evidence_status' => 'valid',
                ]);
                $agents[] = LabAgent::create([
                    'lab_generation_id' => $generation->id, 'model_version_id' => $child->id,
                    'parent_a_model_version_id' => null, 'parent_b_model_version_id' => null,
                    'symbol' => $scopeAgent->symbol, 'timeframe' => $scopeAgent->timeframe,
                    'strategy_family' => $scopeAgent->strategy_family, 'origin' => 'authority_descendant',
                    'lifecycle_status' => 'full_queued', 'parameter_diff' => $definition['diff'],
                    'decision_reason' => data_get($definition, 'trait_ablated')
                        ? 'Matched descendant sibling with the confirmed trait removed; research-only.'
                        : 'Confirmed component frozen; one other gene tested against a matched trait ablation.',
                ]);
            }
            $kernel = $this->compoundingKernel->complete(
                $generation,
                $discoveryBaseline,
                $scopeAgent,
                $dataHash,
                $executionHash,
                $target,
                [$gene, ...collect($selections)->pluck('selection.gene')->filter()->all()],
            );
            foreach ($agents as $agent) {
                EvaluateLabAgentJob::dispatch($agent->id, $agent->symbol, 'full')->afterCommit();
            }
            foreach ($kernel['dispatches'] as $dispatch) {
                EvaluateLabAgentJob::dispatch(
                    $dispatch['agent']->id,
                    $dispatch['agent']->symbol,
                    $dispatch['mode'],
                )->afterCommit();
            }

            return ['protocol' => self::PROTOCOL, 'status' => 'queued', 'generation_id' => $generation->id,
                'agent_ids' => collect([...$agents, ...$kernel['agents']])->pluck('id')->all(),
                'population_size' => CausalCompoundingKernelService::POPULATION_SIZE,
                'compounding_kernel' => $kernel['contract'], 'promotion_evidence' => false];
        });
    }

    /** @return array<string,mixed> */
    public function recordIncubationArm(LabAgent $mentor, array $trial, ?ModelVersion $authoritySource = null): array
    {
        if (! Schema::hasTable('skill_incubation_trials')) {
            return $this->unavailable();
        }
        $mentor->loadMissing('modelVersion');
        $model = $authoritySource ?: $mentor->modelVersion;
        $arm = (string) ($trial['arm'] ?? '');
        if (! $model || ! in_array($arm, self::INCUBATOR_ARMS, true)) {
            return ['protocol' => self::PROTOCOL, 'status' => 'rejected', 'reason_code' => 'INCUBATOR_ARM_INVALID', 'promotion_evidence' => false];
        }
        $dataHash = (string) ($trial['data_hash'] ?? '');
        $executionHash = (string) ($trial['execution_hash'] ?? '');
        $fold = (string) ($trial['fold_stage'] ?? 'preflight');
        $status = (string) ($trial['status'] ?? 'pending');
        $windowKeys = array_values(array_unique(array_filter(array_map('strval', (array) ($trial['window_keys'] ?? [])))));
        $evidence = [
            'protocol' => self::PROTOCOL, 'control_hashes_bound' => $dataHash !== '' && $executionHash !== '',
            'trait_capsule_hash' => $trial['trait_capsule_hash'] ?? null,
            'activation_context_hash' => $trial['activation_context_hash'] ?? null,
            'instrument_bundle_hash' => $trial['instrument_bundle_hash'] ?? null,
            'single_component_change' => (bool) ($trial['single_component_change'] ?? false),
            'target_gate_improved' => (bool) ($trial['target_gate_improved'] ?? false),
            'non_target_regression' => (bool) ($trial['non_target_regression'] ?? true),
            'contextual_control_comparison' => (array) ($trial['contextual_control_comparison'] ?? []),
            'window_keys' => $windowKeys, 'settlement_id' => $trial['settlement_id'] ?? null,
            'source_confirmed' => data_get($model->metadata, 'skill_mentor.status') === 'confirmed',
            'promotion_evidence' => false,
        ];
        $key = hash('sha256', json_encode([self::PROTOCOL, $model->id, $arm, $fold, $dataHash, $executionHash,
            $evidence['trait_capsule_hash'], $windowKeys], JSON_UNESCAPED_SLASHES));
        DB::table('skill_incubation_trials')->updateOrInsert(['trial_key' => $key], [
            'mentor_model_version_id' => $model->id, 'child_model_version_id' => $trial['child_model_version_id'] ?? null, 'lab_agent_id' => $mentor->id,
            'symbol' => strtoupper($mentor->symbol), 'timeframe' => strtoupper($mentor->timeframe), 'strategy_family' => $mentor->strategy_family,
            'arm' => $arm, 'fold_stage' => $fold, 'status' => $status, 'data_hash' => $dataHash ?: null, 'execution_hash' => $executionHash ?: null,
            'evidence' => json_encode($evidence), 'settled_at' => $status === 'passed' ? now() : null, 'updated_at' => now(), 'created_at' => now(),
        ]);

        return $this->refreshAuthority($model, $mentor);
    }

    /** @return array<string,mixed> */
    public function recordDescendantTrial(ModelVersion $mentor, ModelVersion $child, string $symbol, string $timeframe, array $trial): array
    {
        if (! Schema::hasTable('descendant_value_trials')) {
            return $this->unavailable();
        }
        $window = (string) ($trial['window_key'] ?? '');
        if ($window === '') {
            return ['protocol' => self::PROTOCOL, 'status' => 'rejected', 'reason_code' => 'DESCENDANT_WINDOW_REQUIRED', 'promotion_evidence' => false];
        }
        $evidence = [
            'protocol' => self::PROTOCOL,
            'trait_capsule_hash' => $trial['trait_capsule_hash'] ?? null,
            'activation_context_hash' => $trial['activation_context_hash'] ?? null,
            'instrument_bundle_hash' => $trial['instrument_bundle_hash'] ?? null,
            'confirmed_component_only' => (bool) ($trial['confirmed_component_only'] ?? false),
            'other_gene_mutated' => (bool) ($trial['other_gene_mutated'] ?? false),
            'improved_over_mentor' => (bool) ($trial['improved_over_mentor'] ?? false),
            'trait_incremental_over_ablation' => (bool) ($trial['trait_incremental_over_ablation'] ?? false),
            'ablated_child_model_version_id' => $trial['ablated_child_model_version_id'] ?? null,
            'inherited_failure' => (bool) ($trial['inherited_failure'] ?? true),
            'regime_coverage' => (float) ($trial['regime_coverage'] ?? 0),
            'diversity_contribution' => (float) ($trial['diversity_contribution'] ?? 0),
            'uncertainty' => (float) ($trial['uncertainty'] ?? 1),
            'constraint_violations' => (int) ($trial['constraint_violations'] ?? 1),
            'independent_windows' => (int) ($trial['independent_windows'] ?? 0),
            'forward_gate_passed' => (bool) ($trial['forward_gate_passed'] ?? false),
            'context_trust' => (array) ($trial['context_trust'] ?? []),
            'contextual_control_comparison' => (array) ($trial['contextual_control_comparison'] ?? []),
            'contextual_trait_ablation_comparison' => (array) ($trial['contextual_trait_ablation_comparison'] ?? []),
            'promotion_evidence' => false,
        ];
        $capsuleBound = filled($evidence['trait_capsule_hash'])
            && filled($evidence['activation_context_hash'])
            && filled($evidence['instrument_bundle_hash']);
        $status = $evidence['confirmed_component_only'] && $evidence['other_gene_mutated'] && $capsuleBound
            ? (string) ($trial['status'] ?? 'settled')
            : 'invalid';
        DB::table('descendant_value_trials')->updateOrInsert([
            'mentor_model_version_id' => $mentor->id, 'child_model_version_id' => $child->id, 'window_key' => $window,
        ], [
            'trial_key' => hash('sha256', implode('|', [self::PROTOCOL, $mentor->id, $child->id, $window])),
            'symbol' => strtoupper($symbol), 'timeframe' => strtoupper($timeframe), 'strategy_family' => (string) ($trial['strategy_family'] ?? $mentor->strategy),
            'status' => $status, 'evidence' => json_encode($evidence), 'settled_at' => $status === 'settled' ? now() : null, 'updated_at' => now(), 'created_at' => now(),
        ]);
        $mentorAgent = LabAgent::query()->where('model_version_id', $mentor->id)
            ->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))
            ->latest('id')->first();

        return $this->refreshAuthority($mentor, $mentorAgent);
    }

    /**
     * Settle every evaluable member of a descendant cohort. If the control
     * finishes last, this call also closes children that were waiting for it.
     *
     * @return array<string,mixed>
     */
    public function settleDescendantOutcome(LabAgent $agent, array $result, ?CandidateGateDecision $forwardDecision = null): array
    {
        $agent->loadMissing('modelVersion', 'generation.agents.modelVersion');
        $contract = (array) data_get($agent->modelVersion?->metadata, 'authority_descendant', []);
        if (data_get($contract, 'protocol') !== self::PROTOCOL) {
            return ['protocol' => self::PROTOCOL, 'status' => 'not_descendant', 'promotion_evidence' => false];
        }
        $mentor = ModelVersion::find((int) data_get($contract, 'mentor_model_version_id'));
        if (! $mentor) {
            return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason_code' => 'DESCENDANT_MENTOR_MISSING', 'promotion_evidence' => false];
        }
        $generation = $agent->generation;
        $control = $generation?->agents->first(fn (LabAgent $candidate): bool => data_get($candidate->modelVersion?->metadata, 'authority_descendant.arm') === 'mentor_control');
        $controlMetrics = $control && $control->id === $agent->id
            ? $result
            : (array) $control?->modelVersion?->marketPerformances()->where('symbol', $agent->symbol)
                ->where('timeframe', $agent->timeframe)->latest('id')->value('metrics');
        $controlContract = (array) data_get($control?->modelVersion?->metadata, 'authority_descendant', []);
        if (! $control || $controlMetrics === [] || ! $this->contractHashesMatch($controlContract, $controlMetrics)
            || ! $this->capsuleExecutionMatches($controlContract, $controlMetrics)) {
            return ['protocol' => self::PROTOCOL, 'status' => 'awaiting_verified_control', 'promotion_evidence' => false];
        }

        $settled = 0;
        $waiting = 0;
        $target = (string) data_get($contract, 'target', data_get($mentor->metadata, 'skill_mentor.target', 'profit_factor'));
        $confirmedGene = (string) data_get($contract, 'confirmed_gene');
        $causalBaseline = $this->resolveCausalBaseline(
            LabAgent::query()->where('model_version_id', $mentor->id)->where('symbol', $agent->symbol)->where('timeframe', $agent->timeframe)->latest('id')->first() ?: $agent,
            $mentor,
            $confirmedGene,
        );
        if ($causalBaseline === null) {
            return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason_code' => 'DESCENDANT_CAUSAL_ABLATION_BASELINE_MISSING', 'promotion_evidence' => false];
        }
        foreach ($generation->agents as $childAgent) {
            $childContract = (array) data_get($childAgent->modelVersion?->metadata, 'authority_descendant', []);
            if (! in_array(data_get($childContract, 'arm'), self::DESCENDANT_ARMS, true)) {
                continue;
            }
            $ablation = $generation->agents->first(fn (LabAgent $candidate): bool => data_get($candidate->modelVersion?->metadata, 'authority_descendant.arm') === data_get($childContract, 'paired_ablation_arm')
            );
            $metrics = $childAgent->id === $agent->id
                ? $result
                : (array) $childAgent->modelVersion?->marketPerformances()->where('symbol', $childAgent->symbol)
                    ->where('timeframe', $childAgent->timeframe)->latest('id')->value('metrics');
            $ablationMetrics = (array) $ablation?->modelVersion?->marketPerformances()->where('symbol', $childAgent->symbol)
                ->where('timeframe', $childAgent->timeframe)->latest('id')->value('metrics');
            $decision = $childAgent->id === $agent->id && $forwardDecision
                ? $forwardDecision
                : CandidateGateDecision::query()->where('lab_agent_id', $childAgent->id)
                    ->where('stage', 'statistical_forward_gate')->latest('evaluated_at')->first();
            if (! $ablation || $metrics === [] || $ablationMetrics === [] || ! $decision) {
                $waiting++;

                continue;
            }
            $windowKeys = array_values(array_unique(array_filter(array_map('strval',
                (array) data_get($metrics, 'forward_window_protocol.window_keys', [])))));
            $observedWindows = max((int) data_get($metrics, 'forward_window_protocol.observed_windows', 0), count($windowKeys));
            $positiveWindows = (int) data_get($metrics, 'forward_window_protocol.positive_windows', 0);
            $independent = data_get($metrics, 'forward_window_protocol.independence_verified') === true
                && ! (bool) data_get($metrics, 'forward_window_protocol.overlap_detected', true);
            $scientificReasons = array_values(array_diff((array) $decision->reason_codes, ['AUTHORITY_FOUNDRY_RESEARCH_ONLY']));
            $forwardPassed = $scientificReasons === [] && $independent && $observedWindows >= 3 && $positiveWindows >= 3;
            $diff = $this->diff((array) $mentor->parameters, (array) $childAgent->modelVersion->parameters);
            $mutatedGene = (string) data_get($childContract, 'mutated_gene');
            $componentPreserved = $confirmedGene !== ''
                && array_key_exists($confirmedGene, (array) $mentor->parameters)
                && data_get($childAgent->modelVersion->parameters, $confirmedGene) === data_get($mentor->parameters, $confirmedGene);
            $otherGeneMutated = count($diff) === 1 && array_key_first($diff) === $mutatedGene && $mutatedGene !== $confirmedGene;
            $hashesMatch = $this->contractHashesMatch($childContract, $metrics)
                && $this->capsuleExecutionMatches($childContract, $metrics);
            $contextControlComparison = $this->contextualTargetComparison(
                $target,
                $metrics,
                $controlMetrics,
                (array) data_get($childContract, 'trait_capsule', []),
            );
            // Reproductive credit is deliberately stricter than a global
            // replay win: the child must improve both the full organism and
            // the exact activation cell carried by the capsule.
            $improved = $this->targetImproved($target, $metrics, $controlMetrics)
                && (bool) data_get($contextControlComparison, 'eligible', false);
            $nonTargetRegression = $this->nonTargetRegression($metrics, $controlMetrics);
            $ablationContract = (array) data_get($ablation->modelVersion?->metadata, 'authority_descendant', []);
            $ablationHashesMatch = $this->contractHashesMatch($ablationContract, $ablationMetrics)
                && $this->capsuleExecutionMatches($ablationContract, $ablationMetrics)
                && hash_equals((string) data_get($childContract, 'trait_capsule_hash', ''), (string) data_get($ablationContract, 'trait_capsule_hash', ''))
                && hash_equals((string) data_get($childContract, 'trait_capsule_hash', ''), (string) data_get($controlContract, 'trait_capsule_hash', ''));
            $ablationDiff = $this->diff((array) $causalBaseline['model']->parameters, (array) $ablation->modelVersion?->parameters);
            $ablationMatched = count($ablationDiff) === 1 && array_key_first($ablationDiff) === $mutatedGene
                && data_get($ablation->modelVersion?->parameters, $confirmedGene) === data_get($causalBaseline['model']->parameters, $confirmedGene);
            $contextAblationComparison = $this->contextualTargetComparison(
                $target,
                $metrics,
                $ablationMetrics,
                (array) data_get($childContract, 'trait_capsule', []),
            );
            $traitIncremental = $this->targetImproved($target, $metrics, $ablationMetrics)
                && ! $this->nonTargetRegression($metrics, $ablationMetrics)
                && (bool) data_get($contextAblationComparison, 'eligible', false);
            $constraints = ($hashesMatch ? 0 : 1) + ($ablationHashesMatch ? 0 : 1) + ($componentPreserved ? 0 : 1)
                + ($otherGeneMutated ? 0 : 1) + ($ablationMatched ? 0 : 1) + ($traitIncremental ? 0 : 1)
                + ($nonTargetRegression ? 1 : 0);
            $windowKey = hash('sha256', json_encode($windowKeys !== [] ? $windowKeys : [data_get($metrics, 'evidence_run_id', 'missing')], JSON_UNESCAPED_SLASHES));
            $comparison = $this->gateMargins->compare($metrics, $ablationMetrics, $target);
            $trustEvidenceValid = $hashesMatch && $ablationHashesMatch && $componentPreserved
                && $otherGeneMutated && $ablationMatched && $independent && $observedWindows >= 3
                && data_get($contextAblationComparison, 'status') === 'powered_exact_context'
                && is_numeric(data_get($comparison, 'margin_delta'));
            $trustOutcome = ! $trustEvidenceValid
                ? 'uncertainty'
                : ($traitIncremental
                    ? 'positive'
                    : ((data_get($comparison, 'candidate_better') === false
                        || $this->nonTargetRegression($metrics, $ablationMetrics)) ? 'negative' : 'uncertainty'));
            $trust = $this->parentTrust->record(
                $mentor,
                $childAgent->symbol,
                $childAgent->timeframe,
                $childAgent->strategy_family,
                $confirmedGene,
                (array) data_get($childContract, 'parent_trust_context', []),
                $trustOutcome,
                is_numeric(data_get($comparison, 'margin_delta')) ? (float) data_get($comparison, 'margin_delta') : 0.0,
                [
                    'protocol' => self::PROTOCOL,
                    'evidence_run_id' => hash('sha256', implode('|', [self::PROTOCOL, $mentor->id, $childAgent->model_version_id,
                        (int) $ablation->model_version_id, $windowKey])),
                    'counterfactual_status' => 'matched_trait_ablation_'.$trustOutcome,
                    'child_model_version_id' => (int) $childAgent->model_version_id,
                    'ablated_child_model_version_id' => (int) $ablation->model_version_id,
                    'window_key' => $windowKey,
                    'comparison' => $comparison,
                    'contextual_control_comparison' => $contextControlComparison,
                    'contextual_trait_ablation_comparison' => $contextAblationComparison,
                    'promotion_evidence' => false,
                ],
            );
            $this->recordDescendantTrial($mentor, $childAgent->modelVersion, $childAgent->symbol, $childAgent->timeframe, [
                'window_key' => $windowKey, 'strategy_family' => $childAgent->strategy_family,
                'confirmed_component_only' => $componentPreserved, 'other_gene_mutated' => $otherGeneMutated,
                'improved_over_mentor' => $improved, 'inherited_failure' => ! $forwardPassed,
                'trait_incremental_over_ablation' => $traitIncremental,
                'trait_capsule_hash' => data_get($childContract, 'trait_capsule_hash'),
                'activation_context_hash' => data_get($childContract, 'trait_capsule.activation_context.context_hash'),
                'instrument_bundle_hash' => data_get($childContract, 'trait_capsule.instrument_bundle.bundle_hash'),
                'ablated_child_model_version_id' => $ablation->model_version_id,
                'regime_coverage' => (float) data_get($metrics, 'statistical_evidence.edge_quality.worst_regime_pf', 0),
                'diversity_contribution' => $otherGeneMutated ? 1.0 : 0.0,
                'uncertainty' => $observedWindows > 0 ? max(0.0, 1.0 - ($positiveWindows / $observedWindows)) : 1.0,
                'constraint_violations' => $constraints, 'independent_windows' => $observedWindows,
                'forward_gate_passed' => $forwardPassed, 'context_trust' => $trust, 'status' => 'settled',
                'contextual_control_comparison' => $contextControlComparison,
                'contextual_trait_ablation_comparison' => $contextAblationComparison,
            ]);
            $settled++;
        }
        $mentorAgent = LabAgent::query()->where('model_version_id', $mentor->id)
            ->where('symbol', $agent->symbol)->where('timeframe', $agent->timeframe)->latest('id')->first();
        $authority = $this->refreshAuthority($mentor, $mentorAgent);

        return [...$authority, 'status' => $waiting > 0 ? 'partially_settled' : 'settled',
            'settled_children' => $settled, 'waiting_children' => $waiting, 'promotion_evidence' => false];
    }

    /** Advance one incubator child only after its immutable replay outcome is available. */
    public function settleIncubatorOutcome(LabAgent $agent, array $result): array
    {
        $agent->loadMissing('modelVersion', 'generation.agents.modelVersion');
        $contract = (array) data_get($agent->modelVersion?->metadata, 'authority_incubator', []);
        if (data_get($contract, 'protocol') !== self::PROTOCOL) {
            return ['status' => 'not_incubator', 'promotion_evidence' => false];
        }
        $mentor = ModelVersion::find((int) data_get($contract, 'mentor_model_version_id'));
        if (! $mentor) {
            return ['status' => 'blocked', 'reason_code' => 'INCUBATOR_MENTOR_MISSING', 'promotion_evidence' => false];
        }
        $expectedData = (string) data_get($contract, 'data_hash');
        $expectedExecution = (string) data_get($contract, 'execution_hash');
        $dataHash = (string) data_get($result, 'data_manifest.sha256', data_get($result, 'data_hash'));
        $executionHash = (string) data_get($result, 'execution_contract.execution_hash', data_get($result, 'execution_hash'));
        $stage = (string) data_get($contract, 'fold_stage', 'preflight');
        $arm = (string) data_get($contract, 'arm');
        $windows = array_values(array_unique(array_filter(array_map('strval', (array) data_get($result, 'forward_window_protocol.window_keys', [])))));
        if ($windows === [] && filled(data_get($result, 'evidence_run_id'))) {
            $windows = [(string) data_get($result, 'evidence_run_id')];
        }
        $control = $agent->generation?->agents->first(fn (LabAgent $candidate): bool => data_get($candidate->modelVersion?->metadata, 'authority_incubator.arm') === 'frozen_control');
        $controlMetrics = (array) $control?->modelVersion?->marketPerformances()->where('symbol', $agent->symbol)->where('timeframe', $agent->timeframe)->latest('id')->value('metrics');
        $target = (string) data_get($mentor->metadata, 'skill_mentor.target', 'profit_factor');
        $contextualComparison = $this->contextualTargetComparison(
            $target,
            $result,
            $controlMetrics,
            (array) data_get($contract, 'trait_capsule', []),
        );
        $targetImproved = $this->targetImproved($target, $result, $controlMetrics)
            && (bool) data_get($contextualComparison, 'eligible', false);
        $nonTargetRegression = $this->nonTargetRegression($result, $controlMetrics);
        $controlArm = in_array($arm, ['frozen_control', 'skill_ablation'], true);
        $passed = $expectedData !== '' && $expectedExecution !== '' && hash_equals($expectedData, $dataHash) && hash_equals($expectedExecution, $executionHash)
            && (int) data_get($result, 'total_trades', 0) > 0 && ! (bool) data_get($result, 'is_overfit', false)
            && $this->capsuleExecutionMatches($contract, $result)
            && ($controlArm || ($targetImproved && ! $nonTargetRegression));
        $authority = $this->recordIncubationArm($agent, [
            'arm' => $arm, 'child_model_version_id' => $agent->model_version_id, 'fold_stage' => $stage, 'status' => $passed ? 'passed' : 'failed',
            'data_hash' => $dataHash, 'execution_hash' => $executionHash, 'single_component_change' => count((array) $agent->parameter_diff) === 1,
            'trait_capsule_hash' => data_get($contract, 'trait_capsule_hash'),
            'activation_context_hash' => data_get($contract, 'trait_capsule.activation_context.context_hash'),
            'instrument_bundle_hash' => data_get($contract, 'trait_capsule.instrument_bundle.bundle_hash'),
            'target_gate_improved' => $targetImproved, 'non_target_regression' => $nonTargetRegression, 'window_keys' => $windows,
            'contextual_control_comparison' => $contextualComparison,
            'settlement_id' => data_get($result, 'evidence_run_id'),
        ], $mentor);
        if (! $passed || $stage === 'final') {
            $descendants = $passed && $stage === 'final' && data_get($authority, 'stage') === 'skill_mentor'
                ? [
                    'status' => 'automatic_handoff_ready',
                    'next_action' => 'director_materializes_unified_twenty_seat_descendant_population_after_current_generation_is_terminal',
                    'mentor_model_version_id' => (int) $mentor->id,
                    'population_size' => CausalCompoundingKernelService::POPULATION_SIZE,
                    'protocol' => CausalCompoundingKernelService::PROTOCOL,
                    'promotion_evidence' => false,
                ]
                : null;

            return [...$authority, 'status' => $passed ? 'final_settled' : 'failed',
                'descendant_cohort' => $descendants, 'promotion_evidence' => false];
        }
        $next = $stage === 'preflight' ? 'interim' : 'final';
        $metadata = (array) $agent->modelVersion->metadata;
        data_set($metadata, 'authority_incubator.fold_stage', $next);
        $agent->modelVersion->update(['metadata' => $metadata]);
        $agent->update(['lifecycle_status' => 'full_queued', 'decision_reason' => 'Authority incubator '.$next.' replay queued; research-only.']);
        EvaluateLabAgentJob::dispatch($agent->id, $agent->symbol, 'full')->afterCommit();

        return [...$authority, 'status' => 'advanced_'.$next, 'promotion_evidence' => false];
    }

    /** Immutable promotion decision; callers may persist it but must not bypass it. */
    public function refreshAuthority(ModelVersion $model, ?LabAgent $agent = null, array $passport = []): array
    {
        if (! Schema::hasTable('evolutionary_authority_ledgers')) {
            return $this->unavailable();
        }
        $agent ??= LabAgent::query()->where('model_version_id', $model->id)->latest('id')->first();
        $scope = $agent ? [strtoupper($agent->symbol), strtoupper($agent->timeframe), $agent->strategy_family] : ['', '', null];
        $passport = $this->preservedPassport($model, $scope[0], $scope[1], $passport);
        $gene = (string) data_get($model->metadata, 'skill_mentor.parameter_key', '');
        $capsuleResolution = $this->cartridges->traitCapsuleForMentor($model, $agent, $gene);
        $traitCapsule = (array) data_get($capsuleResolution, 'capsule', []);
        $capsuleValid = (bool) data_get($capsuleResolution, 'valid', false);
        $capsuleHash = (string) data_get($traitCapsule, 'capsule_hash', '');
        $activationContextHash = (string) data_get($traitCapsule, 'activation_context.context_hash', '');
        $instrumentBundleHash = (string) data_get($traitCapsule, 'instrument_bundle.bundle_hash', '');
        $incubation = Schema::hasTable('skill_incubation_trials') ? DB::table('skill_incubation_trials')->where('mentor_model_version_id', $model->id)->get() : collect();
        $required = collect(self::INCUBATOR_ARMS);
        $finalPassed = $capsuleValid && $required->every(fn (string $arm): bool => $incubation->contains(function ($row) use ($arm, $capsuleHash, $activationContextHash, $instrumentBundleHash): bool {
            $evidence = (array) json_decode($row->evidence, true);
            $controlArm = in_array($arm, ['frozen_control', 'skill_ablation'], true);

            return $row->arm === $arm && $row->fold_stage === 'final' && $row->status === 'passed'
                && (bool) data_get($evidence, 'control_hashes_bound')
                && $capsuleHash !== '' && hash_equals($capsuleHash, (string) data_get($evidence, 'trait_capsule_hash', ''))
                && $activationContextHash !== '' && hash_equals($activationContextHash, (string) data_get($evidence, 'activation_context_hash', ''))
                && $instrumentBundleHash !== '' && hash_equals($instrumentBundleHash, (string) data_get($evidence, 'instrument_bundle_hash', ''))
                && ($controlArm ? ! (bool) data_get($evidence, 'single_component_change') : (bool) data_get($evidence, 'single_component_change'))
                && ($controlArm || (bool) data_get($evidence, 'target_gate_improved'))
                && ! (bool) data_get($evidence, 'non_target_regression');
        }));
        $windows = $incubation->flatMap(fn ($row) => (array) data_get(json_decode($row->evidence, true), 'window_keys', []))->unique()->count();
        $descendants = Schema::hasTable('descendant_value_trials') ? DB::table('descendant_value_trials')->where('mentor_model_version_id', $model->id)->where('status', 'settled')->get() : collect();
        $validChildren = $descendants->filter(function ($row) use ($capsuleHash, $activationContextHash, $instrumentBundleHash): bool {
            $evidence = (array) json_decode($row->evidence, true);

            return (bool) data_get($evidence, 'confirmed_component_only')
                && (bool) data_get($evidence, 'other_gene_mutated')
                && (bool) data_get($evidence, 'improved_over_mentor')
                && (bool) data_get($evidence, 'trait_incremental_over_ablation')
                && ! (bool) data_get($evidence, 'inherited_failure')
                && (bool) data_get($evidence, 'forward_gate_passed')
                && (int) data_get($evidence, 'independent_windows', 0) >= 3
                && (int) data_get($evidence, 'constraint_violations', 1) === 0
                && $capsuleHash !== '' && hash_equals($capsuleHash, (string) data_get($evidence, 'trait_capsule_hash', ''))
                && $activationContextHash !== '' && hash_equals($activationContextHash, (string) data_get($evidence, 'activation_context_hash', ''))
                && $instrumentBundleHash !== '' && hash_equals($instrumentBundleHash, (string) data_get($evidence, 'instrument_bundle_hash', ''));
        })->pluck('child_model_version_id')->unique()->count();
        $sourceConfirmed = data_get($model->metadata, 'skill_mentor.status') === 'confirmed';
        $trustContext = [
            ...(array) data_get($traitCapsule, 'activation_context.predicate', []),
            'cost_stress' => data_get($traitCapsule, 'activation_context.predicate.spread_liquidity_state', 'normal'),
        ];
        $contextTrust = $agent && $gene !== ''
            ? $this->parentTrust->score($model, $scope[0], $scope[1], (string) $scope[2], $gene, $trustContext)
            : ['status' => 'no_context_evidence', 'success_count' => 0, 'promotion_evidence' => false];
        $contextTrustConfirmed = data_get($contextTrust, 'status') === 'context_confirmed'
            && (int) data_get($contextTrust, 'success_count', 0) >= 2;
        $incubated = $sourceConfirmed && $capsuleValid && $finalPassed && $windows >= 3;
        $breeder = $incubated && $validChildren >= 2 && $contextTrustConfirmed;
        $passportPassed = (bool) ($passport['passed'] ?? false);
        $stage = $passportPassed && $breeder ? 'eligible_parent' : ($breeder ? 'breeder_candidate' : ($incubated ? 'skill_mentor' : ($sourceConfirmed ? 'confirmed_skill' : 'research_only')));
        $evidence = ['protocol' => self::PROTOCOL, 'source_confirmed' => $sourceConfirmed, 'incubation_passed' => $incubated,
            'incubation_final_arms' => $required->values()->all(), 'independent_windows' => $windows, 'descendant_improving_children' => $validChildren,
            'context_trust_confirmed' => $contextTrustConfirmed, 'context_trust' => $contextTrust,
            'trait_capsule_valid' => $capsuleValid, 'trait_capsule' => $traitCapsule,
            'trait_capsule_resolution' => array_diff_key($capsuleResolution, ['capsule' => true]),
            'passport' => $passport, 'authority_is_prospective_only' => true, 'promotion_evidence' => false];
        $key = hash('sha256', implode('|', [self::PROTOCOL, $model->id, $scope[0], $scope[1], $capsuleHash]));
        DB::table('evolutionary_authority_ledgers')->updateOrInsert(['authority_key' => $key], [
            'model_version_id' => $model->id, 'lab_agent_id' => $agent?->id, 'symbol' => $scope[0] ?: strtoupper((string) data_get($model->metadata, 'symbol', 'GLOBAL')),
            'timeframe' => $scope[1] ?: strtoupper((string) data_get($model->metadata, 'timeframe', 'GLOBAL')), 'strategy_family' => $scope[2],
            'authority_stage' => $stage, 'status' => $stage === 'eligible_parent' ? 'passed' : 'withheld',
            'data_hash' => data_get($traitCapsule, 'frozen_dependencies.data_hash', $incubation->first()?->data_hash),
            'execution_hash' => data_get($traitCapsule, 'frozen_dependencies.execution_hash', $incubation->first()?->execution_hash),
            'evidence' => json_encode($evidence), 'evaluated_at' => now(), 'updated_at' => now(), 'created_at' => now(),
        ]);

        return ['protocol' => self::PROTOCOL, 'stage' => $stage, 'parent_eligible' => $stage === 'eligible_parent', 'evidence' => $evidence, 'promotion_evidence' => false];
    }

    /** @return array<string,mixed> */
    public function authorityFor(ModelVersion $model): array
    {
        if (! Schema::hasTable('evolutionary_authority_ledgers')) {
            return $this->unavailable();
        }
        $row = DB::table('evolutionary_authority_ledgers')->where('model_version_id', $model->id)->latest('id')->first();

        return $row ? ['protocol' => self::PROTOCOL, 'stage' => $row->authority_stage, 'status' => $row->status, 'evidence' => json_decode($row->evidence, true), 'promotion_evidence' => false]
            : ['protocol' => self::PROTOCOL, 'stage' => 'research_only', 'status' => 'missing_authority_evidence', 'promotion_evidence' => false];
    }

    /**
     * Resolve the scientific control independently from genetic parentage.
     * Only a current-protocol, hash-bound frozen-control pair is accepted.
     *
     * @return array{model:ModelVersion,source:string,pair_id:int,data_hash:string,execution_hash:string}|null
     */
    private function resolveCausalBaseline(LabAgent $mentor, ModelVersion $model, string $gene): ?array
    {
        if (! Schema::hasTable('lab_learning_lane_pairs')) {
            return null;
        }
        $agentIds = [$mentor->id];
        if (Schema::hasTable('lab_skill_zoo_entries')) {
            $skillAgentIds = LabSkillZooEntry::query()->where('status', 'confirmed')
                ->where(function ($query) use ($mentor, $model): void {
                    $query->where('model_version_id', $model->id)->orWhere('lab_agent_id', $mentor->id);
                })->pluck('lab_agent_id')->filter()->map(fn ($id): int => (int) $id)->all();
            $agentIds = array_values(array_unique([...$agentIds, ...$skillAgentIds]));
        }
        $pairs = LabLearningLanePair::query()
            ->with(['candidateAgent.modelVersion', 'candidateResponseMap', 'controlAgent.modelVersion', 'controlResponseMap'])
            ->whereIn('candidate_agent_id', $agentIds)->latest('id')->get();
        foreach ($pairs as $pair) {
            if (! $pair->isVerifiedControlPair()
                || (int) $pair->candidateAgent?->model_version_id !== (int) $model->id
                || (string) $pair->candidateResponseMap?->parameter_key !== $gene) {
                continue;
            }
            $baseline = $pair->controlAgent?->modelVersion;
            if (! $baseline) {
                continue;
            }
            $diff = $this->diff((array) $baseline->parameters, (array) $model->parameters);
            if (count($diff) !== 1 || array_key_first($diff) !== $gene) {
                continue;
            }

            return ['model' => $baseline, 'source' => 'verified_frozen_control_pair', 'pair_id' => (int) $pair->id,
                'data_hash' => (string) $pair->control_data_hash, 'execution_hash' => (string) $pair->control_execution_hash];
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function preservedPassport(ModelVersion $model, string $symbol, string $timeframe, array $passport): array
    {
        if ((bool) ($passport['passed'] ?? false)) {
            return $passport;
        }
        $ledger = DB::table('evolutionary_authority_ledgers')->where('model_version_id', $model->id)
            ->when($symbol !== '', fn ($query) => $query->where('symbol', $symbol))
            ->when($timeframe !== '', fn ($query) => $query->where('timeframe', $timeframe))
            ->latest('id')->first();
        $stored = (array) data_get($ledger ? json_decode($ledger->evidence, true) : [], 'passport', []);
        if ((bool) ($stored['passed'] ?? false)) {
            return $stored;
        }
        $modelPassport = (array) data_get($model->metadata, 'elite_agent_passport', []);
        if (data_get($modelPassport, 'status') === 'passed') {
            return ['passed' => true, 'elite_passport' => 'passed',
                'passport_hash' => hash('sha256', json_encode($modelPassport, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)),
                'source' => 'model_metadata'];
        }

        return $passport;
    }

    private function contractHashesMatch(array $contract, array $result): bool
    {
        $expectedData = (string) data_get($contract, 'data_hash');
        $expectedExecution = (string) data_get($contract, 'execution_hash');
        $actualData = (string) data_get($result, 'data_manifest.sha256', data_get($result, 'data_hash'));
        $actualExecution = (string) data_get($result, 'execution_contract.execution_hash', data_get($result, 'execution_hash'));

        return $expectedData !== '' && $expectedExecution !== '' && $actualData !== '' && $actualExecution !== ''
            && hash_equals($expectedData, $actualData) && hash_equals($expectedExecution, $actualExecution);
    }

    /** The inherited bundle must be consumed in the inherited niche. */
    private function capsuleExecutionMatches(array $contract, array $result): bool
    {
        $capsule = (array) data_get($contract, 'trait_capsule', []);
        $gene = (string) data_get($contract, 'confirmed_gene', '');
        $assessment = $this->traitCapsules->assess($capsule, $gene);
        if (! (bool) data_get($assessment, 'valid', false)
            || ! filled(data_get($contract, 'trait_capsule_hash'))
            || ! hash_equals((string) data_get($contract, 'trait_capsule_hash'), (string) data_get($capsule, 'capsule_hash', ''))) {
            return false;
        }
        $trace = (array) data_get($result, 'instrument_research_trace', []);
        if (data_get($trace, 'protocol') !== 'lab_instrument_runtime_trace_v1'
            || data_get($trace, 'status') !== 'consumed'
            || data_get($trace, 'assignment_hash_valid') !== true
            || data_get($trace, 'parameter_hash_valid') !== true
            || data_get($trace, 'runtime_bindings_valid') !== true) {
            return false;
        }
        $expectedKeys = array_values(array_unique(array_map(
            'strval',
            (array) data_get($capsule, 'instrument_bundle.instrument_keys', []),
        )));
        $observedKeys = collect((array) data_get($trace, 'instruments', []))
            ->filter(fn ($row): bool => is_array($row) && data_get($row, 'status') === 'consumed')
            ->pluck('instrument_key')->map(fn ($key): string => (string) $key)->unique()->values()->all();
        if ($expectedKeys === [] || $expectedKeys !== $observedKeys) {
            return false;
        }

        return collect((array) data_get($trace, 'context_slices', []))->contains(function ($slice) use ($capsule): bool {
            if (! is_array($slice) || data_get($slice, 'powered') !== true) {
                return false;
            }

            return (bool) data_get(
                $this->traitCapsules->contextCompatibility($capsule, (array) data_get($slice, 'context', [])),
                'compatible',
                false,
            );
        });
    }

    /**
     * Compare the short, powered win where the capsule says it is active.
     * A global monthly win cannot manufacture London/trend-up authority, and
     * an unsupported local target remains research-only instead of falling
     * back to a misleading aggregate.
     *
     * @return array<string,mixed>
     */
    private function contextualTargetComparison(string $target, array $candidate, array $control, array $capsule): array
    {
        $candidateSlice = $this->matchingPoweredSlice($candidate, $capsule);
        $controlSlice = $this->matchingPoweredSlice($control, $capsule);
        if ($candidateSlice === null || $controlSlice === null) {
            return ['status' => 'exact_context_slice_missing', 'eligible' => false, 'promotion_evidence' => false];
        }
        $candidateMetrics = (array) data_get($candidateSlice, 'metrics', []);
        $controlMetrics = (array) data_get($controlSlice, 'metrics', []);
        $normalizedTarget = match ($target) {
            'volatility_session_stability', 'exit_topology', 'risk_exit', 'transition_firewall' => 'stress_cost',
            'opportunity_recall' => 'trade_frequency',
            default => $target,
        };
        $candidateValue = match ($normalizedTarget) {
            'profit_factor', 'stress_cost' => data_get($candidateMetrics, 'net_pf'),
            'drawdown_risk' => data_get($candidateMetrics, 'max_drawdown_percent'),
            'trade_frequency' => data_get($candidateMetrics, 'trades'),
            default => null,
        };
        $controlValue = match ($normalizedTarget) {
            'profit_factor', 'stress_cost' => data_get($controlMetrics, 'net_pf'),
            'drawdown_risk' => data_get($controlMetrics, 'max_drawdown_percent'),
            'trade_frequency' => data_get($controlMetrics, 'trades'),
            default => null,
        };
        if (! is_numeric($candidateValue) || ! is_numeric($controlValue)) {
            return [
                'status' => 'target_not_available_in_context_slice', 'target' => $normalizedTarget,
                'eligible' => false, 'candidate_context' => data_get($candidateSlice, 'context'),
                'control_context' => data_get($controlSlice, 'context'), 'promotion_evidence' => false,
            ];
        }
        $candidateValue = (float) $candidateValue;
        $controlValue = (float) $controlValue;
        $targetBetter = $normalizedTarget === 'drawdown_risk'
            ? $candidateValue + .000001 < $controlValue
            : $candidateValue > $controlValue + .000001;
        $candidateDrawdown = data_get($candidateMetrics, 'max_drawdown_percent');
        $controlDrawdown = data_get($controlMetrics, 'max_drawdown_percent');
        $candidateCost = data_get($candidateMetrics, 'execution_cost_percent');
        $controlCost = data_get($controlMetrics, 'execution_cost_percent');
        $localNonTargetSafe = (! is_numeric($candidateDrawdown) || ! is_numeric($controlDrawdown)
                || (float) $candidateDrawdown <= ((float) $controlDrawdown * 1.05) + .000001)
            && (! is_numeric($candidateCost) || ! is_numeric($controlCost)
                || (float) $candidateCost <= ((float) $controlCost * 1.05) + .000001);

        return [
            'status' => 'powered_exact_context', 'target' => $normalizedTarget,
            'candidate_observation' => $candidateValue, 'control_observation' => $controlValue,
            'target_better' => $targetBetter, 'local_non_target_safe' => $localNonTargetSafe,
            'eligible' => $targetBetter && $localNonTargetSafe,
            'candidate_context' => data_get($candidateSlice, 'context'),
            'control_context' => data_get($controlSlice, 'context'),
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string,mixed>|null */
    private function matchingPoweredSlice(array $result, array $capsule): ?array
    {
        return collect((array) data_get($result, 'instrument_research_trace.context_slices', []))
            ->first(function ($slice) use ($capsule): bool {
                if (! is_array($slice) || data_get($slice, 'powered') !== true) {
                    return false;
                }

                return (bool) data_get(
                    $this->traitCapsules->contextCompatibility($capsule, (array) data_get($slice, 'context', [])),
                    'compatible',
                    false,
                );
            });
    }

    /** @return array<string,mixed> */
    private function researchChildMetadata(ModelVersion $mentor, string $contractKey, array $contract): array
    {
        $metadata = (array) $mentor->metadata;
        $metadata['authority_source_skill'] = (array) data_get($metadata, 'skill_mentor', []);
        foreach (['skill_mentor', 'evolution_stage', 'screening_seed_only', 'elite_agent_passport',
            'learning_lane', 'learning_receipt', 'causal_learning_cohort', 'causal_learning_experiment',
            'causal_experiment_lane', 'repair_anchor', 'repair_anchor_sibling', 'repair_lineage',
            'parent_inheritance_protocol', 'parent_foundry', 'shadow_research_lane', 'portfolio_research_contract'] as $key) {
            unset($metadata[$key]);
        }
        $metadata[$contractKey] = $contract;
        $causalBaselineModelId = (int) data_get($contract, 'causal_baseline_model_version_id', data_get($contract, 'baseline_model_version_id', 0));
        if ($causalBaselineModelId > 0) {
            $metadata['causal_baseline_model_version_id'] = $causalBaselineModelId;
            $metadata['genetic_parent_model_version_id'] = data_get($contract, 'genetic_parent_model_version_id');
        }
        $metadata['base_strategy'] = data_get($contract, 'base_strategy', data_get($metadata, 'base_strategy', $mentor->strategy));

        return $metadata;
    }

    /** @return array<string,mixed> */
    private function descendantTrustContext(ModelVersion $mentor): array
    {
        $metadata = (array) $mentor->metadata;
        $lane = (array) data_get($metadata, 'portfolio_council_lane', []);
        $niche = (array) data_get($metadata, 'semantic_group.niche', []);

        return $this->parentTrust->context([
            ...$niche,
            ...$lane,
            'regime' => data_get($lane, 'regime', data_get($niche, 'regime')),
            'session' => data_get($lane, 'session', data_get($lane, 'session_utc_hour', data_get($niche, 'session'))),
            'volume_state' => data_get($lane, 'volume_state', data_get($niche, 'volume_state')),
            'cost_stress' => data_get($lane, 'cost_stress', data_get($niche, 'cost_stress', 'normal')),
        ]);
    }

    private function unavailable(): array
    {
        return ['protocol' => self::PROTOCOL, 'status' => 'unavailable', 'promotion_evidence' => false];
    }

    /** @return array<string,mixed>|null */
    private function parametersForArm(string $arm, array $base, array $skill, string $gene): ?array
    {
        $parameters = $base;
        if ($arm === 'frozen_control' || $arm === 'skill_ablation') {
            return $parameters;
        }
        if ($arm === 'single_gene_child') {
            $parameters[$gene] = $skill[$gene];

            return $parameters;
        }
        $other = collect($base)->keys()->first(fn (string $key): bool => $key !== $gene && (is_numeric($base[$key]) || is_bool($base[$key])));
        if (! $other) {
            return null;
        }
        $value = $parameters[$other];
        $parameters[$other] = is_bool($value) ? ! $value : ((float) $value * ($arm === 'memory_blinded_child' ? 1.05 : .95));

        return $parameters;
    }

    /** @return array<string,array{old:mixed,new:mixed}> */
    private function diff(array $old, array $new): array
    {
        $diff = [];
        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $key) {
            if (($old[$key] ?? null) !== ($new[$key] ?? null)) {
                $diff[$key] = ['old' => $old[$key] ?? null, 'new' => $new[$key] ?? null];
            }
        }

        return $diff;
    }

    private function targetImproved(string $target, array $current, array $control): bool
    {
        if ($control === [] || $current === []) {
            return false;
        }
        $comparison = app(GateMarginService::class)->compare($current, $control, $target);

        return data_get($comparison, 'candidate_better') === true
            && (float) data_get($comparison, 'margin_delta', 0) > 0;
    }

    private function nonTargetRegression(array $current, array $control): bool
    {
        if ($control === []) {
            return true;
        }

        $margins = app(GateMarginService::class);
        $candidateGates = (array) data_get($margins->screening($current), 'gates', []);
        $controlGates = (array) data_get($margins->screening($control), 'gates', []);
        foreach ($controlGates as $gate => $controlGate) {
            if (data_get($controlGate, 'status') === 'unknown') {
                continue;
            }
            $candidateGate = (array) ($candidateGates[$gate] ?? []);
            if (data_get($candidateGate, 'status') === 'unknown'
                || ! is_numeric(data_get($candidateGate, 'normalized_margin'))
                || (float) data_get($candidateGate, 'normalized_margin') + .000001
                    < (float) data_get($controlGate, 'normalized_margin')) {
                return true;
            }
        }

        return false;
    }
}
