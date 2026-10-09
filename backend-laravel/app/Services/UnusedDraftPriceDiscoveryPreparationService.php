<?php

namespace App\Services;

use App\Models\AgentLearningEpisode;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\MarketTrainingArchive;
use App\Models\ModelVersion;
use App\Models\ResearchLoopDecision;
use App\Models\SystemEvent;
use App\Services\MarketData\SecondaryM5ResearchRecoveryService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use LogicException;

/** One explicitly authorized, unused G263 price question; not another Root1 allowance. */
class UnusedDraftPriceDiscoveryPreparationService
{
    public const PROTOCOL = 'unused_draft_price_discovery_preparation_v1';
    public const INTENT = 'unused_draft_price_discovery_intent';
    public const OWNER = 'unused_draft_price_discovery';
    public const MODEL_SEAL = 'unused_draft_price_discovery_seal';
    public const COMMAND = 'trading:prepare-unused-price-discovery';
    public const GENERATION_ID = 357;
    public const GENERATION_NUMBER = 263;
    public const RESEARCH_ONLY = 'UNUSED_DRAFT_PRICE_DISCOVERY_RESEARCH_ONLY';
    private const CONTEXT_SEALS = ['research_release', 'mtf_bundle_hash', 'mtf_bundle_manifest', 'mtf_runtime_contract', 'queue_batches'];
    private const FOREIGN_PURPOSES = ['native_specialist_council_intent', 'authorized_specialist_council_panel_intent',
        'specialist_council_authorized_panel', 'specialist_council_preparation', 'native_spread_context_study',
        'academy_trial_id', 'academy_experiment', 'academy_control_admission', 'causal_learning_cohort',
        'causal_compounding_kernel', 'prospective_repair', 'activation_factorial', 'phase_scope_probe'];
    private const MODEL_PURPOSES = ['native_specialist_council_seed', 'authorized_specialist_council_panel_seed',
        'specialist_council', 'specialist_council_evaluation', 'native_spread_context_study', 'academy_experiment',
        'academy_control_admission', 'causal_learning_cohort', 'prospective_repair', 'activation_factorial', 'phase_scope_probe'];

    public function __construct(private ResearchPaperEpochContractService $hashes,
        private LabGenerationContextService $contexts, private LabQueueJobInspector $queues,
        private MultiTimeframeSnapshotService $mtf, private SecondaryM5ResearchRecoveryService $secondary) {}

    public function declares(?LabGeneration $generation): bool
    {
        return $generation !== null && (data_get($generation->trigger_context, self::INTENT) !== null
            || data_get($generation->trigger_context, self::OWNER) !== null
            || ((int) $generation->id === self::GENERATION_ID && $generation->agents()
                ->whereHas('modelVersion', fn ($model) => $model->whereNotNull('metadata->'.self::MODEL_SEAL))->exists()));
    }

