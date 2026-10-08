<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\AgentLearningEpisode;
use App\Models\AgentLearningMutationIntent;
use App\Models\AgentLearningSettlement;
use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use App\Models\LabGeneration;
use App\Models\LabLifecycleEvent;
use App\Models\ModelVersion;
use App\Models\ResearchExperimentReceipt;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;

/** Prospective existing-data decision sensitivity; this owner grants no trading or skill authority. */
class NativeSpreadContextStudyService
{
    public const PROTOCOL = 'native_spread_context_study_v1';
    public const RECEIPT_PROTOCOL = 'native_spread_context_study_receipt_v1';
    public const FIELD = 'native_spread_context_study_contract';
    public const MARKER = 'native_spread_context_study';
    public const ARTIFACT = 'native_spread_context_study_preregistration';
    public const CRITERION = 'entry_wait_decision_change';
    public const INTERVENTION = 'decision_context.spread_liquidity_state_only';
    public const ATR_BINDINGS = ['closed_strategy_atr_v1' => 'atr', 'closed_structure_atr_v1' => 'structure_atr',
        'closed_m5_management_atr_v1' => '_management_atr'];
    private const AXES = ['regime', 'volatility', 'session', 'venue_phase', 'direction'];

    public function __construct(private LabImmutableEvidenceService $evidence,
        private SpecialistCouncilLifecycleService $councils, private ResearchPaperEpochContractService $epochs) {}

