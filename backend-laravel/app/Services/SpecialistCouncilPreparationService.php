<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Models\SpecialistCouncilVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/** Binds a complete prospective experiment; population and queue ownership remain canonical. */
class SpecialistCouncilPreparationService
{
    public const PROTOCOL = 'specialist_council_preparation_v1';

    public function __construct(
        private SpecialistCouncilLifecycleService $lifecycle,
        private SpecialistCouncilContractService $contracts,
        private ResearchPaperEpochContractService $epochs,
    ) {}

    /**
     * Input contains the original manifest, evaluation_plan, creator_id,
     * evaluator_id and carrier_model_version_id. All native models must belong
     * to this constructor-complete, unused draft. Nothing is dispatched here.
     */
    public function prepare(LabGeneration $generation, array $request): array
    {
        if (($request['research_purpose'] ?? null) === 'spread_context_study'
            || data_get($generation->trigger_context, 'native_specialist_council_intent.research_purpose') === 'spread_context_study'
            || array_key_exists('study_spec', $request)) {
            return $this->prepareSpreadContextStudy($generation, $request);
        }
        if (($request['protocol'] ?? null) !== self::PROTOCOL
            || ! is_array($request['manifest'] ?? null) || ! is_array($request['evaluation_plan'] ?? null)
            || ! is_string($request['creator_id'] ?? null) || ! is_string($request['evaluator_id'] ?? null)
            || $request['creator_id'] === '' || $request['evaluator_id'] === ''
            || ! is_string($request['research_question'] ?? null) || trim($request['research_question']) === ''
            || strlen($request['research_question']) > 4096) {
            throw new InvalidArgumentException('CANONICAL_COUNCIL_PREPARATION_INPUT_INVALID');
        }
        if ($request['creator_id'] === $request['evaluator_id']) throw new LogicException('CREATOR_OR_MEMBER_CANNOT_SELF_CERTIFY');
        if (($request['evaluation_plan']['purpose'] ?? null) !== 'research') {
            throw new LogicException('PROSPECTIVE_DRAFT_PREPARATION_IS_RESEARCH_ONLY');
        }
        $timeframes = $generation->agents()->distinct()->pluck('timeframe')->all();
        if (count($timeframes) !== 1 || ! is_string($timeframes[0]) || $timeframes[0] === '') {
            throw new LogicException('CANONICAL_COUNCIL_GENERATION_TIMEFRAME_AMBIGUOUS');
        }
        // This is the dispatcher's own lease, not a second construction owner.
        $lease = Cache::lock("lab-generation-dispatch:{$generation->ai_laboratory_id}:{$timeframes[0]}:{$generation->id}",
            max(300, (int) config('services.lab_queue.dispatch_lease_seconds', 3600)));
        if (! $lease->get()) throw new LogicException('CANONICAL_COUNCIL_DISPATCH_LEASE_BUSY');
        try {
            return DB::transaction(function () use ($generation, $request): array {
                $draft = LabGeneration::whereKey($generation->id)->lockForUpdate()->firstOrFail();
                $context = (array) $draft->trigger_context;
                $agents = LabAgent::where('lab_generation_id', $draft->id)->orderBy('id')->lockForUpdate()->get();
                // The canonical constructor sets started_at when creating a draft;
                // only original execution/queue evidence makes it observed.
                if ($draft->status !== 'draft' || $draft->completed_at !== null
                    || $agents->count() !== (int) $draft->population_size || $agents->isEmpty()
                    || $agents->contains(fn (LabAgent $agent): bool => $agent->lifecycle_status !== 'draft')) {
                    throw new LogicException('CANONICAL_COUNCIL_REQUIRES_COMPLETE_UNUSED_DRAFT');
                }
                foreach (['research_release', 'canonical_dataset_snapshots', 'queue_batches'] as $seal) {
                    if (! empty($context[$seal])) throw new LogicException('CANONICAL_COUNCIL_DRAFT_ALREADY_SEALED_OR_DISPATCHED');
                }
                if (! empty($context['mtf_bundle_manifest']) && empty($context['specialist_council_preparation'])) {
                    throw new LogicException('CANONICAL_COUNCIL_DRAFT_ALREADY_SEALED_OR_DISPATCHED');
                }
                $generationModelIds = $agents->pluck('model_version_id')->map(fn ($id): int => (int) $id)->all();
                if (count(array_unique($generationModelIds)) !== count($generationModelIds)
                    || LabAgent::whereIn('model_version_id', $generationModelIds)->where('lab_generation_id', '!=', $draft->id)->exists()
                    || LabEvaluationRun::where('lab_generation_id', $draft->id)->orWhereIn('model_version_id', $generationModelIds)->exists()) {
                    throw new LogicException('CANONICAL_COUNCIL_MODEL_ALREADY_OBSERVED_OR_REUSED');
                }
                $models = ModelVersion::whereIn('id', $generationModelIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                if ($models->count() !== count($generationModelIds)) throw new LogicException('CANONICAL_COUNCIL_NATIVE_MODEL_MISSING');
                $nativeIntent = $this->nativeConstructorIntent($draft, $models, $agents);
                if ($nativeIntent !== null) $this->assertNativeIntentRequest($nativeIntent, $request, $models);
                $followupProof = null;
                if (isset($nativeIntent['followup_work_item_id'])) {
                    $work = \App\Models\ResearchExperimentWorkItem::find($nativeIntent['followup_work_item_id']);
                    $followupProof = $work ? app(SpecialistCouncilResearchFeedbackService::class)->inspectFollowupReadiness($work) : [];
                    if (($followupProof['executable'] ?? false) !== true
                        || ($followupProof['resolution_hash'] ?? null) !== $nativeIntent['followup_resolution_hash']
                        || $this->epochs->parameterHash($request) !== $this->epochs->parameterHash(
                            app(SpecialistCouncilFollowupExecutionService::class)->preparationRequest($draft, $followupProof))) {
                        throw new LogicException('CANONICAL_COUNCIL_FOLLOWUP_PREREGISTERED_REQUEST_DRIFT');
                    }
                }
                foreach (['academy_trial_id', 'prospective_repair', 'causal_learning_cohort', 'cooperative_experiment_blocks'] as $owner) {
                    if (! empty($context[$owner])) throw new LogicException('CANONICAL_COUNCIL_GENERATION_RESERVED_FOR_ANOTHER_EXPERIMENT');
                }
                foreach ($models as $model) {
                    foreach (['cooperative_experiment_block', 'causal_learning_cohort', 'prospective_repair',
                        'prospective_repair_source_pair_id', 'academy_experiment', 'academy_control_admission',
                        'activation_factorial', 'phase_scope_probe', 'control_pair_contract'] as $owner) {
                        if (! empty(data_get($model->metadata, $owner))) {
                            throw new LogicException('CANONICAL_COUNCIL_NATIVE_MODEL_RESERVED_FOR_ANOTHER_EXPERIMENT:'.$owner);
                        }
                    }
                    if (data_get($model->metadata, 'last_result') !== null || data_get($model->metadata, 'last_screen_result') !== null) {
                        throw new LogicException('CANONICAL_COUNCIL_MODEL_ALREADY_OBSERVED_OR_REUSED');
                    }
                }
                $this->assertModelOwnership($request, $generationModelIds);
                $requestHash = $this->epochs->parameterHash($request);
                $prior = $context['specialist_council_preparation'] ?? null;
                if ($prior !== null) {
                    if (! is_array($prior) || ($prior['request_hash'] ?? null) !== $requestHash) {
                        throw new LogicException('CANONICAL_COUNCIL_PREPARATION_RETRY_CHANGED');
                    }
                    $this->verifiedGeneration($draft, true);
                    if (isset($request['discovery_bundle_manifest'])) $this->inspectDiscoveryOwner($draft, $request['discovery_bundle_manifest']);
                    return $prior;
                }
                $this->assertComparativeDesign($request['evaluation_plan'], (int) $request['carrier_model_version_id']);
                if ($nativeIntent !== null && ! array_key_exists('solo_comparison', $request['evaluation_plan'])) {
                    throw new LogicException('CANONICAL_COUNCIL_FUTURE_NATIVE_SOLO_DECLARATION_REQUIRED');
                }
                $discoveryManifest = $request['discovery_bundle_manifest'] ?? null;
                if ($discoveryManifest !== null) {
                    if (! is_array($discoveryManifest)) throw new LogicException('CANONICAL_COUNCIL_DISCOVERY_MANIFEST_INVALID');
                    $this->assertDiscoveryPlan($request['evaluation_plan'], $discoveryManifest);
                }
                foreach ($models as $model) {
                    if (data_get($model->metadata, 'specialist_council') !== null
                        || data_get($model->metadata, 'specialist_council_evaluation') !== null) {
                        throw new LogicException('CANONICAL_COUNCIL_NATIVE_MODEL_ALREADY_BOUND');
                    }
                }
                // Ordinary replay materializes this exact native owner. Do so
                // before any passport/model/plan seal, never during a sealed
                // member's first replay where it would change the whole cohort.
                $this->materializeOriginalInstrumentAssignments($draft, $agents, $models);
                $version = $this->lifecycle->registerDraft($request['manifest'], $request['creator_id']);
                $feedback = app(SpecialistCouncilResearchFeedbackService::class);
                $observations = $feedback->priorObservations($version->council_id, 8);
                $observationHash = $this->epochs->parameterHash($observations);
                $questionFingerprint = $feedback->questionFingerprint($version->manifest, $request['evaluation_plan']);
                $sourceHash = app(LabImmutableEvidenceService::class)->codeHash();
                if ($feedback->completedQuestionForSource($questionFingerprint, $sourceHash) !== null) {
                    throw new LogicException('CANONICAL_COUNCIL_EXACT_COMPLETED_QUESTION_ALREADY_OBSERVED');
                }
                $consumption = ['protocol' => 'specialist_council_research_consumption_v1',
                    'research_question' => $request['research_question'], 'prior_feedback' => $observations,
                    'research_question_fingerprint' => $questionFingerprint,
                    'preparation_source_hash' => $sourceHash,
                    'prior_feedback_digest' => $observationHash, 'authority' => 'research_only',
                    'confirmed_trait_inherited' => false, 'parameter_mutation_inferred' => false,
                    'independent_evidence_claimed' => false, 'promotion_evidence' => false];
                if ($followupProof !== null) {
                    $consumption['original_followup'] = ['work_item_id' => $followupProof['work_item_id'],
                        'source_receipt_id' => $followupProof['source_receipt_id'], 'resolution_hash' => $followupProof['resolution_hash'],
                        'source_contract_hash' => $followupProof['source_contract_hash'] ?? null,
                        'source_evidence_hash' => $followupProof['source_evidence_hash'] ?? null,
                        'parameter_deltas' => array_map(fn (array $spec): array => $spec['parameter_deltas'] ?? [], $followupProof['native_source_models']),
                        'authority' => 'research_only', 'confirmed_trait_inherited' => false, 'promotion_evidence' => false];
                }
                $originalPlan = [...$request['evaluation_plan'], 'research_question' => $request['research_question'],
                    'research_question_fingerprint' => $questionFingerprint,
                    'preparation_source_hash' => $sourceHash,
                    'prior_feedback_digest' => $observationHash, 'learning_consumption_receipt' => $consumption];
                $runtimeIds = [(int) $request['carrier_model_version_id']];
                foreach ($request['evaluation_plan']['arms'] as $arm) {
                    if (in_array($arm['kind'] ?? null, ['candidate', 'ablation', 'retention'], true)) $runtimeIds[] = (int) $arm['model_version_id'];
                }
                foreach (array_unique($runtimeIds) as $id) $this->lifecycle->attachResearchModel($version, $models[$id]);
                // Runtime attachment precedes plan model hashes. Parameter vectors are never rewritten.
                $plan = $this->lifecycle->sealEvaluationPlan($version->fresh(), $request['evaluator_id'], $originalPlan);
                foreach ($plan['arms'] as $key => $arm) {
                    $this->lifecycle->attachEvaluationArm($version->fresh(), $key, $models[(int) $arm['model_version_id']]->fresh());
                }
                $symbols = $agents->pluck('symbol')->unique()->values()->all();
                if (count($symbols) !== 1) throw new LogicException('CANONICAL_COUNCIL_SINGLE_ACCOUNT_SYMBOL_AMBIGUOUS');
                $this->assertPlanExecutable($version->fresh(), $plan, $symbols[0], $discoveryManifest === null ? null
                    : ['bundle_hash' => $discoveryManifest['bundle_hash'], 'manifest' => $discoveryManifest]);
                $modelHashes = [];
                foreach (array_unique([...$runtimeIds, ...array_column($plan['arms'], 'model_version_id')]) as $id) {
                    $modelHashes[(string) $id] = $this->contracts->modelHash($models[(int) $id]->fresh());
                }
                $allModelHashes = [];
                foreach ($models as $model) $allModelHashes[(string) $model->id] = $this->contracts->modelHash($model->fresh());
                $receipt = ['protocol' => self::PROTOCOL, 'status' => 'prepared_for_canonical_dispatch',
                    'lab_generation_id' => $draft->id, 'request_hash' => $requestHash,
                    'version_id' => $version->id, 'manifest_hash' => $version->manifest_hash, 'plan_hash' => $plan['plan_hash'],
                    'creator_id' => $request['creator_id'], 'evaluator_id' => $request['evaluator_id'],
                    'research_question' => $request['research_question'], 'prior_feedback_digest' => $observationHash,
                    'research_question_fingerprint' => $questionFingerprint,
                    'preparation_source_hash' => $sourceHash,
                    'learning_consumption_receipt' => $consumption,
                    'carrier_model_version_id' => (int) $request['carrier_model_version_id'],
                    'arm_keys' => array_keys($plan['arms']), 'bound_model_hashes' => $modelHashes,
                    'generation_agent_ids' => $agents->pluck('id')->all(), 'generation_model_hashes' => $allModelHashes,
                    'native_intent_hash' => $nativeIntent['intent_hash'] ?? null,
                    'discovery_bundle_hash' => $discoveryManifest['bundle_hash'] ?? null,
                    'discovery_manifest_hash' => $discoveryManifest === null ? null : $this->epochs->parameterHash($discoveryManifest),
                    'prepared_at' => now()->utc()->toIso8601String(), 'next_owner' => 'canonical_lab_dispatcher',
                    'promotion_evidence' => false, 'paper_authority_granted' => false];
                $receipt['receipt_hash'] = $this->epochs->parameterHash($receipt);
                $preparedContext = [...$context, 'specialist_council_preparation' => $receipt];
                if ($discoveryManifest !== null) {
                    $preparedContext['mtf_bundle_hash'] = $discoveryManifest['bundle_hash'];
                    $preparedContext['mtf_bundle_manifest'] = $discoveryManifest;
                }
                $draft->forceFill(['trigger_context' => $preparedContext])->save();
                return $receipt;
            }, 1);
        } finally { $lease->release(); }
    }

    /** Absent intake is ordinary; a declared but broken intake must never fall through to promotion. */
    public function isResearchGeneration(LabGeneration $generation): bool
    {
        if (data_get($generation->trigger_context, 'specialist_council_preparation') === null) {
            if ($this->hasNativeConstructorIntent($generation)) {
                throw new LogicException('CANONICAL_COUNCIL_ATOMIC_PREPARATION_REQUIRED');
            }
            return false;
        }
        if ($generation->status === 'draft'
            && (int) data_get($generation->trigger_context, 'native_specialist_council_intent.followup_work_item_id', 0) > 0) {
            $work = \App\Models\ResearchExperimentWorkItem::find((int) data_get($generation->trigger_context, 'native_specialist_council_intent.followup_work_item_id'));
            $proof = $work ? app(SpecialistCouncilResearchFeedbackService::class)->inspectFollowupReadiness($work) : [];
            if (! $work || $work->status !== 'leased' || ! $work->lease_expires_at || $work->lease_expires_at->isPast()
                || ($proof['executable'] ?? false) !== true
                || ($proof['resolution_hash'] ?? null) !== data_get($generation->trigger_context, 'native_specialist_council_intent.followup_resolution_hash')) {
                throw new LogicException('CANONICAL_COUNCIL_FOLLOWUP_CURRENT_DISPATCH_OWNER_REQUIRED');
            }
        }
        $this->verifiedGeneration($generation);
        return true;
    }

    /** Canonical seed origin is retained even if a declared intent projection is lost. */
    public function hasNativeConstructorIntent(LabGeneration $generation): bool
    {
        return data_get($generation->trigger_context, 'native_specialist_council_intent') !== null
            || $generation->agents()->where('origin', 'native_council_root')->exists();
    }

    /** Read-only typed discovery owner shared by dispatcher, snapshot admission and evaluator. */
    public function inspectDiscoveryOwner(LabGeneration $generation, array $manifest): array
    {
        if (data_get($generation->trigger_context, 'specialist_council_preparation') === null) {
            return ['allowed' => false, 'reason' => 'CANONICAL_COUNCIL_PREPARATION_ABSENT', 'promotion_evidence' => false];
        }
        [$receipt, $plan] = $this->verifiedGeneration($generation);
        if (! is_string($receipt['discovery_bundle_hash'] ?? null)
            || ! hash_equals($receipt['discovery_bundle_hash'], (string) ($manifest['bundle_hash'] ?? ''))
            || ($receipt['discovery_manifest_hash'] ?? null) !== $this->epochs->parameterHash($manifest)
            || data_get($generation->trigger_context, 'mtf_bundle_hash') !== $receipt['discovery_bundle_hash']
            || $this->epochs->parameterHash((array) data_get($generation->trigger_context, 'mtf_bundle_manifest', []))
                !== $receipt['discovery_manifest_hash']) {
            throw new LogicException('CANONICAL_COUNCIL_DISCOVERY_ORIGINAL_OWNER_DRIFT');
        }
        $this->assertDiscoveryPlan($plan, $manifest);
        return ['allowed' => true, 'reason' => 'ORIGINAL_NATIVE_COUNCIL_RESEARCH_PLAN_VERIFIED',
            'version_id' => $receipt['version_id'], 'plan_hash' => $receipt['plan_hash'],
            'bundle_hash' => $receipt['discovery_bundle_hash'], 'promotion_evidence' => false];
    }

    private function verifiedGeneration(LabGeneration $generation, bool $requiresOpenDraft = false): array
    {
        $receipt = data_get($generation->trigger_context, 'specialist_council_preparation');
        if (is_array($receipt) && ($receipt['research_purpose'] ?? null) === 'spread_context_study') {
            return $this->verifiedSpreadStudyGeneration($generation);
        }
        if (! is_array($receipt) || ($receipt['protocol'] ?? null) !== self::PROTOCOL
            || ($receipt['lab_generation_id'] ?? null) !== $generation->id
            || ($receipt['status'] ?? null) !== 'prepared_for_canonical_dispatch'
            || ($receipt['promotion_evidence'] ?? null) !== false || ($receipt['paper_authority_granted'] ?? null) !== false) {
            throw new LogicException('CANONICAL_COUNCIL_PREPARATION_DECLARATION_INVALID');
        }
        $agents = $generation->agents()->orderBy('id')->get();
        $ids = $agents->pluck('model_version_id')->map(fn ($id): int => (int) $id)->all();
        $models = ModelVersion::whereIn('id', $ids)->get()->keyBy('id');
        if ($agents->pluck('id')->all() !== ($receipt['generation_agent_ids'] ?? null)
            || $agents->count() !== (int) $generation->population_size || $models->count() !== count($ids)
            || count(array_unique($ids)) !== count($ids)
            || LabAgent::whereIn('model_version_id', $ids)->where('lab_generation_id', '!=', $generation->id)->exists()
            || count((array) ($receipt['generation_model_hashes'] ?? [])) !== count($ids)) {
            throw new LogicException('CANONICAL_COUNCIL_PREPARATION_GENERATION_OWNERSHIP_DRIFT');
        }
        $nativeIntent = $this->nativeConstructorIntent($generation, $models, $agents);
        if (($receipt['native_intent_hash'] ?? null) !== ($nativeIntent['intent_hash'] ?? null)
            || ($nativeIntent !== null && (($receipt['creator_id'] ?? null) !== $nativeIntent['creator_id']
                || ($receipt['research_question'] ?? null) !== $nativeIntent['research_question']))) {
            throw new LogicException('CANONICAL_COUNCIL_PREPARATION_NATIVE_INTENT_DRIFT');
        }
        foreach ($receipt['generation_model_hashes'] as $id => $hash) {
            if (! isset($models[(int) $id]) || ! hash_equals((string) $hash, $this->contracts->modelHash($models[(int) $id]))) {
                throw new LogicException('CANONICAL_COUNCIL_PREPARATION_ORIGINAL_MODEL_DRIFT');
            }
        }
        $this->assertOriginalBindings($receipt, $models, $requiresOpenDraft);
        $stored = DB::table('specialist_council_evaluation_plans')->where('specialist_council_version_id', $receipt['version_id'])->first();
        $plan = json_decode($stored->plan, true, 64, JSON_THROW_ON_ERROR);
        if (($plan['purpose'] ?? null) !== 'research' || ($receipt['arm_keys'] ?? null) !== array_keys((array) ($plan['arms'] ?? []))
            || $stored->evaluator_id !== ($receipt['evaluator_id'] ?? null)) {
            throw new LogicException('CANONICAL_COUNCIL_PREPARATION_ORIGINAL_RESEARCH_PLAN_DRIFT');
        }
        $this->assertModelOwnership(['carrier_model_version_id' => $receipt['carrier_model_version_id'],
            'manifest' => SpecialistCouncilVersion::findOrFail($receipt['version_id'])->manifest, 'evaluation_plan' => $plan], $ids);
        $this->assertComparativeDesign($plan, (int) $receipt['carrier_model_version_id']);
        $releaseSource = data_get($generation->trigger_context, 'research_release.source_hash');
        if ($releaseSource !== null && ! hash_equals((string) $receipt['preparation_source_hash'], (string) $releaseSource)) {
            throw new LogicException('CANONICAL_COUNCIL_PREPARATION_ORIGINAL_SOURCE_DRIFT');
        }
        return [$receipt, $plan];
    }

    private function nativeConstructorIntent(LabGeneration $generation, $models, $agents): ?array
    {
        $intent = data_get($generation->trigger_context, 'native_specialist_council_intent');
        if ($intent === null && ! $agents->contains(fn (LabAgent $agent): bool => $agent->origin === 'native_council_root')) return null;
        if (! is_array($intent) || ($intent['protocol'] ?? null) !== LabPopulationService::NATIVE_COUNCIL_INTENT_PROTOCOL
            || ($intent['purpose'] ?? null) !== 'research' || ($intent['symbol'] ?? null) !== 'XAUUSD'
            || ($intent['storage_timeframe'] ?? null) !== 'H1' || ($intent['population_size'] ?? null) !== 6
            || ($intent['authority'] ?? null) !== 'research_only' || ($intent['requires_atomic_preparation'] ?? null) !== true
            || ($intent['independent_evidence_claimed'] ?? null) !== false || ($intent['promotion_evidence'] ?? null) !== false
            || $generation->trigger_type !== GenerationAdmissionDecisionService::HISTORICAL_TRIGGER
            || (int) $generation->population_size !== 6 || $agents->count() !== 6
            || ! is_string($intent['creator_id'] ?? null) || trim($intent['creator_id']) === ''
            || ! is_string($intent['research_question'] ?? null) || trim($intent['research_question']) === ''
            || ($intent['intent_hash'] ?? null) !== $this->epochs->parameterHash(array_diff_key($intent, ['intent_hash' => true]))) {
            throw new LogicException('CANONICAL_COUNCIL_NATIVE_CONSTRUCTOR_INTENT_INVALID');
        }
        $roles = [];
        foreach ($agents as $index => $agent) {
            $seed = (array) data_get($models[(int) $agent->model_version_id]?->metadata, 'native_specialist_council_seed', []);
            if ($agent->origin !== 'native_council_root' || $agent->symbol !== 'XAUUSD' || $agent->timeframe !== 'H1'
                || $agent->parent_a_model_version_id !== null || $agent->parent_b_model_version_id !== null
                || ($seed['protocol'] ?? null) !== LabPopulationService::NATIVE_COUNCIL_INTENT_PROTOCOL
                || ($seed['intent_hash'] ?? null) !== $intent['intent_hash']
                || ($seed['lab_generation_id'] ?? null) !== $generation->id
                || ($seed['authority'] ?? null) !== 'research_only' || ($seed['qualified_specialist'] ?? null) !== false) {
                throw new LogicException('CANONICAL_COUNCIL_NATIVE_CONSTRUCTOR_SEED_DRIFT');
            }
            if (($intent['research_purpose'] ?? '') === 'spread_context_study'
                && (($seed['research_purpose'] ?? null) !== 'spread_context_study' || ($seed['construction_slot'] ?? null) !== $index + 1
                    || ($seed['slot_role'] ?? null) !== LabPopulationService::nativeCouncilSlotRoles($intent)[$index]
                    || isset($intent['followup_work_item_id']) || isset($intent['followup_resolution_hash']))) {
                throw new LogicException('CANONICAL_SPREAD_STUDY_ORIGINAL_SLOT_OR_VECTOR_DRIFT');
            }
            $roles[] = $seed['slot_role'] ?? '';
        }
        sort($roles);
        $expectedRoles = LabPopulationService::nativeCouncilSlotRoles($intent); sort($expectedRoles);
        if ($roles !== $expectedRoles) {
            throw new LogicException('CANONICAL_COUNCIL_NATIVE_CONSTRUCTOR_SEED_DRIFT');
        }
        return $intent;
    }

    private function assertNativeIntentRequest(array $intent, array $request, $models): void
    {
        if (($intent['research_purpose'] ?? '') === 'spread_context_study') throw new LogicException('CANONICAL_COUNCIL_STUDY_PURPOSE_REQUIRES_ORIGINAL_STUDY_PREPARATION');
        $carrier = $models[(int) $request['carrier_model_version_id']] ?? null;
        if ($request['creator_id'] !== $intent['creator_id'] || $request['research_question'] !== $intent['research_question']
            || data_get($carrier?->metadata, 'native_specialist_council_seed.slot_role') !== 'candidate_carrier') {
            throw new LogicException('CANONICAL_COUNCIL_NATIVE_INTENT_REQUEST_MISMATCH');
        }
        $sourceIds = [];
        foreach ((array) ($request['manifest']['members'] ?? []) as $member) {
            if (! in_array($member['role'] ?? null, SpecialistCouncilContractService::TRADING_ROLES, true)) continue;
            $model = $models[(int) ($member['model_version_id'] ?? 0)] ?? null;
            if (! $model || data_get($model->metadata, 'native_specialist_council_seed.slot_role') !== 'source_'.($member['role'] ?? '')) {
                throw new LogicException('CANONICAL_COUNCIL_NATIVE_INTENT_SOURCE_ROLE_MISMATCH');
            }
            $sourceIds[] = (int) $model->id;
        }
        if (count(array_unique($sourceIds)) !== 4) throw new LogicException('CANONICAL_COUNCIL_NATIVE_INTENT_SOURCE_ROLE_MISMATCH');
        foreach (['champion_model_version_id', 'solo_model_version_id'] as $key) {
            if (! in_array((int) data_get($request, 'manifest.evaluation_policy.'.$key), $sourceIds, true)) {
                throw new LogicException('CANONICAL_COUNCIL_NATIVE_INTENT_COMPARATOR_MISMATCH');
            }
        }
        foreach ($request['evaluation_plan']['arms'] as $arm) {
            $armModel = $models[(int) ($arm['model_version_id'] ?? 0)] ?? null;
            $slot = data_get($armModel?->metadata, 'native_specialist_council_seed.slot_role');
            $allowed = match ($arm['kind'] ?? '') {
                'candidate' => $slot === 'candidate_carrier', 'ablation' => $slot === 'ablation_carrier',
                'solo' => in_array((int) $arm['model_version_id'], $sourceIds, true), default => false,
            };
            if (! $allowed) throw new LogicException('CANONICAL_COUNCIL_NATIVE_INTENT_ARM_ROLE_MISMATCH');
        }
    }

    private function assertDiscoveryPlan(array $plan, array $manifest): void
    {
        if (($plan['purpose'] ?? null) !== 'research' || ($plan['execution_timeframe'] ?? null) !== 'M5'
            || ($manifest['validation_bundle_protocol'] ?? null) !== MultiTimeframeSnapshotService::DISCOVERY_BUNDLE_PROTOCOL
            || ($manifest['data_role'] ?? null) !== 'pre_2026_discovery_only' || ($manifest['symbol'] ?? null) !== 'XAUUSD'
            || count((array) ($plan['windows'] ?? [])) !== 1) {
            throw new LogicException('CANONICAL_COUNCIL_DISCOVERY_PLAN_SCOPE_INVALID');
        }
        $owner = app(MultiTimeframeSnapshotService::class);
        $resolved = $owner->inspectAndRestoreDiscoveryBundle($manifest);
        $readiness = $resolved['readiness'];
        if (($readiness['allowed'] ?? false) !== true) {
            throw new LogicException('CANONICAL_COUNCIL_DISCOVERY_BUNDLE_NOT_READY:'.($readiness['reason'] ?? 'unknown'));
        }
        $window = array_values($plan['windows'])[0];
        $probe = (array) ($window['prospective_probe_window'] ?? []);
        $scope = (array) ($window['evaluation_scope'] ?? []);
        $calendar = (array) data_get($manifest, 'discovery_scope.calendar', []);
        if (($window['dataset_sha256'] ?? null) !== ($manifest['bundle_hash'] ?? null)
            || ($probe['dataset_hash'] ?? null) !== ($manifest['bundle_hash'] ?? null)
            || ($probe['execution_hash'] ?? null) !== ($plan['execution_hash'] ?? null)
            || ! app(ProspectiveRepairProbeWindowService::class)->attests($probe, [...$probe, 'complete' => true])
            || ($probe['independent_validation'] ?? null) !== false || ($probe['paper_2026_eligible'] ?? null) !== false
            || ($probe['loaded_rows'] ?? null) !== 15512 || ($probe['evaluated_rows'] ?? null) !== 15000
            || ($probe['warmup_rows'] ?? null) !== 512 || ($scope['rows'] ?? null) !== 15000
            || ($scope['decision_rows'] ?? null) !== 14999 || ($scope['warmup_rows'] ?? null) !== 512
            || ($scope['policy_hash'] ?? null) !== $this->epochs->parameterHash($probe)
            || $this->utc($scope['start_inclusive'] ?? null) !== ($probe['evaluated_start'] ?? null)
            || $this->utc($window['start_inclusive'] ?? null) !== ($probe['loaded_start'] ?? null)
            || $this->utc($window['end_exclusive'] ?? null) !== $this->utc($manifest['closed_cutoff'] ?? null)
            || $this->utc($scope['end_exclusive'] ?? null) !== $this->utc($manifest['closed_cutoff'] ?? null)) {
            throw new LogicException('CANONICAL_COUNCIL_DISCOVERY_PLAN_DATA_OR_BUDGET_MISMATCH');
        }
        foreach (['loaded_rows', 'warmup_rows', 'evaluated_rows', 'loaded_start', 'loaded_end', 'evaluated_start', 'evaluated_end', 'evaluated_month_counts'] as $field) {
            if (($calendar[$field] ?? null) !== ($probe[$field] ?? null)) throw new LogicException('CANONICAL_COUNCIL_DISCOVERY_PLAN_CALENDAR_MISMATCH:'.$field);
        }
        foreach (['requested_m5_rows' => 15512, 'evaluated_rows' => 15000, 'warmup_rows' => 512] as $field => $value) {
            if (data_get($manifest, 'bounded_cost_contract.'.$field) !== $value) throw new LogicException('CANONICAL_COUNCIL_DISCOVERY_BUNDLE_BUDGET_MISMATCH');
        }
    }

    /** Prospective continuation compiler reuses this exact data owner before fresh model IDs exist. */
    public function assertProspectiveDiscoveryPlan(array $plan, array $manifest): void
    {
        $this->assertDiscoveryPlan($plan, $manifest);
    }

    private function utc(mixed $value): string
    {
        if (! is_string($value) || $value === '') throw new LogicException('CANONICAL_COUNCIL_DISCOVERY_TIMESTAMP_MISSING');
        return CarbonImmutable::parse($value)->utc()->toIso8601ZuluString();
    }

    private function assertModelOwnership(array $request, array $generationModelIds): void
    {
        $referenced = [$request['carrier_model_version_id'] ?? null,
            data_get($request, 'manifest.evaluation_policy.champion_model_version_id'),
            data_get($request, 'manifest.evaluation_policy.solo_model_version_id')];
        foreach ((array) ($request['manifest']['members'] ?? []) as $member) {
            if (in_array($member['role'] ?? null, SpecialistCouncilContractService::TRADING_ROLES, true)) $referenced[] = $member['model_version_id'] ?? null;
        }
        foreach ((array) ($request['evaluation_plan']['arms'] ?? []) as $arm) $referenced[] = $arm['model_version_id'] ?? null;
        foreach ($referenced as $id) {
            if ((! is_int($id) && (! is_string($id) || ! ctype_digit($id))) || ! in_array((int) $id, $generationModelIds, true)) {
                throw new LogicException('CANONICAL_COUNCIL_MODEL_OUTSIDE_UNUSED_DRAFT');
            }
        }
    }

    private function assertComparativeDesign(array $plan, int $carrierId): void
    {
        if (empty($plan['windows']) || count($plan['windows']) > 12 || empty($plan['arms']) || count($plan['arms']) > 32) {
            throw new InvalidArgumentException('CANONICAL_COUNCIL_PREPARATION_DESIGN_UNBOUNDED');
        }
        foreach ($plan['windows'] as $window) {
            $arms = array_filter($plan['arms'], fn (array $arm): bool => ($arm['window_key'] ?? '') === ($window['window_key'] ?? ''));
            $kinds = array_count_values(array_column($arms, 'kind'));
            if (($kinds['candidate'] ?? 0) !== 1 || ($kinds['solo'] ?? 0) !== 1 || ($kinds['ablation'] ?? 0) < 1) {
                throw new LogicException('CANONICAL_COUNCIL_RESEARCH_REQUIRES_CANDIDATE_SOLO_ABLATION');
            }
            foreach ($arms as $arm) {
                if (($arm['kind'] ?? '') === 'candidate' && (int) $arm['model_version_id'] !== $carrierId) {
                    throw new LogicException('CANONICAL_COUNCIL_CANDIDATE_IS_NOT_DECLARED_CARRIER');
                }
            }
        }
        $seen = [];
        foreach ($plan['arms'] as $arm) {
            $key = ($arm['window_key'] ?? '').':'.($arm['model_version_id'] ?? '');
            if (isset($seen[$key])) throw new LogicException('SAME_NATIVE_MODEL_CANNOT_BE_TWO_ARMS_IN_ONE_WINDOW');
            $seen[$key] = true;
        }
    }

    private function assertOriginalBindings(array $receipt, $models, bool $requiresOpenDraft = true): void
    {
        $body = array_diff_key($receipt, ['receipt_hash' => true]);
        $version = SpecialistCouncilVersion::find($receipt['version_id'] ?? 0);
        $plan = $version ? DB::table('specialist_council_evaluation_plans')->where('specialist_council_version_id', $version->id)->first() : null;
        if (! $version || ! $plan || ! in_array($version->state, $requiresOpenDraft ? ['evaluating'] : ['evaluating', 'evaluated'], true) || ! $this->contracts->manifestValid($version->manifest)
            || ($receipt['manifest_hash'] ?? '') !== $version->manifest_hash || ($receipt['plan_hash'] ?? '') !== $plan->plan_hash
            || ! hash_equals((string) ($receipt['receipt_hash'] ?? ''), $this->epochs->parameterHash($body))) {
            throw new LogicException('CANONICAL_COUNCIL_PREPARATION_ORIGINAL_SEAL_DRIFT');
        }
        foreach ((array) ($receipt['bound_model_hashes'] ?? []) as $id => $hash) {
            if (! isset($models[(int) $id]) || ! hash_equals((string) $hash, $this->contracts->modelHash($models[(int) $id]))) {
                throw new LogicException('CANONICAL_COUNCIL_PREPARATION_ORIGINAL_MODEL_DRIFT');
            }
        }
        $sealedPlan = json_decode($plan->plan, true, 64, JSON_THROW_ON_ERROR);
        if (! hash_equals((string) $plan->plan_hash, $this->epochs->parameterHash($sealedPlan))
            || $version->creator_id !== ($receipt['creator_id'] ?? null)) throw new LogicException('CANONICAL_COUNCIL_PREPARATION_ORIGINAL_SEAL_DRIFT');
        if (($receipt['preparation_source_hash'] ?? null) !== ($sealedPlan['preparation_source_hash'] ?? null)
            || ! hash_equals((string) ($receipt['preparation_source_hash'] ?? ''), app(LabImmutableEvidenceService::class)->codeHash())) {
            throw new LogicException('CANONICAL_COUNCIL_PREPARATION_ORIGINAL_SOURCE_DRIFT');
        }
        $consumption = (array) ($receipt['learning_consumption_receipt'] ?? []);
        $observations = (array) ($consumption['prior_feedback'] ?? []);
        if ($this->epochs->parameterHash($observations) !== ($receipt['prior_feedback_digest'] ?? null)
            || $this->epochs->parameterHash($consumption) !== $this->epochs->parameterHash((array) ($sealedPlan['learning_consumption_receipt'] ?? []))
            || ($consumption['authority'] ?? null) !== 'research_only' || ($consumption['promotion_evidence'] ?? null) !== false) {
            throw new LogicException('CANONICAL_COUNCIL_PREPARATION_ORIGINAL_KNOWLEDGE_DRIFT');
        }
        app(SpecialistCouncilResearchFeedbackService::class)->assertPriorObservations($version->council_id, $observations);
        foreach ($sealedPlan['arms'] as $key => $arm) {
            $binding = $this->lifecycle->evaluationBindingForModel($models[(int) $arm['model_version_id']],
                $sealedPlan['windows'][$arm['window_key']]['dataset_sha256']);
            if (($binding['arm_key'] ?? null) !== $key) throw new LogicException('CANONICAL_COUNCIL_PREPARATION_ORIGINAL_ARM_DRIFT');
        }
    }

    /** Reuse the actual request admission owner before committing any partial preparation. */
    private function assertPlanExecutable(SpecialistCouncilVersion $version, array $plan, string $symbol, ?array $mtfBundle = null): void
    {
        $execution = app(ExecutionContractService::class)->for($symbol, $plan['execution_timeframe']);
        $tradingIds = array_column(array_filter($version->manifest['members'], fn (array $member): bool =>
            in_array($member['role'], SpecialistCouncilContractService::TRADING_ROLES, true)), 'specialist_id');
        foreach ($version->manifest['members'] as $member) {
            if (in_array($member['role'], SpecialistCouncilContractService::TRADING_ROLES, true)
                && array_values($member['scope']['symbols']) !== [$symbol]) {
                throw new LogicException('CANONICAL_COUNCIL_SINGLE_ACCOUNT_MEMBER_SYMBOL_MISMATCH');
            }
        }
        foreach ($plan['arms'] as $key => $arm) {
            if ($arm['evaluation_phase'] !== 'screening') throw new LogicException('CANONICAL_COUNCIL_PREPARATION_REQUIRES_SCREENING_ARMS');
            if ($arm['kind'] === 'ablation' && count($tradingIds) === 1 && $arm['removed_id'] === $tradingIds[0]) {
                throw new LogicException('ABLATION_HAS_NO_TRADING_MEMBER');
            }
            $request = ['symbol' => $symbol, 'timeframe' => $plan['execution_timeframe'], 'evaluation_mode' => 'incremental',
                'replay_dataset_hash' => $plan['windows'][$arm['window_key']]['dataset_sha256'],
                'execution' => $execution['parameters'], 'execution_contract' => $execution];
            if (in_array($arm['kind'], ['candidate', 'ablation', 'retention'], true)) {
                $request['specialist_council_contract'] = ['policy' => $version->manifest['execution']];
            }
            if ($arm['kind'] === 'solo' && isset($plan['solo_comparison'])) {
                if ($mtfBundle !== null) $request['mtf_snapshot_manifest'] = $mtfBundle['manifest'];
                $request['specialist_council_contract'] = $this->lifecycle->runtimeContractForModel(
                    ModelVersion::findOrFail($arm['model_version_id']), $plan['execution_timeframe'],
                    $request['replay_dataset_hash'], $execution['execution_hash'], $mtfBundle, $symbol);
            }
            $this->lifecycle->bindEvaluationRequest($version, $key, $request);
        }
    }

    private function materializeOriginalInstrumentAssignments(LabGeneration $draft, $agents, $models): void
    {
        $instruments = app(LabInstrumentResearchService::class);
        foreach ($agents as $agent) {
            $model = $models[(int) $agent->model_version_id];
            $before = $this->epochs->parameterHash((array) $model->parameters);
            $agent->setRelation('modelVersion', $model)->setRelation('generation', $draft);
            $assignment = $instruments->assignment($agent); $model->refresh(); $agent->setRelation('modelVersion', $model);
            if (($assignment['protocol'] ?? null) !== LabInstrumentResearchService::PROTOCOL
                || ! in_array($assignment['status'] ?? null, ['assigned', 'no_executable_instrument_match'], true)
                || ($assignment['lab_agent_id'] ?? null) !== $agent->id || ($assignment['lab_generation_id'] ?? null) !== $draft->id
                || ($assignment['model_version_id'] ?? null) !== $model->id
                || preg_match('/^[a-f0-9]{64}$/D', (string) ($assignment['assignment_hash'] ?? '')) !== 1
                || ! app(LabImmutableEvidenceService::class)->equivalentJsonValue(data_get($model->metadata, 'instrument_research_assignment'), $assignment)
                || $before !== $this->epochs->parameterHash((array) $model->parameters)
                || (data_get($assignment, 'pair_reservation.required') === true && data_get($assignment, 'pair_reservation.status') !== 'reserved')) {
                throw new LogicException('CANONICAL_COUNCIL_PRESEALED_INSTRUMENT_ASSIGNMENT_INVALID');
            }
        }
    }

    /** Only the original new six-slot constructor can supply this carrier proof. */
    public function assertOriginalSpreadStudyCarriers(LabGeneration $generation, LabAgent $masked, LabAgent $unmasked): array
    {
        $agents = $generation->agents()->with('modelVersion')->orderBy('id')->get();
        $models = $agents->mapWithKeys(fn ($agent) => [(int) $agent->model_version_id => $agent->modelVersion]);
        $intent = $this->nativeConstructorIntent($generation, $models, $agents);
        if (($intent['research_purpose'] ?? null) !== 'spread_context_study' || isset($intent['followup_work_item_id'])
            || isset($intent['followup_resolution_hash']) || $masked->id === $unmasked->id
            || $masked->lab_generation_id !== $generation->id || $unmasked->lab_generation_id !== $generation->id
            || data_get($generation->trigger_context, 'authorized_specialist_council_panel_intent') !== null
            || LabEvaluationRun::where('lab_generation_id', $generation->id)->exists()) {
            throw new LogicException('CANONICAL_SPREAD_STUDY_ORIGINAL_UNUSED_CONSTRUCTOR_REQUIRED');
        }
        $plan = (array) data_get($generation->trigger_context, 'generation_plan', []);
        $declaration = (array) ($intent['study_context_declaration'] ?? []);
        LabPopulationService::assertNativeStudyContextDeclaration($declaration);
        if (count($plan) !== 6) throw new LogicException('CANONICAL_SPREAD_STUDY_ORIGINAL_SIX_SLOT_PLAN_REQUIRED');
        $sources = []; $carriers = []; $episodes = []; $roles = LabPopulationService::nativeCouncilSlotRoles($intent);
        foreach ($agents as $index => $agent) {
            $model = $agent->modelVersion; $seed = (array) data_get($model->metadata, 'native_specialist_council_seed', []);
            $planned = (array) data_get($plan[$index], 'niche.native_specialist_council_seed', []);
            if (($seed['slot_role'] ?? null) !== $roles[$index] || ($seed['research_purpose'] ?? null) !== 'spread_context_study'
                || ($seed['construction_slot'] ?? null) !== $index + 1 || ($planned['intent_hash'] ?? null) !== $intent['intent_hash']
                || ($planned['slot_role'] ?? null) !== $roles[$index] || ($planned['research_purpose'] ?? null) !== 'spread_context_study'
                || ($planned['construction_slot'] ?? null) !== $index + 1 || count((array) $agent->parameter_diff) !== 0
                || data_get($model->metadata, 'parent_mentor_broker.parameter_baseline_source') !== 'schema_defaults'
                || $agent->lifecycle_status !== 'draft' || LabEvaluationRun::where('model_version_id', $model->id)->exists()) {
                throw new LogicException('CANONICAL_SPREAD_STUDY_ORIGINAL_SLOT_OR_VECTOR_DRIFT');
            }
            foreach (['specialist_council_evaluation', 'authorized_specialist_council_panel_seed', 'cooperative_experiment_block',
                'causal_learning_cohort', 'prospective_repair', 'academy_experiment', 'control_pair_contract', 'activation_factorial', 'phase_scope_probe'] as $owner) {
                if (! empty(data_get($model->metadata, $owner))) throw new LogicException('CANONICAL_SPREAD_STUDY_EXISTING_OWNER_FORBIDDEN');
            }
            $owned = \App\Models\AgentLearningEpisode::where('lab_agent_id', $agent->id)->get();
            $episode = $owned->first();
            $fingerprint = hash('sha256', $agent->strategy_family.'|'.json_encode(
                app(StrategyParameterSchemaService::class)->canonicalizeForIdentity($agent->strategy_family, (array) $model->parameters), JSON_PRESERVE_ZERO_FRACTION));
            if ($owned->count() !== 1 || ! $episode || $episode->stage !== 'mutation_selection' || $episode->decision !== 'CONTROL'
                || $episode->model_version_id !== $model->id || $episode->decision_key !== 'generation:'.$generation->id.':agent:'.$agent->id
                || $episode->id !== data_get($model->metadata, 'learning_decision.episode_id') || $episode->settled_at !== null
                || $episode->parameter_hash !== $fingerprint || data_get($model->metadata, 'parameter_fingerprint') !== $fingerprint
                || $episode->settlement()->exists()) throw new LogicException('CANONICAL_SPREAD_STUDY_ORIGINAL_CONSTRUCTOR_EPISODE_REQUIRED');
            $episodes[] = ['episode_id' => (int) $episode->id, 'agent_id' => (int) $agent->id,
                'model_id' => (int) $model->id, 'stage' => 'mutation_selection'];
            $reference = ['agent_id' => (int) $agent->id, 'model_version_id' => (int) $model->id];
            if ($index < 4) $sources[$roles[$index]] = $reference; else $carriers[$roles[$index]] = $reference;
        }
        if ($carriers['study_masked_carrier']['agent_id'] !== (int) $masked->id
            || $carriers['study_unmasked_carrier']['agent_id'] !== (int) $unmasked->id) throw new LogicException('CANONICAL_SPREAD_STUDY_EXACT_CARRIER_ROLES_REQUIRED');
        $day = $models[$sources['source_day']['model_version_id']];
        $dayAgent = $agents->firstWhere('id', $sources['source_day']['agent_id']);
        foreach ([$masked, $unmasked] as $carrier) {
            $model = $models[(int) $carrier->model_version_id];
            if ($carrier->strategy_family !== $dayAgent->strategy_family || ! app(LabImmutableEvidenceService::class)->equivalentJsonValue($model->parameters, $day->parameters)) {
                throw new LogicException('CANONICAL_SPREAD_STUDY_EXACT_PRISTINE_DAY_VECTOR_REQUIRED');
            }
        }
        return ['intent_hash' => $intent['intent_hash'], 'research_purpose' => 'spread_context_study', 'source_ids' => $sources,
            'carrier_role_ids' => $carriers, 'constructor_episode_ids' => $episodes, 'study_context_declaration' => $declaration,
            'promotion_evidence' => false];
    }

    private function prepareSpreadContextStudy(LabGeneration $generation, array $request): array
    {
        $keys = ['protocol', 'research_purpose', 'creator_id', 'evaluator_id', 'research_question', 'manifest', 'study_spec', 'discovery_bundle_manifest'];
        $study = (array) ($request['study_spec'] ?? []);
        if (array_diff(array_keys($request), $keys) !== [] || array_diff($keys, array_keys($request)) !== []
            || ($request['protocol'] ?? null) !== self::PROTOCOL || ($request['research_purpose'] ?? null) !== 'spread_context_study'
            || ! is_array($request['manifest']) || ! is_array($request['discovery_bundle_manifest'])
            || ! is_string($request['creator_id']) || ! is_string($request['evaluator_id']) || $request['creator_id'] === ''
            || $request['evaluator_id'] === '' || $request['creator_id'] === $request['evaluator_id']
            || array_diff(array_keys($study), ['specialist_id', 'exact_context', 'liquidity_atr_binding', 'initial_capital', 'minimum_paired_opportunities', 'prospective_probe_window']) !== []
            || ! is_numeric($study['initial_capital'] ?? null) || ! is_finite((float) $study['initial_capital']) || $study['initial_capital'] <= 0) {
            throw new InvalidArgumentException('CANONICAL_SPREAD_STUDY_PREPARATION_INPUT_INVALID');
        }
        $lease = Cache::lock("lab-generation-dispatch:{$generation->ai_laboratory_id}:H1:{$generation->id}",
            max(300, (int) config('services.lab_queue.dispatch_lease_seconds', 3600)));
        if (! $lease->get()) throw new LogicException('CANONICAL_COUNCIL_DISPATCH_LEASE_BUSY');
        try { return DB::transaction(function () use ($generation, $request, $study): array {
            $draft = LabGeneration::whereKey($generation->id)->lockForUpdate()->firstOrFail();
            $context = (array) $draft->trigger_context; $prior = $context['specialist_council_preparation'] ?? null;
            if ($prior !== null) {
                if (($prior['request_hash'] ?? null) !== $this->epochs->parameterHash($request)) throw new LogicException('CANONICAL_COUNCIL_PREPARATION_RETRY_CHANGED');
                $this->verifiedSpreadStudyGeneration($draft); return $prior;
            }
            if ($draft->status !== 'draft' || $draft->completed_at !== null) throw new LogicException('CANONICAL_COUNCIL_REQUIRES_COMPLETE_UNUSED_DRAFT');
            foreach (['research_release', 'canonical_dataset_snapshots', 'mtf_bundle_manifest', 'queue_batches', 'authorized_specialist_council_panel_intent',
                'academy_trial_id', 'prospective_repair', 'causal_learning_cohort', 'cooperative_experiment_blocks', NativeSpreadContextStudyService::MARKER] as $owner) {
                if (! empty($context[$owner])) throw new LogicException('CANONICAL_SPREAD_STUDY_EXISTING_OWNER_FORBIDDEN');
            }
            $agents = $draft->agents()->with('modelVersion')->orderBy('id')->lockForUpdate()->get();
            $models = ModelVersion::whereIn('id', $agents->pluck('model_version_id'))->lockForUpdate()->get()->keyBy('id');
            $masked = $agents->first(fn ($agent) => data_get($agent->modelVersion->metadata, 'native_specialist_council_seed.slot_role') === 'study_masked_carrier');
            $unmasked = $agents->first(fn ($agent) => data_get($agent->modelVersion->metadata, 'native_specialist_council_seed.slot_role') === 'study_unmasked_carrier');
            if (! $masked || ! $unmasked) throw new LogicException('CANONICAL_SPREAD_STUDY_ORIGINAL_UNUSED_CONSTRUCTOR_REQUIRED');
            $proof = $this->assertOriginalSpreadStudyCarriers($draft, $masked, $unmasked);
            $intent = data_get($context, 'native_specialist_council_intent');
            if ($request['creator_id'] !== $intent['creator_id'] || $request['research_question'] !== $intent['research_question']) throw new LogicException('CANONICAL_COUNCIL_NATIVE_INTENT_REQUEST_MISMATCH');
            $declaration = $proof['study_context_declaration'];
            if (($study['specialist_id'] ?? null) !== $declaration['specialist_id']
                || ($study['liquidity_atr_binding'] ?? null) !== $declaration['liquidity_atr_binding']
                || $this->epochs->parameterHash((array) ($study['exact_context'] ?? [])) !== $this->epochs->parameterHash($declaration['exact_context'])) {
                throw new LogicException('CANONICAL_SPREAD_STUDY_ORIGINAL_CONTEXT_DECLARATION_MISMATCH');
            }
            $members = array_filter((array) ($request['manifest']['members'] ?? []), fn ($member) => in_array($member['role'] ?? null, SpecialistCouncilContractService::TRADING_ROLES, true));
            if (count($members) !== 4 || count(array_unique(array_column($members, 'role'))) !== 4) throw new LogicException('CANONICAL_COUNCIL_NATIVE_INTENT_SOURCE_ROLE_MISMATCH');
            foreach ($members as $member) if (($proof['source_ids']['source_'.$member['role']]['model_version_id'] ?? null) !== ($member['model_version_id'] ?? null)) throw new LogicException('CANONICAL_COUNCIL_NATIVE_INTENT_SOURCE_ROLE_MISMATCH');
            foreach ($models as $model) if (data_get($model->metadata, 'specialist_council') !== null || data_get($model->metadata, NativeSpreadContextStudyService::MARKER) !== null) throw new LogicException('CANONICAL_COUNCIL_NATIVE_MODEL_ALREADY_BOUND');
            $execution = app(ExecutionContractService::class)->for('XAUUSD', 'M5');
            $manifest = $request['discovery_bundle_manifest']; $probe = (array) ($study['prospective_probe_window'] ?? []);
            $plan = $this->spreadStudyDataPlan($manifest, $probe, $execution['execution_hash']);
            $this->assertDiscoveryPlan($plan, $manifest);
            foreach ($models as $model) if (data_get($model->metadata, 'instrument_research_assignment') !== null) throw new LogicException('CANONICAL_SPREAD_STUDY_PREDECLARATION_ASSIGNMENT_FORBIDDEN');
            // The original constructor intent owns this semantic predicate.
            // Install it through the existing membership seam before the
            // canonical assignment and manifest hashes are produced.
            $targetId = $proof['source_ids']['source_'.$declaration['specialist_id']]['model_version_id'];
            $target = $models[$targetId]; $targetMetadata = (array) $target->metadata;
            $cell = [...$declaration['exact_context'], 'spread_liquidity_state' => $declaration['spread_context_predicate'],
                'liquidity_atr_binding' => $declaration['liquidity_atr_binding']];
            $cell['cell_hash'] = $this->epochs->parameterHash($cell);
            if (data_get($targetMetadata, 'specialist_council_membership.contextual_cell') !== null) throw new LogicException('CANONICAL_SPREAD_STUDY_LATE_CONTEXT_INJECTION_FORBIDDEN');
            data_set($targetMetadata, 'specialist_council_membership.contextual_cell', $cell);
            $target->update(['metadata' => $targetMetadata]);
            $this->materializeOriginalInstrumentAssignments($draft, $agents, $models);
            $version = $this->lifecycle->registerDraft($request['manifest'], $request['creator_id']);
            foreach ([$masked, $unmasked] as $agent) $this->lifecycle->attachResearchModel($version, $models[(int) $agent->model_version_id]->fresh());
            $base = ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'evaluation_mode' => 'incremental', 'initial_balance' => $study['initial_capital'],
                'execution' => $execution['parameters'], 'execution_contract' => $execution, 'replay_dataset_hash' => $manifest['bundle_hash'],
                'mtf_snapshot_manifest' => $manifest, 'policy_context' => ['prospective_probe_window' => $probe], 'emit_decision_trace' => true, 'include_trades' => true];
            $base['specialist_council_contract'] = $this->lifecycle->runtimeContractForModel($models[(int) $masked->model_version_id]->fresh(),
                'M5', $manifest['bundle_hash'], $execution['execution_hash'], ['bundle_hash' => $manifest['bundle_hash'], 'manifest' => $manifest], 'XAUUSD');
            $registration = app(NativeSpreadContextStudyService::class)->preregister($masked, $unmasked,
                ['request' => $base, 'specialist_id' => $study['specialist_id'] ?? '', 'exact_context' => $study['exact_context'] ?? [],
                    'liquidity_atr_binding' => $study['liquidity_atr_binding'],
                    'minimum_paired_opportunities' => $study['minimum_paired_opportunities'] ?? null]);
            $hashes = []; foreach ($models as $model) $hashes[(string) $model->id] = $this->contracts->modelHash($model->fresh());
            $receipt = ['protocol' => self::PROTOCOL, 'status' => 'prepared_for_canonical_dispatch', 'research_purpose' => 'spread_context_study',
                'lab_generation_id' => (int) $draft->id, 'request_hash' => $this->epochs->parameterHash($request), 'version_id' => (int) $version->id,
                'manifest_hash' => $version->manifest_hash, 'creator_id' => $request['creator_id'], 'evaluator_id' => $request['evaluator_id'],
                'research_question' => $request['research_question'], 'native_intent_hash' => $proof['intent_hash'], 'original_constructor' => $proof,
                'plan_hash' => $this->epochs->parameterHash($plan),
                'generation_agent_ids' => $agents->pluck('id')->all(), 'generation_model_hashes' => $hashes,
                'study_registration' => $registration, 'study_spec' => $study, 'data_plan' => $plan,
                'dispatch_agent_ids' => [(int) $masked->id, (int) $unmasked->id], 'base_request' => $base,
                'discovery_bundle_hash' => $manifest['bundle_hash'], 'discovery_manifest_hash' => $this->epochs->parameterHash($manifest),
                'preparation_source_hash' => app(LabImmutableEvidenceService::class)->codeHash(), 'prepared_at' => now()->utc()->toIso8601String(),
                'next_owner' => 'canonical_lab_dispatcher', 'promotion_evidence' => false, 'paper_authority_granted' => false];
            // Seal the original DB JSON representation, including its 1.0 -> 1
            // projection. A later verifier must not re-seal or relax any value.
            $receipt = json_decode(json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            $receipt['receipt_hash'] = $this->epochs->parameterHash($receipt);
            $draft->refresh()->update(['trigger_context' => [...(array) $draft->trigger_context, 'specialist_council_preparation' => $receipt,
                'mtf_bundle_hash' => $manifest['bundle_hash'], 'mtf_bundle_manifest' => $manifest]]);
            return $receipt;
        }, 1); } finally { $lease->release(); }
    }

    private function spreadStudyDataPlan(array $manifest, array $probe, string $executionHash): array
    {
        return ['purpose' => 'research', 'execution_timeframe' => 'M5', 'execution_hash' => $executionHash, 'windows' => [[
            'window_key' => 'original-spread-context-study', 'dataset_sha256' => $manifest['bundle_hash'] ?? null,
            'start_inclusive' => $probe['loaded_start'] ?? null, 'end_exclusive' => $manifest['closed_cutoff'] ?? null,
            'prospective_probe_window' => $probe, 'evaluation_scope' => ['start_inclusive' => $probe['evaluated_start'] ?? null,
                'end_exclusive' => $manifest['closed_cutoff'] ?? null, 'rows' => $probe['evaluated_rows'] ?? null,
                'decision_rows' => isset($probe['evaluated_rows']) ? $probe['evaluated_rows'] - 1 : null,
                'warmup_rows' => $probe['warmup_rows'] ?? null, 'policy_hash' => $this->epochs->parameterHash($probe)]]]];
    }

    private function verifiedSpreadStudyGeneration(LabGeneration $generation): array
    {
        $receipt = (array) data_get($generation->trigger_context, 'specialist_council_preparation', []);
        $agents = $generation->agents()->with('modelVersion')->orderBy('id')->get();
        $models = $agents->mapWithKeys(fn ($agent) => [(int) $agent->model_version_id => $agent->modelVersion]);
        $intent = $this->nativeConstructorIntent($generation, $models, $agents);
        LabPopulationService::assertNativeStudyContextDeclaration((array) ($intent['study_context_declaration'] ?? []));
        $version = SpecialistCouncilVersion::find($receipt['version_id'] ?? 0);
        if (($receipt['protocol'] ?? null) !== self::PROTOCOL || ($receipt['status'] ?? null) !== 'prepared_for_canonical_dispatch'
            || ($receipt['lab_generation_id'] ?? null) !== $generation->id
            || ($receipt['creator_id'] ?? null) !== $intent['creator_id'] || ($receipt['research_question'] ?? null) !== $intent['research_question']
            || ($receipt['research_purpose'] ?? null) !== 'spread_context_study' || ($intent['research_purpose'] ?? null) !== 'spread_context_study'
            || ($receipt['native_intent_hash'] ?? null) !== $intent['intent_hash'] || ! $version || $version->state !== 'draft'
            || $this->epochs->parameterHash((array) data_get($receipt, 'original_constructor.study_context_declaration', [])) !== $this->epochs->parameterHash($intent['study_context_declaration'])
            || ! $this->contracts->manifestValid($version->manifest) || $version->manifest_hash !== ($receipt['manifest_hash'] ?? null)
            || ($receipt['receipt_hash'] ?? null) !== $this->epochs->parameterHash(array_diff_key($receipt, ['receipt_hash' => true]))
            || $agents->pluck('id')->all() !== ($receipt['generation_agent_ids'] ?? null)
            || count((array) ($receipt['generation_model_hashes'] ?? [])) !== 6
            || ($receipt['preparation_source_hash'] ?? null) !== app(LabImmutableEvidenceService::class)->codeHash()
            || ($receipt['paper_authority_granted'] ?? null) !== false || ($receipt['promotion_evidence'] ?? null) !== false) {
            throw new LogicException('CANONICAL_SPREAD_STUDY_ORIGINAL_PREPARATION_DRIFT');
        }
        foreach ($agents as $agent) if (($receipt['generation_model_hashes'][(string) $agent->model_version_id] ?? null) !== $this->contracts->modelHash($agent->modelVersion)) throw new LogicException('CANONICAL_COUNCIL_PREPARATION_ORIGINAL_MODEL_DRIFT');
        $carrierIds = $receipt['dispatch_agent_ids'] ?? [];
        if (count($carrierIds) !== 2) throw new LogicException('CANONICAL_SPREAD_STUDY_EXACT_CARRIER_ROLES_REQUIRED');
        foreach (['study_masked_carrier', 'study_unmasked_carrier'] as $index => $role) {
            $carrier = $agents->firstWhere('id', $carrierIds[$index]);
            if (! $carrier || data_get($carrier->modelVersion->metadata, 'native_specialist_council_seed.slot_role') !== $role
                || (int) data_get($carrier->modelVersion->metadata, 'specialist_council.version_id') !== (int) $version->id
                || ! app(NativeSpreadContextStudyService::class)->declares($carrier->modelVersion)) throw new LogicException('CANONICAL_SPREAD_STUDY_ORIGINAL_BINDING_DRIFT');
            app(NativeSpreadContextStudyService::class)->bindRequest($receipt['base_request'], [$carrier->modelVersion]);
        }
        $release = data_get($generation->trigger_context, 'research_release.source_hash');
        if ($release !== null && $release !== $receipt['preparation_source_hash']) throw new LogicException('CANONICAL_COUNCIL_PREPARATION_ORIGINAL_SOURCE_DRIFT');
        return [$receipt, $receipt['data_plan']];
    }

    /** Source references await neutral pair closure; only the two original carriers enter replay. */
    public function spreadStudyDispatchAgentIds(LabGeneration $generation): ?array
    {
        if (data_get($generation->trigger_context, 'native_specialist_council_intent.research_purpose') !== 'spread_context_study') return null;
        [$receipt] = $this->verifiedSpreadStudyGeneration($generation);
        return $receipt['dispatch_agent_ids'];
    }
}