    /** Read-only registration preview. It never freezes files or changes the selected native input. */
    public function registration(LabGeneration $generation, string $dataset, string $question): array
    {
        $this->assertUnused($generation);
        if (trim($question) === '' || strlen($question) > 2048) $this->deny('QUESTION_INVALID');
        $archive = MarketTrainingArchive::where('dataset_key', $dataset)->where('provider', 'mixed')
            ->where('symbol', 'XAUUSD')->where('timeframe', 'M5')->first();
        if (! $archive) $this->deny('VERIFIED_MIXED_ARCHIVE_REQUIRED');
        $proof = $this->secondary->verify($archive);
        $parentReceipt = (array) data_get($archive->metrics, 'secondary_m5_research_receipt', []);
        if (($proof['verified'] ?? null) !== true || ($proof['provider'] ?? null) !== 'mixed'
            || ($parentReceipt['native_equivalence_proven'] ?? null) !== false
            || ($parentReceipt['volume_available'] ?? null) !== false
            || ($parentReceipt['quote_liquidity_inherited'] ?? null) !== false
            || data_get($proof, 'calendar_scope.full_source_unexpected_after') !== 0) $this->deny('MIXED_PROVENANCE_INVALID');
        $native = $this->mtf->agentValidationReadiness('XAUUSD');
        if (($native['ready'] ?? null) !== false || ($native['reason'] ?? null) !== 'HISTORICAL_M5_CONTINUITY_SCOPE_UNRESOLVED'
            || ($native['full_source_unexpected_gaps'] ?? null) !== 17
            || data_get($native, 'prospective_m5_repair.verified') !== true
            || data_get($native, 'prospective_m5_repair.prospective_m5_source_sha256') !== ($parentReceipt['native_parent_price_sha256'] ?? null)) {
            $this->deny('ORIGINAL_NATIVE_SEVENTEEN_GAP_DEPENDENCY_CHANGED');
        }
        $snapshot = $this->snapshot($generation);
        $dependency = ['protocol' => 'unused_draft_native_full_dependency_disposition_v1',
            'reason' => $native['reason'], 'full_source_unexpected_gaps' => 17,
            'native_readiness' => array_intersect_key($native, array_flip(['ready', 'reason', 'full_source_unexpected_gaps', 'prospective_m5_repair'])),
            'strategy_verdict' => 'withheld', 'data_dependency_resolved' => false,
            'native_archive_repaired' => false, 'independent_evidence' => false, 'promotion_evidence' => false];
        $body = ['protocol' => self::PROTOCOL, 'generation_id' => (int) $generation->id,
            'generation_number' => (int) $generation->generation, 'dataset_key' => $dataset,
            'research_question' => trim($question), 'original_snapshot' => $snapshot,
            'original_snapshot_hash' => $this->contextHash($snapshot),
            'mixed_proof_hash' => $this->contextHash($proof), 'mixed_proof' => $proof,
            'native_full_dependency' => $dependency, 'native_full_dependency_hash' => $this->contextHash($dependency),
            'resource_contract' => ['evaluated_rows' => 15000, 'warmup_rows' => 512, 'maximum_agents' => 20,
                'maximum_attempts_per_original_agent' => 1, 'maximum_preparations_per_physical_question' => 1,
                'python_ceiling_seconds' => 1680, 'transport_ceiling_seconds' => 1800,
                'screening_job_ceiling_seconds' => 2400, 'full_replay_forbidden' => true],
            'old_root1_allowance_reopened' => false, 'parameter_mutation_allowed' => false,
            'native_quote_inheritance' => false, 'native_volume_inheritance' => false,
            'price_basis' => 'attributed_native_bid_and_secondary_composite_mid_modelled_research_prices',
            'independent_evidence' => false, 'full_validation_eligible' => false, 'paper_eligible' => false,
            'causal_credit_allowed' => false, 'economic_credit_allowed' => false, 'promotion_evidence' => false];
        // Intent labels, provider labels, deployment code and slicing cannot renew the physical allowance.
        $physicalModels = array_map(fn ($model) => array_intersect_key($model, array_flip(['family', 'parameter_hash', 'parameter_diff_hash'])), $snapshot['agents']);
        $body['physical_question_key'] = $this->contextHash([self::PROTOCOL, $physicalModels,
            data_get($proof, 'original_budget_scope_anchor'), $parentReceipt['native_parent_price_sha256']]);
        $body['resource_contract']['unchanged_ordinary_execution_warmup_rows'] = 200;
        $body['resource_contract']['expected_ordinary_decision_candle_coverage'] = 14800;
        $body['intent_hash'] = $this->contextHash($body);
        return $body;
    }

    public function registerIntent(LabGeneration $generation, array $request, array $approval): array
    {
        return DB::transaction(function () use ($generation, $request, $approval): array {
            $locked = LabGeneration::whereKey($generation->id)->lockForUpdate()->firstOrFail();
            $current = $this->registration($locked, (string) ($request['dataset_key'] ?? ''), (string) ($request['research_question'] ?? ''));
            if (($request['intent_hash'] ?? null) !== $current['intent_hash']) $this->deny('REGISTRATION_SNAPSHOT_CHANGED');
            $event = SystemEvent::find((int) ($approval['event_id'] ?? 0));
            if (! $event || data_get($event->payload, 'protocol') !== 'operator_apply_approval_v1'
                || data_get($event->payload, 'operation') !== 'unused-draft-price-discovery-intent'
                || data_get($event->payload, 'scope.intent_hash') !== $current['intent_hash']) $this->deny('ORIGINAL_OPERATOR_APPROVAL_REQUIRED');
            $prior = data_get($locked->trigger_context, self::INTENT);
            if ($prior !== null) {
                if (($prior['intent_hash'] ?? null) !== $current['intent_hash']) $this->deny('INTENT_RETRY_CHANGED');
                return $prior;
            }
            if (LabGeneration::whereKeyNot($locked->id)->where('trigger_context->'.self::INTENT.'->physical_question_key', $current['physical_question_key'])->exists()) {
                $this->deny('PHYSICAL_QUESTION_ALLOWANCE_EXHAUSTED');
            }
            $intent = [...$current, 'approval_event_id' => (int) $event->id,
                'registered_at' => now()->utc()->toIso8601String()];
            $this->contexts->update($locked, fn ($context) => [...$context, self::INTENT => $intent]);
            return $intent;
        });
    }