    /** Only two already-existing unused native carriers may be prospectively assigned. */
    public function preregister(LabAgent $masked, LabAgent $unmasked, array $spec): array
    {
        return DB::transaction(function () use ($masked, $unmasked, $spec): array {
            if (array_diff(array_keys($spec), ['request', 'specialist_id', 'exact_context', 'liquidity_atr_binding', 'quote_provenance_hash', 'evaluation_policy_hash', 'minimum_paired_opportunities']) !== []) $this->refuse('PROSPECTIVE_SPEC_UNKNOWN_FIELDS');
            $agents = LabAgent::whereIn('id', [$masked->id, $unmasked->id])->orderBy('id')->lockForUpdate()->get();
            if ($agents->count() !== 2 || $masked->id === $unmasked->id) $this->refuse('TWO_DISTINCT_CARRIERS_REQUIRED');
            $masked = $agents->firstWhere('id', $masked->id); $unmasked = $agents->firstWhere('id', $unmasked->id);
            if ($masked->lab_generation_id !== $unmasked->lab_generation_id) $this->refuse('SAME_GENERATION_REQUIRED');
            $generation = LabGeneration::whereKey($masked->lab_generation_id)->lockForUpdate()->firstOrFail();
            foreach ([$masked, $unmasked] as $agent) $agent->setRelation('modelVersion', ModelVersion::whereKey($agent->model_version_id)->lockForUpdate()->firstOrFail());
            $base = (array) ($spec['request'] ?? []);
            $identity = $this->identity($masked->modelVersion, $base, $spec);
            $otherIdentity = $this->identity($unmasked->modelVersion, $base, $spec);
            if (! $this->same($identity, $otherIdentity) || ! $this->sameProgram($masked->modelVersion, $unmasked->modelVersion)) $this->refuse('IDENTICAL_NATIVE_PROGRAM_REQUIRED');
            $members = ['masked' => ['agent_id' => (int) $masked->id, 'model_id' => (int) $masked->model_version_id],
                'unmasked' => ['agent_id' => (int) $unmasked->id, 'model_id' => (int) $unmasked->model_version_id]];
            $studyId = $this->hash(['protocol' => self::PROTOCOL, 'generation_id' => $generation->id, 'members' => $members, 'identity' => $identity]);
            $existing = $this->artifact($studyId);
            if ($existing) {
                $sealed = $this->readSeal($existing);
                if (! $this->same($sealed['identity'], $identity) || ! $this->same($sealed['members'], $members)) $this->refuse('PREREGISTRATION_DRIFT');
                foreach ([$masked, $unmasked] as $agent) $this->marker($agent->modelVersion, $sealed, $existing);
                return $this->registration($existing, $sealed);
            }
            $constructorProof = null;
            if ($masked->origin === 'native_council_root' || $unmasked->origin === 'native_council_root') {
                $preparation = app(SpecialistCouncilPreparationService::class);
                if (! method_exists($preparation, 'assertOriginalSpreadStudyCarriers')) $this->refuse('CANONICAL_STUDY_CONSTRUCTOR_DEPENDENCY');
                $constructorProof = $preparation->assertOriginalSpreadStudyCarriers($generation, $masked, $unmasked);
                if (($constructorProof['research_purpose'] ?? null) !== 'spread_context_study'
                    || data_get($constructorProof, 'carrier_role_ids.study_masked_carrier.agent_id') !== (int) $masked->id
                    || data_get($constructorProof, 'carrier_role_ids.study_unmasked_carrier.agent_id') !== (int) $unmasked->id
                    || count($constructorProof['source_ids'] ?? []) !== 4 || count($constructorProof['constructor_episode_ids'] ?? []) !== 6
                    || data_get($constructorProof, 'study_context_declaration.specialist_id') !== ($spec['specialist_id'] ?? null)
                    || data_get($constructorProof, 'study_context_declaration.liquidity_atr_binding') !== ($spec['liquidity_atr_binding'] ?? null)
                    || ! $this->same(data_get($constructorProof, 'study_context_declaration.exact_context'), $spec['exact_context'] ?? [])) $this->refuse('CANONICAL_CONSTRUCTOR_PROOF_INVALID');
                $this->assertConstructorEpisodes($constructorProof, $generation);
            }
            $physical = $this->physicalQuestion($masked->modelVersion, $base, $identity);
            $physicalHash = $this->hash($physical);
            if ((string) $generation->status !== 'draft') $this->refuse('UNUSED_DRAFT_GENERATION_REQUIRED');
            foreach (array_keys((array) $generation->trigger_context) as $key) {
                if ($constructorProof !== null && preg_match('/snapshot|mtf_bundle/', (string) $key)) continue;
                if (preg_match('/snapshot|research_release|mtf_bundle|dispatch|queue|evaluation|native_spread_context_study/', (string) $key)) $this->refuse('UNSEALED_GENERATION_REQUIRED');
            }
            if (LabEvaluationRun::where('lab_generation_id', $generation->id)->exists()) $this->refuse('UNOBSERVED_GENERATION_REQUIRED');
            if ($constructorProof === null && AgentLearningEpisode::whereIn('lab_agent_id', [$masked->id, $unmasked->id])->exists()) $this->refuse('EXISTING_CONSTRUCTOR_EPISODE_FORBIDDEN');
            $backlog = app(LabQueueJobInspector::class)->generationQueueBacklog($generation->agents()->pluck('id')->all());
            if (($backlog['available'] ?? true) !== true || ($backlog['total'] ?? null) !== 0) $this->refuse('VERIFIED_EMPTY_GENERATION_QUEUE_REQUIRED');
            foreach ([$masked, $unmasked] as $agent) {
                if ((string) $agent->lifecycle_status !== 'draft'
                    || LabEvaluationRun::where('model_version_id', $agent->model_version_id)->exists()) $this->refuse('UNUSED_DRAFT_CARRIER_REQUIRED');
                if ((string) $agent->origin === 'native_council_root' && $constructorProof === null) $this->refuse('EXISTING_CONSTRUCTION_OWNER_FORBIDDEN');
                foreach ((array) $agent->modelVersion->metadata as $owner => $value) {
                    if ($constructorProof !== null && $owner === 'native_specialist_council_seed') continue;
                    if ($constructorProof !== null && $owner === 'professional_learning_lane'
                        && $this->same($value, ['protocol' => 'professional_learning_lane_v1', 'curiosity_lane' => false,
                            'selection_lane' => 'standard_research', 'promotion_evidence' => false])) continue;
                    if ($constructorProof !== null && $owner === 'causal_experiment_lane' && $this->same($value,
                        ['status' => 'no_change_control', 'rule' => 'One changed parameter only; requires parent and same-generation alternative before causal credit.',
                            'control_only' => true])) continue;
                    if ($constructorProof !== null && $owner === 'causal_learning_intent'
                        && $this->originalControlIntent($agent, (array) $value, $constructorProof)) continue;
                    if ($value !== null && $value !== false && $value !== [] && preg_match('/native_specialist_council_seed|specialist_council_evaluation|control_pair_contract|cooperative|causal|academy|prospective|activation|phase_scope|learning_lane|learning_confirmation|native_spread_context_study/', (string) $owner)) $this->refuse('EXISTING_EVALUATION_OWNER_FORBIDDEN_'.strtoupper((string) $owner));
                }
                if (count((array) $agent->parameter_diff) !== 0) $this->refuse('UNCHANGED_CARRIER_PROGRAM_REQUIRED');
                $version = $this->councils->researchVersionForModel($agent->modelVersion);
                if (! $version || $version->state !== 'draft') $this->refuse('DRAFT_NATIVE_SOURCE_REQUIRED');
            }
            if (! Schema::hasTable('research_knowledge_entries')) $this->refuse('PHYSICAL_QUESTION_JOURNAL_REQUIRED');
            $journalKey = $this->hash([self::PROTOCOL, 'physical_question', $physicalHash]);
            DB::table('research_knowledge_entries')->insertOrIgnore(['knowledge_key' => $journalKey, 'knowledge_type' => 'PROCEDURAL',
                'subject_type' => self::class, 'subject_key' => $physicalHash, 'symbol' => $masked->symbol, 'timeframe' => $masked->timeframe,
                'authority' => 'research_only', 'freshness' => 'active', 'status' => 'sealed', 'scope' => $this->json($identity['exact_context']),
                'claim' => $this->json(['protocol' => self::PROTOCOL, 'criterion' => self::CRITERION, 'promotion_evidence' => false]),
                'evidence' => $this->json(['study_id' => $studyId, 'physical_question_hash' => $physicalHash]),
                'dependencies' => $this->json(['one_original_bounded_pair' => true]), 'recorded_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $journal = DB::table('research_knowledge_entries')->where('knowledge_key', $journalKey)->lockForUpdate()->first();
            if (! $journal || $journal->subject_type !== self::class || $journal->subject_key !== $physicalHash
                || data_get(json_decode($journal->evidence, true), 'study_id') !== $studyId) $this->refuse('PHYSICAL_QUESTION_ALREADY_RESERVED');
            $sealed = ['protocol' => self::PROTOCOL, 'study_id' => $studyId, 'generation_id' => (int) $generation->id,
                'scope' => ['symbol' => (string) $base['symbol'], 'laboratory_timeframe' => (string) $masked->timeframe,
                    'execution_timeframe' => (string) $base['timeframe']],
                'members' => $members, 'identity' => $identity, 'physical_question_hash' => $physicalHash,
                'prospective_probe_window' => (array) data_get($base, 'policy_context.prospective_probe_window', []),
                'constructor_proof' => $constructorProof, 'model_hashes' => [], 'contracts' => []];
            foreach (['masked' => $masked, 'unmasked' => $unmasked] as $arm => $agent) {
                $sealed['model_hashes'][$arm] = app(SpecialistCouncilContractService::class)->modelHash($agent->modelVersion);
                $body = ['protocol' => self::PROTOCOL, 'study_id' => $studyId, 'arm' => $arm, 'identity' => $identity,
                    'identity_hash' => $this->hash($identity), 'criterion' => self::CRITERION, 'intervention' => self::INTERVENTION,
                    'authority' => 'research_only', 'economic_authority' => false, 'skill_authority' => false,
                    'independent_evidence' => false, 'promotion_evidence' => false];
                $hash = $this->hash($body);
                $sealed['contracts'][$arm] = [...$body, 'contract_hash' => $hash, 'contract_json' => $this->json($body),
                    'server_seal' => ['protocol' => 'native_spread_context_study_server_seal_v1',
                        'hmac_sha256' => hash_hmac('sha256', self::PROTOCOL."\n".$hash, $this->key())]];
            }
            $artifact = $this->evidence->recordArtifact(null, self::ARTIFACT, $sealed,
                ['protocol' => self::PROTOCOL, 'masked_model_id' => $masked->model_version_id,
                    'unmasked_model_id' => $unmasked->model_version_id, 'promotion_evidence' => false], $masked, $studyId);
            foreach (['masked' => $masked, 'unmasked' => $unmasked] as $arm => $agent) {
                $agent->modelVersion->update(['metadata' => [...(array) $agent->modelVersion->metadata,
                    self::MARKER => ['protocol' => self::PROTOCOL, 'study_id' => $studyId, 'arm' => $arm,
                        'artifact_hash' => $artifact->sha256, 'contract_hash' => $sealed['contracts'][$arm]['contract_hash']]]]);
            }
            $generation->update(['trigger_context' => [...(array) $generation->trigger_context,
                self::MARKER => ['protocol' => self::PROTOCOL, 'study_id' => $studyId, 'artifact_hash' => $artifact->sha256]]]);
            return $this->registration($artifact, $sealed);
        });
    }

    public function declares(ModelVersion $model): bool
    {
        return data_get($model->metadata, self::MARKER) !== null || LabEvidenceArtifact::where('artifact_type', self::ARTIFACT)
            ->where(fn ($q) => $q->where('metadata->masked_model_id', $model->id)->orWhere('metadata->unmasked_model_id', $model->id))->exists();
    }

    /** Runs through the existing singleton/batch request compiler before release and immutable request sealing. */
    public function bindRequest(array $request, array $models): array
    {
        foreach ($models as $model) {
            if (! $model instanceof ModelVersion || ! $this->declares($model)) continue;
            [$seal, $artifact, $arm] = $this->modelSeal($model);
            $contract = $seal['contracts'][$arm];
            $request = $this->bindOriginalProbe($request, $seal);
            $identity = $this->identity($model, $request, $seal['identity']);
            if (! $this->same($identity, $seal['identity'])) $this->refuse('DISPATCH_IDENTITY_DRIFT');
            if (isset($request['strategies'])) {
                $indices = [];
                foreach ($request['strategies'] as $index => $payload) {
                    if ((int) ($payload['lab_agent_id'] ?? 0) === $seal['members'][$arm]['agent_id']) $indices[] = $index;
                }
                if (count($indices) !== 1) $this->refuse('EXACT_DISPATCH_ARM_REQUIRED');
                $index = $indices[0];
                $this->assertNativeRequest($request['strategies'][$index], $identity);
                if (isset($request['strategies'][$index][self::FIELD]) && ! $this->same($request['strategies'][$index][self::FIELD], $contract)) $this->refuse('DECLARED_CONTRACT_DRIFT');
                $request['strategies'][$index][self::FIELD] = $contract;
            } else {
                $this->assertNativeRequest($request, $identity);
                if (isset($request[self::FIELD]) && ! $this->same($request[self::FIELD], $contract)) $this->refuse('DECLARED_CONTRACT_DRIFT');
                $request[self::FIELD] = $contract;
            }
        }
        return $request;
    }

    /** Generic screening may supply the same physical window, but the original owner supplies its policy identity. */
    private function bindOriginalProbe(array $request, array $seal): array
    {
        $original = (array) ($seal['prospective_probe_window'] ?? []);
        $incoming = (array) data_get($request, 'policy_context.prospective_probe_window', []);
        $owner = app(ProspectiveRepairProbeWindowService::class);
        if ($original === [] || $incoming === [] || ! $owner->attests($original, [...$original, 'complete' => true])
            || ! $owner->attests($incoming, [...$incoming, 'complete' => true])
            || ! $this->same(array_diff_key($original, ['experiment_key' => true, 'contract_hash' => true]),
                array_diff_key($incoming, ['experiment_key' => true, 'contract_hash' => true]))) $this->refuse('ORIGINAL_PROBE_PHYSICAL_SCOPE_DRIFT');
        $request['policy_context']['prospective_probe_window'] = $original;
        return $request;
    }

    /** Attest from the original transported artifact, before ordinary gates or projections. */
    public function attestResult(ModelVersion $model, LabEvaluationRun $run, array $result): ?array
    {
        if (! $this->declares($model)) return null;
        [$seal, $artifact, $arm] = $this->modelSeal($model);
        if ((int) $run->lab_agent_id !== $seal['members'][$arm]['agent_id'] || (int) $run->model_version_id !== $model->id
            || ! $run->started_at || ! $artifact->recorded_at || $run->started_at->lessThan($artifact->recorded_at)) $this->refuse('ORIGINAL_ARM_CHRONOLOGY_INVALID');
        $request = $this->originalPayload($run, 'evaluation_request');
        $transported = isset($request['strategies']) ? collect($request['strategies'])->firstWhere('lab_agent_id', $run->lab_agent_id) : $request;
        if (! $this->same($transported[self::FIELD] ?? [], $seal['contracts'][$arm])) $this->refuse('ORIGINAL_STUDY_CONTRACT_MISSING');
        $this->bindRequest($request, [$model]);
        $native = $this->councils->attestReplayResult($model, $request, $result);
        if (! $native) $this->refuse('ORIGINAL_NATIVE_RECEIPT_REQUIRED');
        $receipt = (array) ($result['native_spread_context_study_receipt'] ?? []);
        if (! $this->same($receipt, (array) data_get($result, 'data_quality.native_spread_context_study_receipt', []))) $this->refuse('RECEIPT_COPIES_MISMATCH');
        $body = $this->sealedBody($receipt, 'receipt');
        foreach (['protocol' => self::RECEIPT_PROTOCOL, 'study_id' => $seal['study_id'], 'arm' => $arm,
            'contract_hash' => $seal['contracts'][$arm]['contract_hash'], 'identity_hash' => $seal['contracts'][$arm]['identity_hash'],
            'criterion' => self::CRITERION, 'intervention' => self::INTERVENTION, 'native_contract_hash' => $seal['identity']['native_contract_hash'],
            'quote_provenance_hash' => $seal['identity']['quote_provenance_hash'], 'pricing_model_unchanged' => true, 'risk_policy_unchanged' => true,
            'promotion_evidence' => false, 'economic_authority' => false, 'skill_authority' => false, 'independent_evidence' => false] as $key => $expected) {
            if (($body[$key] ?? null) !== $expected) $this->refuse('RECEIPT_IDENTITY_OR_AUTHORITY_INVALID');
        }
        if (! in_array($body['status'] ?? null, ['computed', 'data_missing', 'underpowered'], true)
            || data_get($body, 'source_attestation.status') !== 'verified'
            || ! $this->same($body['source_attestation'], $native['source_attestation'])
            || ! $this->same($body['replay_executed_clock'] ?? [], $native['replay_executed_clock'] ?? [])) $this->refuse('RECEIPT_SOURCE_OR_CLOCK_INVALID');
        $this->events($body, $seal['identity']);
        return $receipt;
    }

    /** Existing terminal boundary may reconcile only this exact original, finished pair. */
    public function reconcileGeneration(LabGeneration $generation): ?array
    {
        $marker = data_get($generation->fresh()?->trigger_context, self::MARKER);
        if ($marker === null) return null;
        $artifact = $this->artifact((string) ($marker['study_id'] ?? ''));
        if (! $artifact || ($marker['protocol'] ?? null) !== self::PROTOCOL || ($marker['artifact_hash'] ?? null) !== $artifact->sha256) $this->refuse('ORIGINAL_GENERATION_MARKER_INVALID');
        $seal = $this->evidence->readArtifactPayload($artifact);
        if (! is_array($seal) || ($seal['generation_id'] ?? null) !== (int) $generation->id || $seal['study_id'] !== $artifact->run_id) $this->refuse('ORIGINAL_GENERATION_SOURCE_INVALID');
        $original = null;
        foreach ($seal['members'] as $member) {
            $agent = LabAgent::find($member['agent_id']);
            $runs = LabEvaluationRun::where('lab_agent_id', $member['agent_id'])->orderBy('id')->get();
            if (! $agent || (int) $agent->lab_generation_id !== (int) $generation->id || (int) $agent->model_version_id !== $member['model_id']) $this->refuse('ORIGINAL_GENERATION_ARM_INVALID');
            if ($runs->isEmpty() || $runs->contains(fn ($run) => ! $this->evidence->isTerminalRun($run))
                || in_array($agent->lifecycle_status, ['draft', 'queued', 'training', 'screening', 'full_queued', 'full_validation'], true)) {
                return ['protocol' => self::PROTOCOL, 'status' => 'awaiting_original_pair', 'promotion_evidence' => false];
            }
            $original ??= $runs->first();
        }
        return $this->settleOriginalPair($original);
    }

    /** Terminal pair settlement reads originals only and owns one locked conversion publication. */
    public function settleOriginalPair(LabEvaluationRun $run): ?array
    {
        $archived = LabEvidenceArtifact::where('artifact_type', self::ARTIFACT)->where(fn ($q) =>
            $q->where('metadata->masked_model_id', $run->model_version_id)->orWhere('metadata->unmasked_model_id', $run->model_version_id))->get();
        foreach ($archived as $originalSeal) {
            $prior = ResearchExperimentReceipt::where('source_type', self::class)->where('source_id', $originalSeal->id)->first();
            if ($prior) return $this->recordedPublication($prior, $originalSeal, $run);
        }
        $model = ModelVersion::find($run->model_version_id);
        if (! $model || ! $this->declares($model)) return null;
        [$seal, $artifact] = $this->modelSeal($model);
        return DB::transaction(function () use ($seal, $artifact): array {
            LabEvidenceArtifact::whereKey($artifact->id)->lockForUpdate()->firstOrFail();
            $prior = ResearchExperimentReceipt::where('source_type', self::class)->where('source_id', $artifact->id)->first();
            if ($prior) return ['protocol' => self::PROTOCOL, 'status' => 'recorded', 'receipt_id' => $prior->id,
                'receipt_key' => $prior->receipt_key, 'classification' => $prior->classification, 'promotion_evidence' => false];
            $runs = []; $receipts = []; $error = null;
            foreach ($seal['members'] as $arm => $member) {
                $owned = LabEvaluationRun::where('lab_agent_id', $member['agent_id'])->orderBy('id')->get();
                if ($owned->isEmpty() || $owned->contains(fn ($item) => ! $this->evidence->isTerminalRun($item))) {
                    return ['protocol' => self::PROTOCOL, 'status' => 'awaiting_original_pair', 'promotion_evidence' => false];
                }
                $original = $owned->first();
                try {
                    if ((int) $original->lab_generation_id !== $seal['generation_id'] || (int) $original->model_version_id !== $member['model_id']
                        || ! $original->finished_at || ! $this->sha($original->response_hash)) $this->refuse('ORIGINAL_TERMINAL_IDENTITY_REQUIRED');
                    $terminalPayload = $this->originalPayload($original, 'evaluation_response');
                    if ($original->request_hash !== null) $this->originalPayload($original, 'evaluation_request');
                    if (isset($terminalPayload['terminal_replay_envelope'])
                        && data_get($terminalPayload, 'terminal_replay_envelope.status') !== $original->status) $this->refuse('ORIGINAL_TERMINAL_ENVELOPE_INVALID');
                } catch (\Throwable) {
                    return ['protocol' => self::PROTOCOL, 'status' => 'awaiting_original_terminal_evidence', 'promotion_evidence' => false];
                }
                $runs[$arm] = ['run_id' => $original->run_id, 'request_hash' => $original->request_hash, 'response_hash' => $original->response_hash,
                    'all_run_ids' => $owned->pluck('run_id')->all()];
                try {
                    if ($owned->count() !== 1 || ! $this->evidence->learningEligibility($original)['complete']
                        || $this->evidence->verifiedModelRuntimeIdentity($original) === null) $this->refuse('ORIGINAL_COMPLETE_SOURCE_REQUIRED');
                    $receipts[$arm] = $this->attestResult(ModelVersion::findOrFail($member['model_id']), $original, $terminalPayload);
                } catch (\Throwable $exception) { $error = str_starts_with($exception->getMessage(), 'NATIVE_SPREAD_STUDY_') ? $exception->getMessage() : 'NATIVE_SPREAD_STUDY_ORIGINAL_EVIDENCE_INVALID'; }
            }
            try { $outcome = $error === null ? $this->compare($receipts, $seal['identity']) : ['status' => 'technical', 'reason' => $error]; }
            catch (\Throwable $exception) { $error = 'NATIVE_SPREAD_STUDY_PAIR_EVIDENCE_INVALID'; $outcome = ['status' => 'technical', 'reason' => $error]; }
            $classification = $error !== null ? 'TECHNICAL_QUARANTINE' : ($outcome['status'] === 'measured_existing_data_sensitivity' ? 'INCONCLUSIVE' : 'UNDERPOWERED');
            $identity = $seal['identity'];
            $publication = app(ResearchExperimentConversionKernelService::class)->record([
                'contract_version' => ResearchExperimentConversionKernelService::CONTRACT_VERSION,
                'source' => ['type' => self::class, 'id' => $artifact->id],
                'scope' => [...$seal['scope'], 'exact_context' => $identity['exact_context']],
                'claim' => ['target_stage' => 'decision_context', 'hypothesis' => self::CRITERION],
                'identity' => ['baseline_epoch_hash' => $identity['native_contract_hash'], 'data_and_mtf_hash' => $identity['dataset_hash'],
                    'runtime_and_contract_hash' => $identity['execution_hash'], 'intervention_hash' => $this->hash([self::INTERVENTION]),
                    'window_plan_hash' => $identity['evaluation_policy_hash'], 'evaluator_version' => self::PROTOCOL],
                'arms' => array_map(fn ($arm, $member) => ['role' => $arm, 'agent_id' => $member['agent_id']], array_keys($seal['members']), array_values($seal['members'])),
            ], ['preregistration_artifact_hash' => $artifact->sha256, 'study_id' => $seal['study_id'], 'original_runs' => $runs,
                'outcome' => $outcome, 'economic_authority' => false, 'skill_authority' => false, 'independent_evidence' => false, 'promotion_evidence' => false],
                $classification, [], ['code' => strtoupper($outcome['status']), 'no_credit_or_economic_claim' => true]);
            if (($publication['status'] ?? null) === 'recorded' && $seal['constructor_proof'] !== null) {
                $publication['neutral_constructor_settlement_ids'] = $this->settleConstructorEpisodes($seal, $artifact, $publication);
                $this->completeSourceReferences($seal, $publication);
                $this->completeTechnicalCarriers($seal, $publication);
            }
            return $publication;
        });
    }

    /** Published diagnostic scope survives current qualification, model retirement and key rotation. */
    private function recordedPublication(ResearchExperimentReceipt $receipt, LabEvidenceArtifact $artifact, LabEvaluationRun $run): array
    {
        $sealed = $this->evidence->readArtifactPayload($artifact); $contract = (array) data_get($receipt->payload, 'contract', []);
        $facts = (array) data_get($receipt->payload, 'evidence', []);
        $expectedKey = hash('sha256', implode('|', [ResearchExperimentConversionKernelService::PROTOCOL, self::class,
            $artifact->id, $this->hash($contract), $this->hash($facts)]));
        $expectedClassification = match (data_get($facts, 'outcome.status')) {
            'technical' => 'TECHNICAL_QUARANTINE', 'measured_existing_data_sensitivity' => 'INCONCLUSIVE',
            'underpowered', 'data_missing' => 'UNDERPOWERED', default => null,
        };
        $arms = is_array($sealed) ? array_map(fn ($arm, $member) => ['role' => $arm, 'agent_id' => $member['agent_id']],
            array_keys($sealed['members']), array_values($sealed['members'])) : [];
        if (! is_array($sealed) || ($sealed['protocol'] ?? null) !== self::PROTOCOL
            || $receipt->contract_hash !== $this->hash($contract) || $receipt->evidence_hash !== $this->hash($facts)
            || $expectedClassification === null || $receipt->classification !== $expectedClassification
            || data_get($contract, 'source.type') !== self::class || (int) data_get($contract, 'source.id') !== (int) $artifact->id
            || ! $this->same($contract['arms'] ?? [], $arms)
            || $receipt->receipt_key !== $expectedKey || ($facts['study_id'] ?? null) !== ($sealed['study_id'] ?? null)
            || ($facts['preregistration_artifact_hash'] ?? null) !== $artifact->sha256
            || ! collect((array) ($facts['original_runs'] ?? []))->contains(fn ($row) => ($row['run_id'] ?? null) === $run->run_id
                && ($row['request_hash'] ?? null) === $run->request_hash && ($row['response_hash'] ?? null) === $run->response_hash)) $this->refuse('HISTORICAL_PUBLICATION_SOURCE_INVALID');
        foreach (['economic_authority', 'skill_authority', 'independent_evidence', 'promotion_evidence'] as $flag) if (($facts[$flag] ?? null) !== false) $this->refuse('HISTORICAL_PUBLICATION_AUTHORITY_INVALID');
        return ['protocol' => self::PROTOCOL, 'status' => 'recorded', 'receipt_id' => $receipt->id,
            'receipt_key' => $receipt->receipt_key, 'classification' => $receipt->classification, 'promotion_evidence' => false];
    }

    private function assertConstructorEpisodes(array $proof, LabGeneration $generation): void
    {
        $seen = [];
        $roles = [...array_values((array) $proof['source_ids']), ...array_values((array) $proof['carrier_role_ids'])];
        foreach ($proof['constructor_episode_ids'] as $witness) {
            $episode = AgentLearningEpisode::find($witness['episode_id'] ?? 0);
            $agent = LabAgent::find($witness['agent_id'] ?? 0);
            if (! $episode || ! $agent || isset($seen[$episode->id]) || (int) $agent->lab_generation_id !== (int) $generation->id
                || (int) $episode->lab_agent_id !== (int) $agent->id || (int) $episode->model_version_id !== (int) ($witness['model_id'] ?? 0)
                || (int) $agent->model_version_id !== (int) $episode->model_version_id || $episode->stage !== 'mutation_selection'
                || ($witness['stage'] ?? null) !== 'mutation_selection'
                || ! collect($roles)->contains(fn ($role) => (int) ($role['agent_id'] ?? 0) === (int) $agent->id
                    && (int) ($role['model_version_id'] ?? 0) === (int) $agent->model_version_id)) $this->refuse('EXACT_CONSTRUCTOR_EPISODE_PROOF_REQUIRED');
            $seen[$episode->id] = true;
        }
        if (count($seen) !== 6) $this->refuse('EXACT_SIX_CONSTRUCTOR_EPISODES_REQUIRED');
    }

    private function originalControlIntent(LabAgent $agent, array $stored, array $proof): bool
    {
        $intent = AgentLearningMutationIntent::find($stored['intent_id'] ?? 0);
        $witness = collect($proof['constructor_episode_ids'])->firstWhere('agent_id', (int) $agent->id);
        if (! $intent || ! $witness || $intent->status !== 'bound' || $intent->influence_type !== 'independent_exploration'
            || $intent->selected_gene !== null || (int) $intent->lab_generation_id !== (int) $agent->lab_generation_id
            || (int) $intent->lab_agent_id !== (int) $agent->id || (int) $intent->model_version_id !== (int) $agent->model_version_id
            || (int) data_get($intent->metadata, 'episode_id') !== (int) $witness['episode_id']
            || $intent->baseline_hash !== $intent->parameter_hash
            || $intent->mutation_hash !== app(CausalLearningMutationIntentService::class)->mutationHash([])
            || ! $this->same(app(CausalLearningMutationIntentService::class)->contract($intent), $stored)) return false;
        foreach (['retrieved_lesson_ids', 'selected_lesson_ids', 'causally_applied_lesson_ids', 'rejected_lesson_ids', 'causally_applied_retrieval_ids'] as $field) if (! empty($intent->$field)) return false;
        foreach (['cohort_role', 'failure_repair_contract', 'learning_method_contract', 'skill_cartridge', 'protocol_violation'] as $field) if (! empty(data_get($intent->metadata, $field))) return false;
        return true;
    }

    /** Exact construction compensation, after the whole valid original pair, without generic learning fanout. */
    private function settleConstructorEpisodes(array $seal, LabEvidenceArtifact $artifact, array $publication): array
    {
        $proof = $seal['constructor_proof'];
        $this->assertConstructorEpisodes($proof, LabGeneration::findOrFail($seal['generation_id']));
        $ids = [];
        foreach ($proof['constructor_episode_ids'] as $witness) {
            $episode = AgentLearningEpisode::whereKey($witness['episode_id'])->lockForUpdate()->firstOrFail();
            $sourceKey = self::PROTOCOL.'|neutral_constructor|'.$seal['study_id'].'|'.$episode->id;
            $outcome = ['protocol' => self::PROTOCOL, 'study_id' => $seal['study_id'], 'intent_hash' => $proof['intent_hash'],
                'original_preregistration_artifact_hash' => $artifact->sha256, 'original_pair_receipt_key' => $publication['receipt_key'],
                'episode_id' => $episode->id, 'agent_id' => $witness['agent_id'], 'model_id' => $witness['model_id'],
                'authority' => 'none', 'selection_reward' => 0.0, 'scientific_lesson_inferred' => false, 'promotion_evidence' => false];
            $existing = AgentLearningSettlement::where('episode_id', $episode->id)->first();
            if ($existing) {
                if ($existing->source_key !== $sourceKey || $existing->source_type !== self::class
                    || (int) $existing->source_id !== (int) $publication['receipt_id'] || $existing->selection_reward !== 0.0
                    || $existing->evidence_state !== 'neutral' || $existing->hard_failure || ! $this->same($existing->outcome, $outcome)
                    || $episode->status !== 'settled') $this->refuse('CONSTRUCTOR_NEUTRAL_SETTLEMENT_CONFLICT');
                $ids[] = $existing->id; continue;
            }
            if (! in_array($episode->status, ['open', 'decision', 'running'], true)) $this->refuse('CONSTRUCTOR_EPISODE_TERMINAL_CONFLICT');
            $settlement = AgentLearningSettlement::create(['settlement_id' => (string) Str::uuid(), 'episode_id' => $episode->id,
                'source_key' => $sourceKey, 'source_type' => self::class, 'source_id' => $publication['receipt_id'],
                'outcome_status' => 'authority_withheld', 'failure_class' => 'research_only_decision_sensitivity', 'evidence_state' => 'neutral',
                'selection_reward' => 0.0, 'hard_failure' => false, 'outcome' => $outcome,
                'reward_components' => ['protocol' => self::PROTOCOL, 'signal_authority' => 'none', 'promotion_evidence' => false],
                'reflection' => ['protocol' => self::PROTOCOL, 'scientific_lesson_inferred' => false, 'promotion_evidence' => false], 'settled_at' => now()]);
            $episode->update(['status' => 'settled', 'settled_at' => now()]); $ids[] = $settlement->id;
        }
        return $ids;
    }

    private function completeSourceReferences(array $seal, array $publication): void
    {
        foreach ($seal['constructor_proof']['source_ids'] as $role => $reference) {
            $agent = LabAgent::whereKey($reference['agent_id'])->lockForUpdate()->firstOrFail();
            if ((int) $agent->model_version_id !== (int) $reference['model_version_id']
                || (int) $agent->lab_generation_id !== (int) $seal['generation_id']
                || LabEvaluationRun::where('lab_agent_id', $agent->id)->exists()) $this->refuse('ORIGINAL_SOURCE_REFERENCE_IDENTITY_INVALID');
            $prior = LabLifecycleEvent::where('lab_agent_id', $agent->id)->where('event_type', 'native_spread_context_source_reference_disposition')->get();
            if ($prior->isNotEmpty()) {
                if ($prior->count() !== 1 || data_get($prior->first()->payload, 'original_pair_receipt_key') !== $publication['receipt_key']) $this->refuse('SOURCE_REFERENCE_DISPOSITION_CONFLICT');
                continue;
            }
            $from = (string) $agent->lifecycle_status;
            $quarantined = in_array($from, ['quarantined', 'technical_quarantine', 'legacy_quarantine'], true);
            if (! $quarantined && $from !== 'draft') $this->refuse('SOURCE_REFERENCE_TERMINAL_CONFLICT');
            if (! $quarantined) {
                $agent->update(['lifecycle_status' => 'completed',
                    'decision_reason' => 'NATIVE_SPREAD_CONTEXT_STUDY_SOURCE_ONLY_REFERENCE: original pair closed; no evaluator outcome or authority.']);
            }
            $this->evidence->recordLifecycle($agent, 'native_spread_context_source_reference_disposition', [
                'protocol' => self::PROTOCOL, 'study_id' => $seal['study_id'], 'intent_hash' => $seal['constructor_proof']['intent_hash'],
                'role' => $role, 'original_pair_receipt_id' => $publication['receipt_id'], 'original_pair_receipt_key' => $publication['receipt_key'],
                'reason_code' => $quarantined ? 'SOURCE_REFERENCE_QUARANTINE_PRESERVED' : 'SOURCE_ONLY_REFERENCE_COMPLETED',
                'source_only_reference' => true, 'evaluator_outcome' => false, 'economic_authority' => false, 'skill_authority' => false,
                'independent_evidence' => false, 'promotion_evidence' => false], 'construction', null, 1, self::class, null,
                $from, $quarantined ? $from : 'completed');
        }
    }

    /** Original terminal errors close operational debt without an economic verdict or replay. */
    private function completeTechnicalCarriers(array $seal, array $publication): void
    {
        if (($publication['classification'] ?? null) !== 'TECHNICAL_QUARANTINE') return;
        foreach ($seal['members'] as $arm => $member) {
            $agent = LabAgent::whereKey($member['agent_id'])->lockForUpdate()->firstOrFail();
            if ($agent->lifecycle_status !== 'evaluation_error') continue;
            $runs = LabEvaluationRun::where('lab_agent_id', $agent->id)->orderBy('id')->get();
            if ($runs->count() !== 1 || $runs->first()->status !== 'technical_error'
                || (int) $runs->first()->model_version_id !== $member['model_id']
                || (int) $runs->first()->lab_generation_id !== $seal['generation_id']) $this->refuse('ORIGINAL_TECHNICAL_CARRIER_EVIDENCE_REQUIRED');
            $run = $runs->first(); $this->originalPayload($run, 'evaluation_response');
            if ($run->request_hash !== null) $this->originalPayload($run, 'evaluation_request');
            $changed = LabAgent::whereKey($agent->id)->where('lifecycle_status', 'evaluation_error')->update([
                'lifecycle_status' => 'technical_quarantine',
                'decision_reason' => 'NATIVE_SPREAD_CONTEXT_STUDY_ORIGINAL_TECHNICAL_TERMINAL: authority withheld; no comparative effect or economic verdict.']);
            if ($changed !== 1) continue;
            $this->evidence->recordLifecycle($agent, 'native_spread_context_original_technical_disposition', [
                'protocol' => self::PROTOCOL, 'study_id' => $seal['study_id'], 'arm' => $arm,
                'original_pair_receipt_id' => $publication['receipt_id'], 'original_pair_receipt_key' => $publication['receipt_key'],
                'original_response_hash' => $run->response_hash, 'reason_code' => 'ORIGINAL_STUDY_TECHNICAL_TERMINAL',
                'comparative_effect_measured' => false, 'economic_authority' => false, 'skill_authority' => false,
                'independent_evidence' => false, 'promotion_evidence' => false], 'screening', $run->run_id, $run->attempt, self::class,
                null, 'evaluation_error', 'technical_quarantine');
        }
    }

    private function compare(array $receipts, array $identity): array
    {
        foreach (['schedule_hash', 'index_set_hash', 'decision_rows', 'signal_start', 'signal_end', 'execution_start', 'execution_end'] as $field) {
            $leftClock = data_get($receipts, 'masked.replay_executed_clock.'.$field);
            if ($leftClock === null || $leftClock !== data_get($receipts, 'unmasked.replay_executed_clock.'.$field)) $this->refuse('PAIRED_EXECUTED_CLOCK_MISMATCH');
        }
        $events = []; foreach ($receipts as $arm => $receipt) $events[$arm] = $this->events($this->sealedBody($receipt, 'receipt'), $identity);
        if (array_keys($events['masked']) !== array_keys($events['unmasked'])) $this->refuse('RAW_OPPORTUNITY_UNIVERSE_MISMATCH');
        $eligible = 0; $changed = 0; $unreached = 0; $diverged = 0; $missing = 0; $missingFeature = 0;
        foreach ($events['masked'] as $id => $left) {
            $right = $events['unmasked'][$id];
            foreach (['event_id', 'evaluation_index', 'signal_time', 'execution_time', 'direction', 'raw_signal_hash',
                'source_context', 'source_context_hash', 'source_quote', 'source_quote_hash', 'closed_input_hash'] as $key) {
                if (! $this->same($left[$key], $right[$key])) $this->refuse('RAW_EVENT_SOURCE_MISMATCH');
            }
            if (($left['source_quote']['available'] ?? false) !== true) { $missing++; continue; }
            if (($left['source_quote']['feature_available'] ?? false) !== true) { $missingFeature++; continue; }
            if (! $left['gate_reached'] || ! $right['gate_reached'] || ! $left['feature_gate_reached'] || ! $right['feature_gate_reached']) { $unreached++; continue; }
            if ($left['account_before_gate_hash'] !== $right['account_before_gate_hash']) { $diverged++; continue; }
            $eligible++;
            if ($left['gate_allowed'] !== $right['gate_allowed'] && $left['action'] !== $right['action']) $changed++;
        }
        $dataMissing = $missing > 0 || $missingFeature > 0 || collect($receipts)->contains(fn ($receipt) => ($receipt['status'] ?? null) === 'data_missing');
        return ['status' => $dataMissing ? 'data_missing' : ($eligible < $identity['minimum_paired_opportunities'] ? 'underpowered' : 'measured_existing_data_sensitivity'),
            'criterion' => self::CRITERION, 'matching_raw_opportunities' => count($events['masked']), 'eligible_paired_opportunities' => $eligible,
            'changed_entry_wait_decisions' => $changed, 'excluded_unreached' => $unreached, 'excluded_diverged_account' => $diverged,
            'missing_quote_opportunities' => $missing, 'missing_context_feature_opportunities' => $missingFeature,
            'decision_changed' => $eligible > 0 ? $changed > 0 : null,
            'market_value_proven' => false, 'economic_authority' => false, 'skill_authority' => false, 'promotion_evidence' => false];
    }

    private function events(array $receipt, array $identity): array
    {
        if (! is_array($receipt['events'] ?? null) || ! array_is_list($receipt['events'])) $this->refuse('EVENT_LEDGER_REQUIRED');
        $events = []; $indices = [];
        foreach ($receipt['events'] as $event) {
            foreach (['event_id', 'raw_signal_hash', 'source_context_hash', 'source_quote_hash', 'closed_input_hash'] as $key) if (! $this->sha($event[$key] ?? null)) $this->refuse('EVENT_HASH_INVALID');
            if (isset($events[$event['event_id']]) || ! is_int($event['evaluation_index'] ?? null) || isset($indices[$event['evaluation_index']])
                || ! in_array($event['direction'] ?? null, ['BUY', 'SELL'], true) || ! in_array($event['action'] ?? null, ['ENTRY', 'WAIT'], true)
                || ! is_bool($event['gate_reached'] ?? null) || ! is_bool($event['feature_gate_reached'] ?? null)
                || ($event['feature_gate_reached'] && ! $event['gate_reached'])
                || ($event['mask_applied'] ?? null) !== ($receipt['arm'] === 'masked')
                || ! $this->contextMatches((array) ($event['source_context'] ?? []), $identity['exact_context'])
                || isset($event['source_context']['spread_liquidity_state'])
                || $this->hash((array) $event['source_context']) !== $event['source_context_hash']
                || $this->hash((array) ($event['source_quote'] ?? [])) !== $event['source_quote_hash']) $this->refuse('EVENT_IDENTITY_INVALID');
            if ($event['gate_reached'] && (! $this->sha($event['account_before_gate_hash'] ?? null)
                || ! is_bool($event['gate_allowed'] ?? null) || ! is_array($event['gate_context'] ?? null)
                || $this->hash($event['gate_context']) !== ($event['gate_context_hash'] ?? null))) $this->refuse('GATE_WITNESS_INVALID');
            $quote = (array) ($event['source_quote'] ?? []);
            if (! is_bool($quote['available'] ?? null) || ! is_bool($quote['feature_available'] ?? null)) $this->refuse('QUOTE_AVAILABILITY_INVALID');
            $atrKey = self::ATR_BINDINGS[$identity['liquidity_atr_binding']] ?? null;
            $atr = $quote['atr'] ?? null;
            if ($atrKey === null || ($quote['atr_binding'] ?? null) !== $identity['liquidity_atr_binding']
                || ($quote['atr_source_key'] ?? null) !== $atrKey
                || ($atr !== null && ((! is_int($atr) && ! is_float($atr)) || ! is_finite((float) $atr)))
                || ($quote['atr_input_hash'] ?? null) !== $this->hash(['source_key' => $atrKey, 'value' => $atr])) $this->refuse('DECLARED_ATR_INPUT_WITNESS_INVALID');
            if ($event['gate_reached'] && (! $this->same(array_diff_key($event['gate_context'], ['spread_liquidity_state' => true]), $event['source_context'])
                || data_get($event, 'gate_context.spread_liquidity_state') !== ($receipt['arm'] === 'masked' ? 'unknown' : ($quote['observed_state'] ?? null)))) $this->refuse('GATE_MASK_INTERVENTION_INVALID');
            if (($quote['available'] ?? null) === true && (($quote['provenance_hash'] ?? null) !== $identity['quote_provenance_hash']
                || ! $this->sha($quote['source_sha256'] ?? null)
                || ($quote['source_sha256'] ?? null) !== data_get($receipt, 'source_attestation.actual_source_sha256'))) $this->refuse('QUOTE_SOURCE_INVALID');
            $clock = (array) ($receipt['replay_executed_clock'] ?? []);
            if ($event['evaluation_index'] < ($clock['first_evaluation_index'] ?? PHP_INT_MAX)
                || $event['evaluation_index'] > ($clock['last_evaluation_index'] ?? -1)
                || ! $this->utc($event['signal_time'] ?? null) || ! $this->utc($event['execution_time'] ?? null)
                || ! $this->utc($clock['signal_start'] ?? null) || ! $this->utc($clock['signal_end'] ?? null)
                || CarbonImmutable::parse($event['signal_time'])->lessThan(CarbonImmutable::parse($clock['signal_start']))
                || CarbonImmutable::parse($event['signal_time'])->greaterThan(CarbonImmutable::parse($clock['signal_end']))
                || CarbonImmutable::parse($event['execution_time'])->lessThan(CarbonImmutable::parse($event['signal_time'])->addMinutes(5))) $this->refuse('EVENT_CLOCK_INVALID');
            if (($event['event_id'] ?? null) !== $this->hash(['member' => $identity['target_member_hash'], 'evaluation_index' => $event['evaluation_index'],
                'signal_time' => $event['signal_time'], 'execution_time' => $event['execution_time'], 'direction' => $event['direction']])) $this->refuse('EVENT_CLOCK_IDENTITY_INVALID');
            if ($quote['available']) {
                foreach (['bid', 'ask', 'spread', 'age_ms'] as $key) if ((! is_int($quote[$key] ?? null) && ! is_float($quote[$key] ?? null)) || ! is_finite((float) $quote[$key])) $this->refuse('QUOTE_PHYSICS_INVALID');
                if (! $this->utc($quote['quote_time'] ?? null) || ! $this->utc($quote['available_at'] ?? null)) $this->refuse('QUOTE_ASOF_INVALID');
                $close = CarbonImmutable::parse($event['signal_time'])->addMinutes(5); $tick = CarbonImmutable::parse($quote['quote_time']);
                if ($quote['bid'] <= 0 || $quote['ask'] < $quote['bid'] || $quote['age_ms'] <= 0 || $quote['age_ms'] > 60000
                    || abs($quote['spread'] - ($quote['ask'] - $quote['bid'])) > .000001
                    || $tick->lessThan(CarbonImmutable::parse($event['signal_time'])) || ! $tick->lessThan($close)
                    || ! CarbonImmutable::parse($quote['available_at'])->equalTo($close)
                    || CarbonImmutable::parse($quote['available_at'])->greaterThan(CarbonImmutable::parse($event['execution_time']))
                    || abs($tick->diffInMilliseconds($close) - $quote['age_ms']) > .001) $this->refuse('QUOTE_PHYSICS_OR_ASOF_INVALID');
            }
            if ($quote['feature_available'] && (! $quote['available'] || (! is_int($quote['atr'] ?? null) && ! is_float($quote['atr'] ?? null))
                || ! is_finite((float) $quote['atr']) || $quote['atr'] <= 0
                || ($quote['observed_state'] ?? null) !== ($quote['spread'] / $quote['atr'] <= .25 ? 'liquid' : 'illiquid'))) $this->refuse('CONTEXT_FEATURE_INVALID');
            $events[$event['event_id']] = $event;
            $indices[$event['evaluation_index']] = true;
        }
        ksort($events);
        $counts = (array) ($receipt['counts'] ?? []);
        $actual = ['matching_context_opportunities' => count($events), 'observed_quote_opportunities' => 0,
            'gate_reached_opportunities' => 0, 'feature_gate_reached_opportunities' => 0,
            'entry_actions' => 0, 'wait_actions' => 0, 'missing_quote_opportunities' => 0, 'missing_context_feature_opportunities' => 0];
        foreach ($events as $event) {
            $actual[$event['source_quote']['available'] ? 'observed_quote_opportunities' : 'missing_quote_opportunities']++;
            $actual['missing_context_feature_opportunities'] += (int) ($event['source_quote']['available'] && ! $event['source_quote']['feature_available']);
            $actual['gate_reached_opportunities'] += (int) $event['gate_reached'];
            $actual['feature_gate_reached_opportunities'] += (int) $event['feature_gate_reached'];
            $actual[$event['action'] === 'ENTRY' ? 'entry_actions' : 'wait_actions']++;
        }
        foreach ($actual as $key => $count) if (! is_int($counts[$key] ?? null) || $counts[$key] !== $count) $this->refuse('EVENT_COVERAGE_INVALID');
        if (! is_int($counts['raw_opportunities'] ?? null) || ! is_int($counts['outside_context_opportunities'] ?? null)
            || $counts['outside_context_opportunities'] < 0 || $counts['raw_opportunities'] !== count($events) + $counts['outside_context_opportunities']) $this->refuse('RAW_OPPORTUNITY_COVERAGE_INVALID');
        $expectedStatus = $actual['missing_quote_opportunities'] > 0 || $actual['missing_context_feature_opportunities'] > 0 ? 'data_missing'
            : (min($actual['matching_context_opportunities'], $actual['feature_gate_reached_opportunities']) < $identity['minimum_paired_opportunities'] ? 'underpowered' : 'computed');
        if (($receipt['status'] ?? null) !== $expectedStatus) $this->refuse('PRODUCER_STATUS_COVERAGE_MISMATCH');
        return $events;
    }

    private function identity(ModelVersion $model, array $request, array $spec): array
    {
        $version = $this->councils->researchVersionForModel($model);
        if (! $version) $this->refuse('NATIVE_SOURCE_REQUIRED');
        $dataset = (string) ($request['replay_dataset_hash'] ?? '');
        $execution = (string) data_get($request, 'execution_contract.execution_hash', '');
        $costs = app(ExecutionContractService::class);
        if ($costs->hashParameters((array) data_get($request, 'execution_contract.parameters', [])) !== $execution
            || (isset($request['execution']) && $costs->hashParameters((array) $request['execution']) !== $execution)) $this->refuse('EXECUTION_COST_IDENTITY_INVALID');
        $mtf = empty($request['mtf_snapshot_manifest']) ? null : ['bundle_hash' => $dataset, 'manifest' => $request['mtf_snapshot_manifest']];
        $runtime = $this->councils->runtimeContractForModel($model, (string) ($request['timeframe'] ?? ''), $dataset, $execution, $mtf, (string) ($request['symbol'] ?? ''));
        if (($request['evaluation_mode'] ?? null) !== 'incremental' || ($request['timeframe'] ?? null) !== 'M5'
            || ! empty($runtime['upgrades'])) $this->refuse('BOUNDED_INCREMENTAL_M5_WITHOUT_UPGRADES_REQUIRED');
        $member = collect($runtime['members'])->firstWhere('specialist_id', $spec['specialist_id'] ?? '');
        $context = (array) ($spec['exact_context'] ?? []);
        $atrBinding = $spec['liquidity_atr_binding'] ?? null;
        if (! $member || array_diff(array_keys($context), self::AXES) !== [] || count($context) !== 5
            || collect($context)->contains(fn ($value) => ! is_string($value) || strlen($value) < 1 || strlen($value) > 128 || $value === 'any')
            || ! in_array($context['direction'] ?? null, ['BUY', 'SELL'], true)) $this->refuse('EXACT_TARGET_CONTEXT_REQUIRED');
        if (! is_string($atrBinding) || ! array_key_exists($atrBinding, self::ATR_BINDINGS)) $this->refuse('EXPLICIT_ATR_INPUT_BINDING_REQUIRED');
        if (($member['liquidity_atr_binding'] ?? null) !== $atrBinding) $this->refuse('DECLARED_NATIVE_ATR_PORT_MISMATCH');
        $minimum = $spec['minimum_paired_opportunities'] ?? 1;
        $capital = $request['initial_balance'] ?? null;
        $policy = (array) data_get($request, 'policy_context.prospective_probe_window', []);
        if (! is_int($minimum) || $minimum < 1 || $minimum > 20000 || ! is_numeric($capital) || ! is_finite((float) $capital) || $capital <= 0
            || $policy === [] || (int) ($policy['evaluated_rows'] ?? 20001) > 20000) $this->refuse('BOUNDED_CRITERION_AND_CAPITAL_REQUIRED');
        $policyHash = $this->hash($policy);
        if (isset($spec['evaluation_policy_hash']) && $spec['evaluation_policy_hash'] !== $policyHash) $this->refuse('EVALUATION_POLICY_HASH_INVALID');
        $provenance = (array) data_get($request, 'mtf_snapshot_manifest.quote_spread_provenance', []);
        if ($provenance !== [] && (($provenance['protocol'] ?? null) !== 'historical_quote_spread_snapshot_v1'
            || ($provenance['provider'] ?? null) !== 'dukascopy_historical_synchronized_tick_v1')) $this->refuse('QUOTE_PROVENANCE_PROTOCOL_INVALID');
        $quoteHash = $this->hash($provenance);
        if (isset($spec['quote_provenance_hash']) && $spec['quote_provenance_hash'] !== $quoteHash) $this->refuse('QUOTE_PROVENANCE_HASH_INVALID');
        foreach ([$dataset, $execution] as $hash) if (! $this->sha($hash)) $this->refuse('SOURCE_IDENTITY_HASH_REQUIRED');
        return ['native_contract_hash' => $runtime['contract_hash'], 'target_member_hash' => $this->hash(['council_version' => $runtime['council_version'], 'member' => $member]),
            'specialist_id' => $member['specialist_id'], 'dataset_hash' => $dataset, 'execution_hash' => $execution,
            'account_policy_hash' => $this->hash($runtime['policy']), 'initial_capital' => (float) $capital,
            'evaluation_policy_hash' => $policyHash, 'quote_provenance_hash' => $quoteHash,
            'exact_context' => $context, 'liquidity_atr_binding' => $atrBinding, 'minimum_paired_opportunities' => $minimum];
    }

    private function assertNativeRequest(array $payload, array $identity): void
    {
        if (data_get($payload, 'specialist_council_contract.contract_hash') !== $identity['native_contract_hash']) $this->refuse('EXACT_NATIVE_DISPATCH_REQUIRED');
        $this->sealedBody((array) $payload['specialist_council_contract'], 'contract');
    }

    /** Administrative relabeling and source releases do not reserve a fresh physical experiment. */
    private function physicalQuestion(ModelVersion $model, array $request, array $identity): array
    {
        $mtf = empty($request['mtf_snapshot_manifest']) ? null : ['bundle_hash' => $identity['dataset_hash'], 'manifest' => $request['mtf_snapshot_manifest']];
        $runtime = $this->councils->runtimeContractForModel($model, $request['timeframe'], $identity['dataset_hash'], $identity['execution_hash'], $mtf, $request['symbol']);
        $members = []; $target = null;
        foreach ($runtime['members'] as $member) {
            $semantic = ['strategy' => $member['base_strategy'] ?? $member['strategy'], 'parameters' => $member['parameters'],
                'role' => $member['role'], 'horizon' => $member['horizon'], 'capital_weight' => $member['capital_weight'],
                'liquidity_atr_binding' => $member['liquidity_atr_binding'] ?? null,
                'risk_per_trade_percent' => $member['risk_per_trade_percent'], 'scope' => $member['scope'] ?? [],
                'context' => $this->withoutLabels((array) ($member['specialist_context_contract'] ?? [])),
                'composition' => $this->withoutLabels((array) ($member['composition_runtime_contract'] ?? [])),
                'operator' => $this->withoutLabels((array) ($member['operator_contract'] ?? [])), 'instruments' => []];
            foreach ((array) data_get($member, 'instrument_research_assignment.selected', []) as $instrument) {
                $semantic['instruments'][] = ['instrument_key' => $instrument['instrument_key'] ?? null, 'role' => $instrument['role'] ?? null,
                    'activation' => $this->withoutLabels((array) ($instrument['activation_contract'] ?? []))];
            }
            if ($member['specialist_id'] === $identity['specialist_id']) $target = $semantic;
            $members[] = $semantic;
        }
        return ['protocol' => self::PROTOCOL, 'programmes' => $members, 'target' => $target,
            'account_policy' => $this->withoutLabels($runtime['policy']), 'execution' => (array) data_get($request, 'execution_contract.parameters', []),
            'initial_capital' => $identity['initial_capital'], 'dataset_physical_hash' => $this->physicalDatasetHash($request, $identity['dataset_hash']),
            'evaluation_scope' => $this->withoutLabels(array_diff_key((array) data_get($request, 'policy_context.prospective_probe_window', []),
                array_flip(['dataset_hash', 'execution_hash']))),
            'exact_context' => $identity['exact_context'], 'liquidity_atr_binding' => $identity['liquidity_atr_binding'],
            'criterion' => self::CRITERION, 'intervention' => self::INTERVENTION];
    }

    private function physicalDatasetHash(array $request, string $fallback): string
    {
        $streams = (array) data_get($request, 'mtf_snapshot_manifest.streams', []);
        if ($streams === [] && ! empty($request['dataset_path'])) $streams = ['M5' => ['path' => $request['dataset_path'],
            'sha256' => data_get($request, 'mtf_snapshot_manifest.quote_spread_provenance.source_m5_csv_sha256', $fallback)]];
        if ($streams === []) return $fallback;
        $hashes = [];
        foreach ($streams as $timeframe => $stream) {
            $path = $stream['path'] ?? '';
            if (! is_string($path) || ! is_file($path) || hash_file('sha256', $path) !== ($stream['sha256'] ?? null)) $this->refuse('PHYSICAL_SOURCE_BYTES_REQUIRED');
            $rows = app(LabDatasetExportService::class)->rowsFromSnapshot($path, 40001);
            if (count($rows) > 40000 || $rows === [] || (isset($stream['row_count']) && $stream['row_count'] !== count($rows))) $this->refuse('BOUNDED_PHYSICAL_SOURCE_ROWS_REQUIRED');
            foreach ($rows as &$row) {
                foreach ($row as $key => $value) {
                    if (in_array($key, ['time', 'quote_time_utc', 'quote_available_after_utc'], true) && $value !== '') {
                        $row[$key] = CarbonImmutable::parse($value, 'UTC')->utc()->format('Y-m-d\TH:i:s.u\Z');
                    } elseif (in_array($key, ['volume_available', 'spread_available'], true)) {
                        $row[$key] = in_array($value, [true, 1, '1'], true);
                    } elseif (is_numeric($value)) { $row[$key] = (float) $value; }
                }
            }
            unset($row);
            $hashes[(string) $timeframe] = $this->hash($rows);
        }
        return $this->hash($hashes);
    }

    private function withoutLabels(array $value): array
    {
        $labels = ['id', 'version', 'version_id', 'council_id', 'council_version', 'model_version_id', 'specialist_id', 'source_model_hash',
            'passport_hash', 'contract_hash', 'contract_json', 'manifest_hash', 'assignment_hash', 'experiment_key', 'source_hash',
            'evaluator_version', 'generation_id', 'study_id', 'source_task_key', 'component_id', 'strategy_version', 'tactic_version', 'management_version'];
        foreach ($value as $key => $item) {
            if (in_array((string) $key, $labels, true)) { unset($value[$key]); continue; }
            if (is_array($item)) $value[$key] = $this->withoutLabels($item);
        }
        return $value;
    }

    private function sameProgram(ModelVersion $left, ModelVersion $right): bool
    {
        $schemas = app(StrategyParameterSchemaService::class);
        $base = fn (ModelVersion $model) => $schemas->runtimeBaseStrategy($model->strategy,
            data_get($model->metadata, 'base_strategy'), (string) data_get($model->metadata, 'strategy_family', $model->strategy));
        return $base($left) === $base($right) && $this->same($left->parameters, $right->parameters)
            && $this->same($this->withoutLabels((array) data_get($this->evidence->modelRuntimeBasis($left), 'components', [])),
                $this->withoutLabels((array) data_get($this->evidence->modelRuntimeBasis($right), 'components', [])));
    }

    private function modelSeal(ModelVersion $model): array
    {
        $marker = (array) data_get($model->metadata, self::MARKER, []);
        $artifact = $this->artifact((string) ($marker['study_id'] ?? ''));
        if (! $artifact) $this->refuse('ORIGINAL_PREREGISTRATION_REQUIRED');
        $seal = $this->readSeal($artifact); $arm = $this->marker($model, $seal, $artifact);
        if (app(SpecialistCouncilContractService::class)->modelHash($model) !== $seal['model_hashes'][$arm]) $this->refuse('SOURCE_MODEL_DRIFT');
        return [$seal, $artifact, $arm];
    }

    private function marker(ModelVersion $model, array $seal, LabEvidenceArtifact $artifact): string
    {
        $marker = (array) data_get($model->metadata, self::MARKER, []); $arm = $marker['arm'] ?? '';
        if (! isset($seal['members'][$arm]) || $seal['members'][$arm]['model_id'] !== (int) $model->id
            || ($marker['protocol'] ?? null) !== self::PROTOCOL || ($marker['study_id'] ?? null) !== $seal['study_id']
            || ($marker['artifact_hash'] ?? null) !== $artifact->sha256
            || ($marker['contract_hash'] ?? null) !== $seal['contracts'][$arm]['contract_hash']) $this->refuse('ORIGINAL_MODEL_MARKER_INVALID');
        return $arm;
    }

    private function readSeal(LabEvidenceArtifact $artifact): array
    {
        $seal = $this->evidence->readArtifactPayload($artifact);
        if (! is_array($seal) || ($seal['protocol'] ?? null) !== self::PROTOCOL || $artifact->run_id !== ($seal['study_id'] ?? null)) $this->refuse('PREREGISTRATION_INVALID');
        foreach (['masked', 'unmasked'] as $arm) {
            $contract = $seal['contracts'][$arm] ?? []; $body = $this->sealedBody($contract, 'contract');
            if (($body['arm'] ?? null) !== $arm || ($body['study_id'] ?? null) !== $seal['study_id']
                || ! $this->same($body['identity'] ?? [], $seal['identity'])
                || data_get($contract, 'server_seal.protocol') !== 'native_spread_context_study_server_seal_v1'
                || ! hash_equals(hash_hmac('sha256', self::PROTOCOL."\n".$contract['contract_hash'], $this->key()),
                    (string) data_get($contract, 'server_seal.hmac_sha256', ''))) $this->refuse('SERVER_SEAL_INVALID');
        }
        return $seal;
    }

    private function sealedBody(array $value, string $prefix): array
    {
        $json = $value[$prefix.'_json'] ?? null;
        try { $body = is_string($json) ? json_decode($json, true, 512, JSON_THROW_ON_ERROR) : null; } catch (\Throwable) { $body = null; }
        $copy = array_diff_key($value, array_flip([$prefix.'_hash', $prefix.'_json', 'server_seal']));
        if (! is_array($body) || ! $this->same($body, $copy) || $this->hash($body) !== ($value[$prefix.'_hash'] ?? null)) $this->refuse(strtoupper($prefix).'_SEAL_INVALID');
        return $body;
    }

    private function artifact(string $studyId): ?LabEvidenceArtifact
    {
        $rows = LabEvidenceArtifact::where('artifact_type', self::ARTIFACT)->where('run_id', $studyId)->orderBy('id')->get();
        if ($rows->count() > 1) $this->refuse('DUPLICATE_PREREGISTRATION_ARTIFACT');
        return $rows->first();
    }

    private function originalPayload(LabEvaluationRun $run, string $type): array
    {
        $rows = LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', $type)->orderBy('id')->get();
        if ($rows->count() !== 1) $this->refuse('EXACT_ORIGINAL_ARTIFACT_REQUIRED');
        $artifact = $rows->first();
        if (data_get($artifact->metadata, 'storage_protocol') !== 'compressed_artifact_v2'
            || ($type === 'evaluation_response' && $artifact->sha256 !== $run->response_hash)
            || ($run->finished_at && (! $artifact->created_at || $artifact->created_at->greaterThan($run->finished_at)))) $this->refuse('ORIGINAL_ARTIFACT_HASH_OR_TIME_INVALID');
        return $this->evidence->readArtifactPayload($artifact) ?? [];
    }

    private function registration(LabEvidenceArtifact $artifact, array $seal): array
    {
        return ['protocol' => self::PROTOCOL, 'status' => 'preregistered', 'study_id' => $seal['study_id'],
            'artifact_hash' => $artifact->sha256, 'contracts' => $seal['contracts'], 'promotion_evidence' => false];
    }

    private function key(): string { $key = (string) config('services.internal_api.token'); if (strlen($key) < 32) $this->refuse('SERVER_HMAC_KEY_REQUIRED'); return $key; }
    private function utc(mixed $value): bool { return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|\+00:00)$/D', $value) === 1; }
    private function contextMatches(array $observed, array $expected): bool
    {
        foreach (self::AXES as $axis) {
            $normalize = static function (mixed $value) use ($axis): string {
                $value = strtolower(trim((string) $value));
                if ($axis === 'volatility') return ['low_volatility' => 'low', 'normal_volatility' => 'normal', 'high_volatility' => 'high'][$value] ?? $value;
                if ($axis === 'session') return ['london_new_york_overlap' => 'overlap', 'london_comex_overlap' => 'overlap', 'asian' => 'asia'][$value] ?? $value;
                return $value;
            };
            if ($normalize($observed[$axis] ?? '') !== $normalize($expected[$axis] ?? '')) return false;
        }
        return true;
    }
    private function sha(mixed $value): bool { return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1; }
    private function same(mixed $left, mixed $right): bool { return $this->evidence->equivalentJsonValue($left, $right); }
    private function hash(array $value): string { return $this->epochs->parameterHash($value); }
    private function json(array $value): string { return json_encode($this->canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION); }
    private function canonical(array $value): array { if (! array_is_list($value)) ksort($value); foreach ($value as $key => $item) if (is_array($item)) $value[$key] = $this->canonical($item); return $value; }
    private function refuse(string $code): never { throw new LogicException('NATIVE_SPREAD_STUDY_'.$code); }
}