    /** Arbiter proposal only: neither a second scheduler nor queue admission. */
    public function proposal(LabGeneration $generation): ?array
    {
        if (! $this->declares($generation)) return null;
        try {
            $intent = $this->intent($generation);
            $prepared = data_get($generation->trigger_context, self::OWNER);
            if ($prepared !== null) {
                $this->assertOwner($generation, (array) data_get($generation->trigger_context, 'mtf_bundle_manifest', []));
                return ['status' => 'prepared', 'intent_hash' => $intent['intent_hash'], 'preparation_hash' => $prepared['preparation_hash'],
                    'generation_id' => (int) $generation->id,
                    'control_revision' => data_get(app(AutonomousModeService::class)->status('XAUUSD', 'H1'), 'changed_at'), 'promotion_evidence' => false];
            }
            $current = $this->registration($generation, $intent['dataset_key'], $intent['research_question']);
            if ($current['intent_hash'] !== $intent['intent_hash']) $this->deny('ORIGINAL_INTENT_DRIFT');
            return ['status' => 'would_prepare', 'intent_hash' => $intent['intent_hash'],
                'physical_question_key' => $intent['physical_question_key'], 'generation_id' => (int) $generation->id,
                'control_revision' => data_get(app(AutonomousModeService::class)->status('XAUUSD', 'H1'), 'changed_at'),
                'promotion_evidence' => false];
        } catch (\Throwable $error) {
            return ['status' => 'blocked', 'reason' => $error instanceof LogicException ? $error->getMessage()
                : 'UNUSED_PRICE_DISCOVERY_OWNER_UNAVAILABLE', 'promotion_evidence' => false];
        }
    }

    /** Fresh canonical physical dependency, not a replay permission or the old seventeen-gap copy. */
    public function currentNativeDependency(): array
    {
        try {
            $readiness = $this->mtf->agentValidationReadiness('XAUUSD');
            $repair = (array) ($readiness['prospective_m5_repair'] ?? []);
            $path = (string) ($repair['prospective_m5_source_path'] ?? data_get($readiness, 'streams.M5.path', ''));
            $claimed = (string) ($repair['prospective_m5_source_sha256'] ?? data_get($readiness, 'streams.M5.sha256', ''));
            $dependency = ['protocol' => 'unused_price_discovery_current_native_dependency_v1',
                'native_owner_ready' => ($readiness['ready'] ?? null) === true, 'ready' => false,
                'reason' => $readiness['reason'] ?? 'NATIVE_READINESS_UNKNOWN',
                'claimed_m5_sha256' => $claimed, 'actual_m5_sha256' => is_file($path) ? hash_file('sha256', $path) : null,
                'full_source_unexpected_gaps' => $readiness['full_source_unexpected_gaps'] ?? null,
                'native_calendar_hash' => $this->contextHash((array) ($repair['calendar_scope'] ?? [])),
                'native_repair_hash' => $repair['repair_hash'] ?? null,
                'old_price_question_spent' => true, 'old_owner_replay_authorized' => false, 'promotion_evidence' => false];
            $dependency['ready'] = $dependency['native_owner_ready'] && preg_match('/^[a-f0-9]{64}$/D', $claimed) === 1
                && $dependency['actual_m5_sha256'] === $claimed;
        } catch (\Throwable) {
            $dependency = ['protocol' => 'unused_price_discovery_current_native_dependency_v1', 'ready' => false,
                'reason' => 'CURRENT_NATIVE_DEPENDENCY_UNAVAILABLE', 'old_price_question_spent' => true,
                'old_owner_replay_authorized' => false, 'promotion_evidence' => false];
        }
        $dependency['dependency_hash'] = $this->contextHash($dependency);
        return $dependency;
    }

    public function prepareFromDecision(ResearchLoopDecision $decision): array
    {
        $generation = $this->decisionGeneration($decision, 'PREPARE_UNUSED_DRAFT_PRICE_DISCOVERY');
        $lease = Cache::lock('lab-generation-dispatch:'.$generation->ai_laboratory_id.':H1:'.$generation->id, 3600);
        if (! $lease->get()) $this->deny('DISPATCH_LEASE_BUSY');
        try {
            return DB::transaction(function () use ($generation, $decision): array {
                $locked = LabGeneration::whereKey($generation->id)->lockForUpdate()->firstOrFail();
                ModelVersion::whereIn('id', $locked->agents()->pluck('model_version_id'))->orderBy('id')->lockForUpdate()->get();
                $proposal = $this->proposal($locked);
                if (($proposal['status'] ?? null) === 'prepared') return data_get($locked->trigger_context, self::OWNER);
                if (($proposal['status'] ?? null) !== 'would_prepare'
                    || $this->contextHash($proposal) !== $this->contextHash(data_get($decision->evidence_snapshot, 'price_discovery_proposal', []))) {
                    $this->deny('CURRENT_ARBITER_PROPOSAL_DRIFT');
                }
                $intent = $this->intent($locked);
                $bundle = $this->mtf->forProspectiveCleanDiscovery('XAUUSD', $intent['dataset_key'], 15000, 512);
                $this->assertManifest($bundle['manifest'], $intent);
                // Materialize only the existing canonical assignment, before sealing runtime identity.
                foreach ($locked->agents()->with('modelVersion')->orderBy('id')->get() as $agent) {
                    $before = $this->hashes->parameterHash((array) $agent->modelVersion->parameters);
                    $assignment = app(LabInstrumentResearchService::class)->assignment($agent);
                    $model = $agent->modelVersion->fresh();
                    if ($before !== $this->hashes->parameterHash((array) $model->parameters)) $this->deny('PARAMETER_MUTATION_FORBIDDEN');
                    if (($assignment['protocol'] ?? null) !== LabInstrumentResearchService::PROTOCOL
                        || ! in_array($assignment['status'] ?? null, ['assigned', 'no_executable_instrument_match'], true)
                        || ($assignment['lab_agent_id'] ?? null) !== $agent->id
                        || ($assignment['lab_generation_id'] ?? null) !== $locked->id
                        || ($assignment['model_version_id'] ?? null) !== $model->id
                        || $this->contextHash(data_get($model->metadata, 'instrument_research_assignment', [])) !== $this->contextHash($assignment)
                        || (data_get($assignment, 'pair_reservation.required') === true && data_get($assignment, 'pair_reservation.status') !== 'reserved')) {
                        $this->deny('PRESEALED_CANONICAL_INSTRUMENT_ASSIGNMENT_INVALID');
                    }
                }
                $prepared = ['protocol' => self::PROTOCOL, 'intent_hash' => $intent['intent_hash'],
                    'physical_question_key' => $intent['physical_question_key'], 'generation_id' => (int) $locked->id,
                    'original_snapshot_hash' => $intent['original_snapshot_hash'],
                    'native_full_dependency_hash' => $intent['native_full_dependency_hash'],
                    'bundle_hash' => $bundle['bundle_hash'], 'manifest_hash' => $this->contextHash($bundle['manifest']),
                    'source_hash' => app(LabImmutableEvidenceService::class)->codeHash(),
                    'arbiter_decision_id' => (int) $decision->id, 'arbiter_decision_key' => $decision->decision_key,
                    'control_revision' => $proposal['control_revision'], 'prepared_at' => now()->utc()->toIso8601String(),
                    'resource_contract' => $intent['resource_contract'], 'promotion_evidence' => false];
                $prepared['preparation_hash'] = $this->contextHash($prepared);
                foreach ($locked->agents()->with('modelVersion')->get() as $agent) {
                    $model = $agent->modelVersion;
                    if (data_get($model->metadata, self::MODEL_SEAL) !== null) $this->deny('MODEL_ALREADY_BOUND');
                    $model->update(['metadata' => [...(array) $model->metadata, self::MODEL_SEAL => [
                        'protocol' => self::PROTOCOL, 'generation_id' => (int) $locked->id,
                        'agent_id' => (int) $agent->id, 'preparation_hash' => $prepared['preparation_hash'],
                        'intent_hash' => $intent['intent_hash'], 'promotion_evidence' => false]]]);
                }
                $this->contexts->update($locked, fn ($context) => [...$context, self::OWNER => $prepared,
                    'unused_draft_native_full_dependency' => $intent['native_full_dependency'],
                    'mtf_bundle_hash' => $bundle['bundle_hash'], 'mtf_bundle_manifest' => $bundle['manifest']]);
                $this->assertOwner($locked->fresh(), $bundle['manifest']);
                return $prepared;
            });
        } finally { $lease->release(); }
    }

    public function decisionGeneration(ResearchLoopDecision $decision, string $action): LabGeneration
    {
        $control = app(AutonomousModeService::class)->status('XAUUSD', 'H1');
        $proposal = (array) data_get($decision->evidence_snapshot, 'price_discovery_proposal', []);
        if ($decision->status !== 'running' || $decision->action !== $action || $decision->command !== self::COMMAND
            || $decision->queue !== 'scheduler-constructor' || $decision->symbol !== 'XAUUSD' || $decision->timeframe !== 'H1'
            || data_get($decision->contract, 'owner') !== ResearchLoopArbiterService::class
            || data_get($decision->contract, 'selection_cardinality') !== 1
            || ($control['enabled'] ?? null) !== true || ($control['state'] ?? null) !== 'running'
            || ($proposal['control_revision'] ?? null) !== ($control['changed_at'] ?? null)
            || ($proposal['generation_id'] ?? null) !== self::GENERATION_ID
            || (int) data_get($decision->arguments, 'generation') !== self::GENERATION_ID
            || (int) data_get($control, 'latest_generation.id') !== self::GENERATION_ID) $this->deny('RUNNING_CURRENT_ARBITER_DECISION_REQUIRED');
        $generation = LabGeneration::findOrFail(self::GENERATION_ID);
        if (data_get($generation->trigger_context, self::INTENT.'.intent_hash') !== ($proposal['intent_hash'] ?? null)) $this->deny('ARBITER_INTENT_FENCE_DRIFT');
        return $generation;
    }

    /** A declared malformed owner always throws; it cannot fall through to ordinary authority. */
    public function assertOwner(LabGeneration $generation, array $manifest): array
    {
        $intent = $this->intent($generation);
        foreach (self::FOREIGN_PURPOSES as $key) if (! empty(data_get($generation->trigger_context, $key))) $this->deny('FOREIGN_OWNER_AFTER_PREPARATION:'.$key);
        $prepared = (array) data_get($generation->trigger_context, self::OWNER, []);
        $unhashed = $prepared; unset($unhashed['preparation_hash']);
        if (($prepared['protocol'] ?? null) !== self::PROTOCOL
            || ($prepared['preparation_hash'] ?? null) !== $this->contextHash($unhashed)
            || ($prepared['intent_hash'] ?? null) !== $intent['intent_hash']
            || ($prepared['original_snapshot_hash'] ?? null) !== $this->contextHash($this->snapshot($generation))
            || ($prepared['source_hash'] ?? null) !== app(LabImmutableEvidenceService::class)->codeHash()
            || ($prepared['manifest_hash'] ?? null) !== $this->contextHash($manifest)
            || ($prepared['bundle_hash'] ?? null) !== ($manifest['bundle_hash'] ?? null)
            || data_get($generation->trigger_context, 'mtf_bundle_hash') !== ($manifest['bundle_hash'] ?? null)
            || $this->contextHash(data_get($generation->trigger_context, 'unused_draft_native_full_dependency', [])) !== $intent['native_full_dependency_hash']) {
            $this->deny('ORIGINAL_PREPARATION_OR_MODEL_DRIFT');
        }
        $this->assertManifest($manifest, $intent);
        foreach ($generation->agents()->with('modelVersion')->get() as $agent) {
            $seal = (array) data_get($agent->modelVersion?->metadata, self::MODEL_SEAL, []);
            if (($seal['protocol'] ?? null) !== self::PROTOCOL || ($seal['generation_id'] ?? null) !== (int) $generation->id
                || ($seal['agent_id'] ?? null) !== (int) $agent->id || ($seal['preparation_hash'] ?? null) !== $prepared['preparation_hash']
                || ($seal['intent_hash'] ?? null) !== $intent['intent_hash'] || ($seal['promotion_evidence'] ?? null) !== false) $this->deny('ORIGINAL_MODEL_OWNER_SEAL_DRIFT');
        }
        return $prepared;
    }

    public function inspectOwner(LabGeneration $generation, array $manifest): array
    {
        if (! $this->declares($generation)) return ['allowed' => false, 'reason' => 'NOT_UNUSED_PRICE_DISCOVERY'];
        $prepared = $this->assertOwner($generation, $manifest);
        return ['allowed' => true, 'preparation_hash' => $prepared['preparation_hash'], 'promotion_evidence' => false];
    }

    public function assertAttempt(LabAgent $agent, string $phase, ?LabEvaluationRun $existing = null): void
    {
        $agent->loadMissing('generation', 'modelVersion');
        if (! $this->declares($agent->generation)) {
            if (data_get($agent->modelVersion?->metadata, self::MODEL_SEAL) !== null) $this->deny('ORPHAN_OR_FOREIGN_MODEL_SEAL');
            return;
        }
        $this->assertOwner($agent->generation, (array) data_get($agent->generation->trigger_context, 'mtf_bundle_manifest', []));
        if ($phase !== 'screening' || ($existing && ($existing->lab_agent_id !== $agent->id
            || $existing->model_version_id !== $agent->model_version_id || $existing->phase !== 'screening'))) $this->deny('SCREENING_ONLY');
        $runs = LabEvaluationRun::where('lab_agent_id', $agent->id)->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->count();
        if ($runs !== 0 || ($existing && (int) $existing->attempt !== 1)) $this->deny('ORIGINAL_AGENT_ATTEMPT_EXHAUSTED');
    }

    public function bindRequest(LabAgent $agent, array $request): array
    {
        if (! $this->declares($agent->generation)) return $request;
        $prepared = $this->assertOwner($agent->generation, (array) data_get($request, 'mtf_snapshot_manifest', []));
        $request['policy_context'][self::OWNER] = ['protocol' => self::PROTOCOL,
            'intent_hash' => $prepared['intent_hash'], 'preparation_hash' => $prepared['preparation_hash'],
            'physical_question_key' => $prepared['physical_question_key'], 'generation_id' => (int) $agent->lab_generation_id,
            'original_agent_id' => (int) $agent->id, 'original_model_id' => (int) $agent->model_version_id,
            'native_full_dependency_hash' => $prepared['native_full_dependency_hash'],
            'resource_contract' => $prepared['resource_contract'], 'independent_evidence' => false,
            'full_validation_eligible' => false, 'paper_eligible' => false, 'promotion_evidence' => false];
        return $request;
    }

    private function intent(LabGeneration $generation): array
    {
        $intent = (array) data_get($generation->trigger_context, self::INTENT, []);
        $body = $intent; unset($body['intent_hash'], $body['approval_event_id'], $body['registered_at']);
        if (($intent['protocol'] ?? null) !== self::PROTOCOL || ($intent['generation_id'] ?? null) !== self::GENERATION_ID
            || ($intent['generation_number'] ?? null) !== self::GENERATION_NUMBER
            || ($intent['intent_hash'] ?? null) !== $this->contextHash($body)) $this->deny('ORIGINAL_INTENT_INVALID');
        return $intent;
    }

    private function assertManifest(array $manifest, array $intent): void
    {
        $ready = $this->mtf->discoveryBundleReadiness($manifest);
        if (($ready['allowed'] ?? null) !== true || ($manifest['provider'] ?? null) !== 'mixed'
            || ($manifest['data_role'] ?? null) !== 'pre_2026_discovery_only'
            || data_get($manifest, 'discovery_scope.parent_dataset_key') !== $intent['dataset_key']
            || data_get($manifest, 'discovery_scope.calendar.evaluated_rows') !== 15000
            || data_get($manifest, 'discovery_scope.calendar.warmup_rows') !== 512
            || data_get($manifest, 'discovery_scope.calendar.loaded_rows') !== 15512
            || data_get($manifest, 'discovery_scope.calendar.selected_unexpected_gaps') !== 0
            || data_get($manifest, 'discovery_scope.calendar.full_source_unexpected_gaps') !== 0
            || data_get($manifest, 'quote_spread_provenance.status') !== 'unavailable'
            || data_get($manifest, 'quote_spread_provenance.quote_liquidity_inherited') !== false
            || ($manifest['full_validation_eligible'] ?? null) !== false || ($manifest['independent_evidence'] ?? null) !== false
            || ($manifest['paper_eligible'] ?? null) !== false || ($manifest['promotion_evidence'] ?? null) !== false) $this->deny('BOUNDED_MIXED_MANIFEST_INVALID');
    }

    private function assertUnused(LabGeneration $generation): void
    {
        $generation->loadMissing('laboratory');
        $context = (array) $generation->trigger_context;
        if ((int) $generation->id !== self::GENERATION_ID || (int) $generation->generation !== self::GENERATION_NUMBER
            || $generation->status !== 'draft' || $generation->trigger_type !== 'historical_research'
            || $generation->completed_at !== null || $generation->laboratory?->symbol !== 'XAUUSD'
            || $generation->laboratory?->timeframe !== 'H1' || (int) $generation->population_size !== 20
            || data_get($context, 'constructor_audit.protocol') !== 'agent_constructor_invariant_v1'
            || data_get($context, 'constructor_audit.planned_slots') !== 20
            || data_get($context, 'constructor_audit.created_agents') !== 20
            || ! empty(data_get($context, 'constructor_audit.skipped_zero_diff_slots'))
            || data_get($context, 'immutable_generation_contract.protocol') !== ImmutableGenerationContractService::PROTOCOL
            || data_get($context, 'immutable_generation_contract.state') !== 'sealed'
            || count((array) ($context['generation_plan'] ?? [])) !== 20) $this->deny('EXACT_COMPLETE_UNUSED_G263_REQUIRED');
        foreach ([...self::CONTEXT_SEALS, ...self::FOREIGN_PURPOSES] as $key) {
            if (! empty($context[$key])) $this->deny('SEALED_OR_FOREIGN_GENERATION_OWNER:'.$key);
        }
        $agents = $generation->agents()->with('modelVersion')->orderBy('id')->get();
        if ($agents->count() !== 20 || $agents->pluck('model_version_id')->unique()->count() !== 20
            || $agents->contains(fn ($agent) => $agent->lifecycle_status !== 'draft' || ! $agent->modelVersion)) $this->deny('ORIGINAL_TWENTY_UNUSED_MODELS_REQUIRED');
        $modelIds = $agents->pluck('model_version_id')->all();
        if (LabEvaluationRun::where('lab_generation_id', $generation->id)->orWhereIn('model_version_id', $modelIds)->exists()
            || LabAgent::whereIn('model_version_id', $modelIds)->where('lab_generation_id', '!=', $generation->id)->exists()) $this->deny('MODEL_ALREADY_OBSERVED_OR_REUSED');
        foreach ($agents as $agent) {
            foreach (self::MODEL_PURPOSES as $key) if (! empty(data_get($agent->modelVersion->metadata, $key))) $this->deny('FOREIGN_MODEL_OWNER:'.$key);
            if (data_get($agent->modelVersion->metadata, 'last_result') !== null || data_get($agent->modelVersion->metadata, 'last_screen_result') !== null
                || $this->requiresVolume($agent->modelVersion)) $this->deny('OBSERVED_OR_VOLUME_MODEL_FORBIDDEN');
            $episodes = AgentLearningEpisode::where('lab_agent_id', $agent->id)->with('settlement')->get();
            if ($episodes->count() !== 1 || $episodes[0]->model_version_id !== $agent->model_version_id
                || $episodes[0]->stage !== 'mutation_selection' || $episodes[0]->settlement !== null
                || ! in_array($episodes[0]->status, ['open', 'decision', 'running'], true)) $this->deny('ORIGINAL_UNUSED_EPISODE_REQUIRED');
        }
        if (! app(ImmutableGenerationContractService::class)->validate($generation)['valid']) $this->deny('ORIGINAL_CONSTRUCTOR_MODEL_CONTRACT_INVALID');
        $queue = $this->queues->generationQueueBacklog($agents->pluck('id')->all(),
            array_values(array_unique([...$this->queues->labQueues(), (string) config('services.lab_queue.learning_queue', 'lab-learning'),
                (string) config('services.lab_queue.default_queue', 'lab-xauusd')])));
        if (($queue['available'] ?? null) !== true || ($queue['total'] ?? null) !== 0 || ($queue['rows'] ?? null) !== []
            || ! in_array($queue['backend'] ?? '', ['redis', 'database'], true)) $this->deny('GENERATION_QUEUE_NOT_PROVEN_EMPTY');
        if (app(LabPopulationService::class)->constructorIsActive('XAUUSD', 'H1')) $this->deny('CONSTRUCTOR_LEASE_ACTIVE');
    }

    /** Original genomes and allocation/experiment memberships; never mutable result projections. */
    public function snapshot(LabGeneration $generation): array
    {
        $context = (array) $generation->trigger_context;
        $gen = array_intersect_key($context, array_flip(['generation_plan', 'immutable_generation_contract', 'research_allocation_budget',
            'control_pairing_contract', 'structural_research_contract', 'population_group_contract', 'constructor_audit',
            'historical_research_admission', 'arbiter_provenance', 'lineage_continuation_contract']));
        $agents = $generation->agents()->with('modelVersion')->orderBy('id')->get()->map(function ($agent) {
            $model = $agent->modelVersion;
            if (! $model) $this->deny('MODEL_MISSING');
            $memberships = array_filter((array) $model->metadata, fn ($value, $key) => preg_match('/causal|cooperative|control|pair|factorial|phase|academy|council|execution_contract/', $key), ARRAY_FILTER_USE_BOTH);
            $episodes = AgentLearningEpisode::where('lab_agent_id', $agent->id)->orderBy('id')->get()->map(fn ($episode) => [
                'id' => (int) $episode->id, 'model_id' => (int) $episode->model_version_id, 'stage' => $episode->stage,
                'decision' => $episode->decision, 'data_hash' => $episode->data_hash, 'execution_hash' => $episode->execution_hash,
                'decision_context_hash' => $this->hashes->parameterHash((array) $episode->decision_context)])->all();
            return ['agent_id' => (int) $agent->id, 'model_id' => (int) $model->id, 'origin' => $agent->origin,
                'family' => $agent->strategy_family, 'strategy' => $model->strategy, 'version' => $model->version,
                'parameter_hash' => $this->hashes->parameterHash((array) $model->parameters),
                'parameter_diff_hash' => $this->hashes->parameterHash((array) $agent->parameter_diff),
                'memberships_hash' => $this->hashes->parameterHash($memberships), 'episodes' => $episodes];
        })->all();
        return ['generation_id' => (int) $generation->id, 'generation_number' => (int) $generation->generation,
            'laboratory_id' => (int) $generation->ai_laboratory_id, 'population_size' => (int) $generation->population_size,
            'constructor_allocation_hash' => $this->hashes->parameterHash($gen), 'agents' => $agents];
    }

    /** Numeric JSON projection equality only; original file/request/response hashes stay byte-exact. */
    private function contextHash(array $value): string
    {
        return app(ExecutionContractService::class)->hashParameters($value);
    }

    private function deny(string $reason): never { throw new LogicException('UNUSED_PRICE_DISCOVERY_'.$reason); }

    private function requiresVolume(ModelVersion $model): bool
    {
        $metadata = (array) $model->metadata;
        return data_get($metadata, 'volume_research_contract.protocol') === 'volume_council_v1'
            || (bool) data_get($metadata, 'volume_research_contract.enabled', false)
            || (bool) data_get($metadata, 'risk_bounded_evolution.volume_shadow', false)
            || (bool) data_get($metadata, 'portfolio_council_lane.volume_shadow', false)
            || data_get($metadata, 'portfolio_council_lane.role') === 'volume_m15_specialist'
            || data_get($metadata, 'portfolio_council_lane.specialist_role') === 'volume_m15_specialist'
            || data_get($model->parameters, 'volume_lane', 'none') !== 'none';
    }
}
