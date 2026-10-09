<?php

namespace App\Services;

use App\Models\AgentLearningEpisode;
use App\Models\AgentLearningSettlement;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Models\ResearchExperimentReceipt;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** One original diagnostic question; canonical construction/dispatch own execution. */
class NativeReachabilityDepthAuditService
{
    public const PURPOSE = 'native_reachability_depth_audit';
    public const PROTOCOL = 'native_reachability_depth_audit_v1';
    public const RECEIPT = 'native_reachability_depth_audit_receipt_v1';
    public const FIELD = 'native_reachability_depth_audit_contract';
    public const MARKER = 'native_reachability_depth_audit';
    public const PHASE = 'native_reachability_depth_phase';
    public const ARTIFACT = 'native_reachability_depth_preregistration';
    public const SAMPLE_ARTIFACT = 'native_reachability_depth_original_sample';

    public function __construct(private LabImmutableEvidenceService $evidence,
        private SpecialistCouncilLifecycleService $councils, private ResearchPaperEpochContractService $epochs) {}

    public function preregister(LabAgent $cheap, LabAgent $deeper, array $request, array $declaration): array
    {
        LabPopulationService::assertNativeDepthAuditDeclaration($declaration);
        return DB::transaction(function () use ($cheap, $deeper, $request, $declaration): array {
            $generation = LabGeneration::whereKey($cheap->lab_generation_id)->lockForUpdate()->firstOrFail();
            $proof = app(SpecialistCouncilPreparationService::class)->assertOriginalDepthAuditCarriers($generation, $cheap, $deeper);
            if (! $this->same($declaration, $proof['depth_audit_declaration'])) $this->refuse('ORIGINAL_DECLARATION_MISMATCH');
            if (data_get($generation->trigger_context, self::MARKER) !== null) $this->refuse('ALREADY_REGISTERED');
            $models = ModelVersion::whereIn('id', [$cheap->model_version_id, $deeper->model_version_id])->lockForUpdate()->get()->keyBy('id');
            $cheapModel = $models[$cheap->model_version_id]; $deeperModel = $models[$deeper->model_version_id];
            if (! $this->same($cheapModel->parameters, $deeperModel->parameters)
                || data_get($cheapModel->metadata, 'specialist_council.version_id') !== data_get($deeperModel->metadata, 'specialist_council.version_id')) {
                $this->refuse('IDENTICAL_NATIVE_PROGRAM_REQUIRED');
            }
            $native = $this->nativeRequest($cheapModel, $request);
            $probe = (array) data_get($request, 'policy_context.prospective_probe_window', []);
            $manifest = (array) ($request['mtf_snapshot_manifest'] ?? []);
            if (($request['evaluation_mode'] ?? null) !== 'incremental' || ($request['timeframe'] ?? null) !== 'M5'
                || ($request['symbol'] ?? null) !== 'XAUUSD' || ($request['dataset_tail_rows'] ?? null) !== null
                || ! empty($native['upgrades']) || count($native['members'] ?? []) !== 4
                || ($probe['loaded_rows'] ?? null) !== 15512 || ($probe['warmup_rows'] ?? null) !== 512 || ($probe['evaluated_rows'] ?? null) !== 15000
                || ($manifest['validation_bundle_protocol'] ?? null) !== 'prospective_clean_discovery_bundle_v1'
                || ($request['initial_balance'] ?? null) != $declaration['initial_capital']) $this->refuse('ORIGINAL_PHYSICAL_DISCOVERY_REQUIRED');
            $identity = ['native_contract_hash' => $native['contract_hash'], 'dataset_hash' => $request['replay_dataset_hash'],
                'execution_hash' => $native['execution_hash'], 'account_policy_hash' => $this->hash($native['policy']),
                'initial_capital' => (float) $request['initial_balance'], 'evaluation_policy_hash' => $this->hash($probe),
                'context_hash' => $this->hash($declaration['contexts']), 'declaration_hash' => $this->hash($declaration),
                'members_hash' => $this->hash($native['members']),
                'quote_provenance_hash' => $this->hash((array) data_get($request, 'mtf_snapshot_manifest.quote_spread_provenance', []))];
            $members = ['cheap' => ['agent_id' => (int) $cheap->id, 'model_id' => (int) $cheap->model_version_id],
                'deeper' => ['agent_id' => (int) $deeper->id, 'model_id' => (int) $deeper->model_version_id]];
            $physicalHash = $this->physicalQuestionHash($request, $native, $declaration);
            $auditId = $this->hash(['protocol' => self::PROTOCOL, 'generation_id' => $generation->id, 'members' => $members, 'identity' => $identity]);
            $journalKey = $this->hash([self::PROTOCOL, 'physical_question', $physicalHash]);
            DB::table('research_knowledge_entries')->insertOrIgnore(['knowledge_key' => $journalKey, 'knowledge_type' => 'PROCEDURAL',
                'subject_type' => self::class, 'subject_key' => $physicalHash, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
                'authority' => 'research_only', 'freshness' => 'active', 'status' => 'sealed',
                'scope' => $this->json(['protocol' => self::PROTOCOL, 'execution_timeframe' => 'M5']),
                'claim' => $this->json(['protocol' => self::PROTOCOL, 'criterion' => $declaration['criterion'], 'promotion_evidence' => false]),
                'evidence' => $this->json(['audit_id' => $auditId]), 'dependencies' => $this->json(['one_original_question' => true]),
                'recorded_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $journal = DB::table('research_knowledge_entries')->where('knowledge_key', $journalKey)->lockForUpdate()->first();
            if (! $journal || data_get(json_decode($journal->evidence, true), 'audit_id') !== $auditId) $this->refuse('PHYSICAL_QUESTION_ALREADY_RESERVED');
            $seal = ['protocol' => self::PROTOCOL, 'audit_id' => $auditId, 'generation_id' => (int) $generation->id,
                'physical_question_hash' => $physicalHash, 'identity' => $identity, 'declaration' => $declaration,
                'members' => $members, 'native_members' => $native['members'], 'base_request' => $request,
                'constructor_proof' => $proof, 'model_hashes' => [], 'source_hash' => $this->evidence->codeHash(),
                'economic_authority' => false, 'skill_authority' => false, 'independent_evidence' => false, 'promotion_evidence' => false];
            $sourceModelIds = array_column(array_values($proof['source_ids']), 'model_version_id');
            foreach (ModelVersion::whereIn('id', [...$sourceModelIds, ...$models->keys()->all()])->get() as $model) {
                $seal['model_hashes'][(string) $model->id] = app(SpecialistCouncilContractService::class)->modelHash($model);
            }
            $artifact = $this->evidence->recordArtifact(null, self::ARTIFACT, $seal,
                ['cheap_model_id' => $cheap->model_version_id, 'deeper_model_id' => $deeper->model_version_id,
                    'protocol' => self::PROTOCOL, 'promotion_evidence' => false], $cheap, $auditId);
            foreach ($members as $arm => $member) {
                $model = $models[$member['model_id']];
                $model->update(['metadata' => [...(array) $model->metadata, self::MARKER => ['protocol' => self::PROTOCOL,
                    'audit_id' => $auditId, 'arm' => $arm, 'artifact_hash' => $artifact->sha256]]]);
            }
            $generation->update(['trigger_context' => [...(array) $generation->trigger_context,
                self::MARKER => ['protocol' => self::PROTOCOL, 'audit_id' => $auditId, 'artifact_hash' => $artifact->sha256],
                self::PHASE => ['protocol' => self::PROTOCOL, 'phase' => 'cheap_pending', 'audit_id' => $auditId]]]);
            return ['protocol' => self::PROTOCOL, 'audit_id' => $auditId, 'artifact_hash' => $artifact->sha256,
                'status' => 'preregistered', 'promotion_evidence' => false];
        }, 1);
    }

    public function declares(ModelVersion $model): bool
    {
        return data_get($model->metadata, self::MARKER) !== null || LabEvidenceArtifact::where('artifact_type', self::ARTIFACT)
            ->where(fn ($query) => $query->where('metadata->cheap_model_id', $model->id)->orWhere('metadata->deeper_model_id', $model->id))->exists();
    }

    public function originalTransportRequest(LabEvaluationRun $run): array
    {
        return $this->originalPayload($run, 'evaluation_request');
    }

    public function bindRequest(array $request, array $models): array
    {
        foreach ($models as $model) {
            if (! $model instanceof ModelVersion || ! $this->declares($model)) continue;
            [$seal, $artifact, $arm] = $this->modelSeal($model);
            $selection = $arm === 'deeper' ? $this->readSelection($seal, $artifact) : null;
            if ($arm === 'deeper' && ($selection === null || $selection['selection']['selected_specialist_ids'] === [])) $this->refuse('DEEPER_BEFORE_ORIGINAL_CHEAP_SAMPLE');
            $contract = $this->contract($seal, $arm, $selection['selection'] ?? null);
            $probe = data_get($seal, 'base_request.policy_context.prospective_probe_window');
            $incoming = (array) data_get($request, 'policy_context.prospective_probe_window', []);
            $probeOwner = app(ProspectiveRepairProbeWindowService::class);
            if (! is_array($probe) || ! $probeOwner->attests($probe, [...$probe, 'complete' => true])
                || ! $probeOwner->attests($incoming, [...$incoming, 'complete' => true])
                || ! $this->same(array_diff_key($probe, ['experiment_key' => true, 'contract_hash' => true]),
                    array_diff_key($incoming, ['experiment_key' => true, 'contract_hash' => true]))) $this->refuse('ORIGINAL_FULL_PHYSICAL_PROBE_DRIFT');
            $request['policy_context']['prospective_probe_window'] = $probe;
            $request['policy_context']['prospective_clean_discovery_scope'] = data_get($seal, 'base_request.mtf_snapshot_manifest.discovery_scope');
            foreach (['symbol', 'timeframe', 'evaluation_mode', 'initial_balance', 'execution', 'execution_contract',
                'replay_dataset_hash', 'mtf_snapshot_manifest'] as $field) {
                if (isset($request[$field]) && ! $this->same($request[$field], $seal['base_request'][$field])) $this->refuse('ORIGINAL_SOURCE_OR_ACCOUNT_DRIFT');
                $request[$field] = $seal['base_request'][$field];
            }
            if (($request['dataset_tail_rows'] ?? null) !== null) $this->refuse('TAIL_SELECTOR_FORBIDDEN');
            $request['dataset_tail_rows'] = null;
            if (isset($request['strategies'])) {
                $matches = []; foreach ($request['strategies'] as $index => $strategy) {
                    if (($strategy['lab_agent_id'] ?? null) === $seal['members'][$arm]['agent_id']) $matches[] = $index;
                }
                if (count($matches) !== 1 || count($request['strategies']) !== 1) $this->refuse('SINGLE_ORIGINAL_CARRIER_REQUIRED');
                $request['strategies'][$matches[0]][self::FIELD] = $contract;
            } else $request[self::FIELD] = $contract;
        }
        return $request;
    }

    public function attestResult(ModelVersion $model, LabEvaluationRun $run, array $response): ?array
    {
        if (! $this->declares($model)) return null;
        [$seal, $artifact, $arm] = $this->modelSeal($model);
        if ((int) $run->model_version_id !== (int) $model->id || (int) $run->lab_agent_id !== $seal['members'][$arm]['agent_id']
            || (int) $run->lab_generation_id !== $seal['generation_id'] || $run->phase !== 'screening' || $run->mode !== 'incremental'
            || (int) $run->attempt !== 1 || ! $run->started_at || ! $artifact->recorded_at || $run->started_at->lessThan($artifact->recorded_at)) $this->refuse('ORIGINAL_RUN_OWNER_MISMATCH');
        $request = $this->originalPayload($run, 'evaluation_request');
        $strategy = $request;
        if (($request['dataset_tail_rows'] ?? null) !== null) $this->refuse('TAIL_SELECTOR_FORBIDDEN');
        if (isset($request['strategies'])) {
            $matches = array_values(array_filter($request['strategies'], fn ($row) => ($row['lab_agent_id'] ?? null) === $seal['members'][$arm]['agent_id']));
            if (count($matches) !== 1 || count($request['strategies']) !== 1) $this->refuse('ORIGINAL_REQUEST_OWNER_AMBIGUOUS');
            $strategy = [...array_diff_key($request, ['strategies' => true]), ...$matches[0]];
        }
        $selection = $arm === 'deeper' ? $this->readSelection($seal, $artifact) : null;
        $expected = $this->contract($seal, $arm, $selection['selection'] ?? null);
        if (! $this->same($strategy[self::FIELD] ?? [], $expected)) $this->refuse('ORIGINAL_SIGNED_VIEW_MISMATCH');
        $native = $this->councils->attestReplayResult($model, $request, $response);
        $receipt = (array) ($response['native_reachability_depth_audit_receipt'] ?? []);
        if (! $this->same($receipt, (array) data_get($response, 'data_quality.native_reachability_depth_audit_receipt', []))) $this->refuse('RECEIPT_COPY_MISMATCH');
        $this->sealed($receipt, 'receipt');
        foreach (['protocol' => self::RECEIPT, 'audit_id' => $seal['audit_id'], 'arm' => $arm,
            'contract_hash' => $expected['contract_hash'], 'identity_hash' => $expected['identity_hash'],
            'authority' => 'research_only', 'economic_authority' => false, 'skill_authority' => false,
            'independent_evidence' => false, 'promotion_evidence' => false, 'physical_source_rows' => 15512] as $field => $value) {
            if (($receipt[$field] ?? null) !== $value) $this->refuse('PRODUCER_IDENTITY_INVALID_'.$field);
        }
        if (! $this->same($receipt['declaration'] ?? [], $seal['declaration']) || ! $this->same($receipt['execution_view'] ?? [], $expected['execution_view'])
            || ! $this->same($receipt['selection'] ?? null, $expected['selection']) || ! $this->same($receipt['replay_executed_clock'] ?? [], $native['replay_executed_clock'] ?? [])
            || ($receipt['status'] ?? null) !== 'computed') $this->refuse('ACTUAL_VIEW_OR_CLOCK_REQUIRED');
        $this->assertClock($receipt, $expected);
        $this->assertPool($receipt, $seal, $arm, $selection['selection'] ?? null);
        app(NativeReachabilityDepthReceiptValidatorService::class)->validate($receipt, $seal, $expected);
        $this->assertObserverTraceJoin($receipt, $response);
        foreach (['observer_sha256' => 'ai-service-python/app/services/native_reachability_depth_audit.py',
            'native_producer_sha256' => 'ai-service-python/app/services/specialist_council.py'] as $field => $path) {
            if (($receipt['source_artifacts'][$field] ?? null) !== hash_file('sha256', dirname(base_path()).'/'.$path)) $this->refuse('PRODUCER_SOURCE_DRIFT');
        }
        foreach (['process_cpu_seconds', 'elapsed_seconds'] as $field) if ((! is_float($receipt['effort'][$field] ?? null) && ! is_int($receipt['effort'][$field] ?? null))
            || ! is_finite((float) $receipt['effort'][$field]) || $receipt['effort'][$field] < 0) $this->refuse('ACTUAL_EFFORT_REQUIRED');
        if (($receipt['effort']['executed_decision_rows'] ?? null) !== $expected['execution_view']['decision_rows']) $this->refuse('ACTUAL_EFFORT_ROWS_MISMATCH');
        return $receipt;
    }

    /** No queueing: the next existing arbiter/dispatcher lease owns the deeper phase. */
    public function settleOriginalPhase(LabEvaluationRun $run): ?array
    {
        $model = ModelVersion::find($run->model_version_id);
        if (! $model || ! $this->declares($model)) return null;
            [$seal, $artifact, $arm] = $this->modelSeal($model);
        return DB::transaction(function () use ($seal, $artifact, $arm): array {
            LabEvidenceArtifact::whereKey($artifact->id)->lockForUpdate()->firstOrFail();
            $prior = ResearchExperimentReceipt::where('source_type', self::class)->where('source_id', $artifact->id)->first();
            if ($prior) return ['status' => 'recorded', 'receipt_id' => $prior->id, 'classification' => $prior->classification, 'promotion_evidence' => false];
            $cheap = $this->terminalOriginalRun($seal, 'cheap');
            if ($cheap === null) return ['status' => 'awaiting_original_cheap', 'promotion_evidence' => false];
            if ($cheap->status !== 'completed') return $this->publish($seal, $artifact, ['status' => 'technical', 'reason' => 'ORIGINAL_CHEAP_TECHNICAL'], [$cheap]);
            try { $cheapReceipt = $this->attestResult(ModelVersion::findOrFail($seal['members']['cheap']['model_id']), $cheap, $this->originalPayload($cheap, 'evaluation_response')); }
            catch (\Throwable $error) { return $this->publish($seal, $artifact, ['status' => 'technical', 'reason' => 'ORIGINAL_CHEAP_EVIDENCE_INVALID'], [$cheap]); }
            $selection = $this->readSelection($seal, $artifact);
            if ($selection === null) $selection = $this->sealSample($seal, $artifact, $cheap, $cheapReceipt);
            if ($selection['selection']['selected_specialist_ids'] === []) {
                return $this->publish($seal, $artifact, ['status' => 'dependency', 'reason' => 'NO_POWERED_CHEAP_REJECTION_SAMPLE',
                    'false_negative_count' => null, 'whole_reject_pool_hash' => $selection['reject_pool_hash']], [$cheap]);
            }
            $deeper = $this->terminalOriginalRun($seal, 'deeper');
            if ($deeper === null) return ['status' => 'deeper_ready', 'audit_id' => $seal['audit_id'],
                'sample_hash' => $selection['selection']['sample_hash'], 'promotion_evidence' => false];
            if ($deeper->status !== 'completed') return $this->publish($seal, $artifact, ['status' => 'technical', 'reason' => 'ORIGINAL_DEEPER_TECHNICAL'], [$cheap, $deeper]);
            try { $deepReceipt = $this->attestResult(ModelVersion::findOrFail($seal['members']['deeper']['model_id']), $deeper, $this->originalPayload($deeper, 'evaluation_response')); }
            catch (\Throwable $error) { return $this->publish($seal, $artifact, ['status' => 'technical', 'reason' => 'ORIGINAL_DEEPER_EVIDENCE_INVALID'], [$cheap, $deeper]); }
            $missed = 0; $unknown = 0;
            foreach ($deepReceipt['pool'] as $entry) {
                if (! in_array($entry['specialist_id'], $selection['selection']['selected_specialist_ids'], true)) continue;
                if ($entry['status'] === 'reached') $missed++;
                elseif ($entry['status'] === 'dependency') $unknown++;
            }
            return $this->publish($seal, $artifact, ['status' => $unknown > 0 ? 'dependency' : 'measured_native_reachability_depth_audit',
                'criterion' => $seal['declaration']['criterion'], 'sample_count' => count($selection['selection']['selected_specialist_ids']),
                'false_negative_count' => $unknown > 0 ? null : $missed, 'unknown_sample_count' => $unknown,
                'whole_reject_pool_hash' => $selection['reject_pool_hash'], 'sample_hash' => $selection['selection']['sample_hash'],
                'cheap_effort' => $cheapReceipt['effort'], 'deeper_effort' => $deepReceipt['effort'],
                'population_rate_estimated' => false, 'market_value_proven' => false], [$cheap, $deeper]);
        }, 1);
    }

    public function continuationStatus(LabGeneration $generation): ?array
    {
        if (data_get($generation->trigger_context, self::MARKER) === null) return null;
        [$seal, $artifact] = $this->generationSeal($generation);
        $publication = ResearchExperimentReceipt::where('source_type', self::class)->where('source_id', $artifact->id)->first();
        if ($publication) return ['status' => 'terminal', 'receipt_id' => $publication->id, 'promotion_evidence' => false];
        $selection = $this->readSelection($seal, $artifact);
        $cheap = $this->terminalOriginalRun($seal, 'cheap');
        if (! $cheap) return ['status' => 'cheap_pending', 'promotion_evidence' => false];
        if ($selection === null || $selection['selection']['selected_specialist_ids'] === []) return ['status' => 'settle_only', 'promotion_evidence' => false];
        $deeper = LabAgent::findOrFail($seal['members']['deeper']['agent_id']);
        $owned = LabEvaluationRun::where('lab_agent_id', $deeper->id)->get();
        if ($owned->isNotEmpty() || in_array($deeper->lifecycle_status, ['queued', 'screening', 'training'], true)) return ['status' => 'deeper_in_flight', 'promotion_evidence' => false];
        if ($deeper->lifecycle_status !== 'draft') $this->refuse('DEEPER_OWNERSHIP_DRIFT');
        return ['status' => 'deeper_ready', 'generation_id' => (int) $generation->id, 'audit_id' => $seal['audit_id'],
            'sample_hash' => $selection['selection']['sample_hash'], 'checkpoint_hash' => $selection['artifact_hash'],
            'physical_question_hash' => $seal['physical_question_hash'],
            'cheap_run_id' => $cheap->run_id, 'cheap_request_hash' => $cheap->request_hash, 'cheap_response_hash' => $cheap->response_hash,
            'watermark' => $this->hash(['audit_id' => $seal['audit_id'], 'cheap_response_hash' => $cheap->response_hash,
                'sample_hash' => $selection['selection']['sample_hash']]),
            'dispatch_agent_ids' => [(int) $deeper->id], 'same_original_question' => true, 'promotion_evidence' => false];
    }

    /** Pure routing evidence; global mode/queue/lease guards stay with the arbiter. */
    public function inspectContinuation(LabGeneration $generation): array
    {
        if (data_get($generation->trigger_context, self::MARKER) === null) {
            return ['status' => data_get($generation->trigger_context, 'native_specialist_council_intent.research_purpose') === self::PURPOSE
                ? 'unprepared' : 'not_applicable', 'promotion_evidence' => false];
        }
        return $this->continuationStatus($generation);
    }

    public function dispatchAgentIds(LabGeneration $generation): ?array
    {
        if (data_get($generation->trigger_context, 'native_specialist_council_intent.research_purpose') !== self::PURPOSE) return null;
        [$seal] = $this->generationSeal($generation);
        $state = $this->continuationStatus($generation);
        return match ($state['status']) {
            'cheap_pending' => [$seal['members']['cheap']['agent_id']],
            'deeper_ready' => $state['dispatch_agent_ids'],
            'deeper_in_flight' => $this->originalQueuedDeeperPublicationIds($seal),
            default => [],
        };
    }

    /** Repair publication only: the original phase, programme and sample stay sealed. */
    private function originalQueuedDeeperPublicationIds(array $seal): array
    {
        $generationId = $seal['generation_id'] ?? null;
        $agentId = data_get($seal, 'members.deeper.agent_id');
        $modelId = data_get($seal, 'members.deeper.model_id');
        if (! is_int($generationId) || $generationId <= 0 || ! is_int($agentId) || $agentId <= 0
            || ! is_int($modelId) || $modelId <= 0) return [];
        $agent = LabAgent::find($agentId);
        if (! $agent || (int) $agent->lab_generation_id !== $generationId || (int) $agent->model_version_id !== $modelId
            || $agent->lifecycle_status !== 'queued' || LabEvaluationRun::where('lab_agent_id', $agentId)->exists()) return [];
        $queues = array_values(array_unique([
            (string) config('services.lab_queue.screening_queue', 'lab-screening'),
            (string) config('services.lab_queue.full_queue', 'lab-full-validation'),
            (string) config('services.lab_queue.full_validation_queue', 'lab-full-validation'),
        ]));
        if (in_array('', $queues, true)) return [];
        $inspector = app(LabQueueJobInspector::class);
        $snapshot = $inspector->queueSnapshot($queues);
        if (($snapshot['available'] ?? null) !== true || ! is_int($snapshot['total'] ?? null)
            || $snapshot['total'] !== 0 || $inspector->hasAgentJob($agentId, $queues) !== false) return [];
        // No status reset and no second evaluation: the canonical stranded
        // queued owner must still pass lease, release and singleton admission.
        return [$agentId];
    }

    public function reconcileGeneration(LabGeneration $generation): ?array
    {
        if (data_get($generation->trigger_context, self::MARKER) === null) return null;
        [$seal] = $this->generationSeal($generation);
        $cheap = $this->terminalOriginalRun($seal, 'cheap');
        if (! $cheap) return ['status' => 'awaiting_original_cheap', 'promotion_evidence' => false];
        return $this->settleOriginalPhase($cheap);
    }

    private function sealSample(array $seal, LabEvidenceArtifact $original, LabEvaluationRun $cheap, array $receipt): array
    {
        $rejected = array_values(array_filter($receipt['pool'], fn ($entry) => $entry['status'] === 'rejected'));
        $ranked = $rejected;
        usort($ranked, fn ($left, $right) => strcmp(hash('sha256', $seal['declaration']['seed'].'|'.$seal['physical_question_hash'].'|'.$left['member_version_hash']),
            hash('sha256', $seal['declaration']['seed'].'|'.$seal['physical_question_hash'].'|'.$right['member_version_hash'])));
        $selected = array_slice($ranked, 0, $seal['declaration']['sample_cap']);
        $selection = ['protocol' => 'native_reachability_reject_sample_v1', 'cheap_run_id' => (int) $cheap->id,
            'cheap_response_hash' => $cheap->response_hash, 'cheap_receipt_hash' => $receipt['receipt_hash'],
            'pool_hash' => $receipt['pool_hash'], 'selected_specialist_ids' => array_column($selected, 'specialist_id'),
            'selected_member_hashes' => array_column($selected, 'member_version_hash')];
        $selection['sample_hash'] = $this->hash($selection);
        $body = ['protocol' => self::PROTOCOL, 'audit_id' => $seal['audit_id'], 'preregistration_hash' => $original->sha256,
            'cheap_run_uuid' => $cheap->run_id, 'cheap_request_hash' => $cheap->request_hash, 'selection' => $selection,
            'whole_pool' => $receipt['pool'], 'reject_pool' => $rejected, 'reject_pool_hash' => $this->hash($rejected),
            'seed_hash' => hash('sha256', $seal['declaration']['seed']), 'economic_authority' => false, 'promotion_evidence' => false];
        $artifact = $this->evidence->recordArtifact(null, self::SAMPLE_ARTIFACT, $body,
            ['preregistration_id' => $original->id, 'promotion_evidence' => false], LabAgent::findOrFail($seal['members']['cheap']['agent_id']), $seal['audit_id']);
        $generation = LabGeneration::findOrFail($seal['generation_id']);
        $generation->update(['trigger_context' => [...(array) $generation->trigger_context, self::PHASE => [
            'protocol' => self::PROTOCOL, 'audit_id' => $seal['audit_id'], 'phase' => $selected === [] ? 'settle_only' : 'deeper_ready',
            'sample_artifact_hash' => $artifact->sha256, 'sample_hash' => $selection['sample_hash']]]]);
        return [...$body, 'artifact_hash' => $artifact->sha256];
    }

    private function readSelection(array $seal, LabEvidenceArtifact $original): ?array
    {
        $rows = LabEvidenceArtifact::where('artifact_type', self::SAMPLE_ARTIFACT)->where('run_id', $seal['audit_id'])->get();
        if ($rows->isEmpty()) return null;
        if ($rows->count() !== 1) $this->refuse('DUPLICATE_SAMPLE_FORBIDDEN');
        $artifact = $rows->first(); $body = $this->readModernArtifact($artifact);
        $cheap = LabEvaluationRun::find($body['selection']['cheap_run_id'] ?? 0);
        if (! $cheap || $cheap->status !== 'completed' || $cheap->response_hash !== ($body['selection']['cheap_response_hash'] ?? null)
            || (int) $cheap->lab_agent_id !== $seal['members']['cheap']['agent_id'] || ($body['cheap_run_uuid'] ?? null) !== $cheap->run_id
            || ($body['cheap_request_hash'] ?? null) !== $cheap->request_hash || ($body['audit_id'] ?? null) !== $seal['audit_id']
            || ($body['preregistration_hash'] ?? null) !== $original->sha256
            || $this->hash($body['whole_pool'] ?? []) !== ($body['selection']['pool_hash'] ?? null)
            || $this->hash($body['reject_pool'] ?? []) !== ($body['reject_pool_hash'] ?? null)
            || $this->hash(array_diff_key($body['selection'] ?? [], ['sample_hash' => true])) !== ($body['selection']['sample_hash'] ?? null)) $this->refuse('ORIGINAL_SAMPLE_DRIFT');
        // Re-open original producer bytes. Matching copied hashes alone cannot
        // establish that this was the original pool or deterministic sample.
        $receipt = $this->attestResult(ModelVersion::findOrFail($seal['members']['cheap']['model_id']), $cheap,
            $this->originalPayload($cheap, 'evaluation_response'));
        $rejected = array_values(array_filter($receipt['pool'], fn ($entry) => $entry['status'] === 'rejected'));
        $ranked = $rejected;
        usort($ranked, fn ($left, $right) => strcmp(hash('sha256', $seal['declaration']['seed'].'|'.$seal['physical_question_hash'].'|'.$left['member_version_hash']),
            hash('sha256', $seal['declaration']['seed'].'|'.$seal['physical_question_hash'].'|'.$right['member_version_hash'])));
        $selected = array_slice($ranked, 0, $seal['declaration']['sample_cap']);
        if (! $this->same($body['whole_pool'], $receipt['pool']) || ! $this->same($body['reject_pool'], $rejected)
            || ($body['selection']['cheap_receipt_hash'] ?? null) !== $receipt['receipt_hash']
            || ($body['seed_hash'] ?? null) !== hash('sha256', $seal['declaration']['seed'])
            || ($body['selection']['selected_specialist_ids'] ?? null) !== array_column($selected, 'specialist_id')
            || ($body['selection']['selected_member_hashes'] ?? null) !== array_column($selected, 'member_version_hash')
            || ! $artifact->created_at || ! $cheap->finished_at || $artifact->created_at->lessThan($cheap->finished_at)) $this->refuse('ORIGINAL_POOL_OR_RANKING_DRIFT');
        return [...$body, 'artifact_hash' => $artifact->sha256];
    }

    private function contract(array $seal, string $arm, ?array $selection): array
    {
        $rows = $seal['declaration'][$arm === 'cheap' ? 'cheap_evaluated_rows' : 'deeper_evaluated_rows'];
        $body = ['protocol' => self::PROTOCOL, 'audit_id' => $seal['audit_id'], 'arm' => $arm, 'identity' => $seal['identity'],
            'identity_hash' => $this->hash($seal['identity']), 'declaration' => $seal['declaration'],
            'execution_view' => ['protocol' => 'native_reachability_execution_view_v1', 'selection' => 'prefix', 'evaluated_rows' => $rows,
                'decision_rows' => $rows - 1, 'warmup_rows' => 512, 'source_evaluated_rows' => 15000, 'source_loaded_rows' => 15512],
            'selection' => $selection, 'authority' => 'research_only', 'economic_authority' => false, 'skill_authority' => false,
            'independent_evidence' => false, 'promotion_evidence' => false];
        $hash = $this->hash($body);
        return [...$body, 'contract_hash' => $hash, 'contract_json' => $this->json($body),
            'server_seal' => ['protocol' => 'native_reachability_depth_audit_server_seal_v1',
                'hmac_sha256' => hash_hmac('sha256', self::PROTOCOL."\n".$hash, $this->key())]];
    }

    private function assertClock(array $receipt, array $contract): void
    {
        $clock = $receipt['replay_executed_clock']; $this->sealed($clock, 'receipt');
        $view = $contract['execution_view'];
        foreach (['owner' => 'native_specialist_council_v1', 'input_rows' => 512 + $view['evaluated_rows'],
            'evaluation_offset_rows' => 512, 'decision_rows' => $view['decision_rows'], 'first_evaluation_index' => 1,
            'last_evaluation_index' => $view['decision_rows'], 'complete' => true, 'policy_hash' => $this->hash($view),
            'execution_timeframe' => 'M5', 'duration_seconds' => 300, 'dataset_hash' => $contract['identity']['dataset_hash'],
            'execution_hash' => $contract['identity']['execution_hash']] as $field => $value) if (($clock[$field] ?? null) !== $value) $this->refuse('EXECUTED_VIEW_CLOCK_INVALID_'.$field);
        if (($receipt['execution_input_rows'] ?? null) !== 512 + $view['evaluated_rows']
            || ($receipt['evaluated_scope']['rows'] ?? null) !== $view['evaluated_rows']
            || ($receipt['evaluated_scope']['decision_rows'] ?? null) !== $view['decision_rows']) $this->refuse('EXECUTED_VIEW_ROWS_INVALID');
    }

    private function assertPool(array $receipt, array $seal, string $arm, ?array $selection): void
    {
        $pool = $receipt['pool'] ?? null; $events = $receipt['events'] ?? null;
        if (! is_array($pool) || ! array_is_list($pool) || count($pool) !== 4 || $this->hash($pool) !== ($receipt['pool_hash'] ?? null)
            || ! is_array($events) || ! array_is_list($events) || count($events) > 60000 || $this->hash($events) !== ($receipt['events_hash'] ?? null)) $this->refuse('ACTUAL_POOL_LEDGER_REQUIRED');
        $members = []; foreach ($seal['native_members'] as $member) $members[$member['specialist_id']] = $this->hash([
            'council_version' => data_get($seal, 'base_request.specialist_council_contract.council_version'), 'member' => $member]);
        $seen = [];
        foreach ($pool as $entry) {
            $id = $entry['specialist_id'] ?? '';
            if (! isset($members[$id]) || isset($seen[$id]) || ($entry['member_version_hash'] ?? null) !== $members[$id]
                || ! in_array($entry['status'] ?? null, ['reached', 'rejected', 'dependency', 'not_selected'], true)) $this->refuse('POOL_MEMBER_OR_STATUS_INVALID');
            $seen[$id] = true; $counts = $entry['counts'] ?? [];
            foreach (['raw_opportunities', 'matching_context_opportunities', 'observed_quote_opportunities', 'missing_quote_opportunities', 'gate_reached_opportunities'] as $key) {
                if (! is_int($counts[$key] ?? null) || $counts[$key] < 0 || $counts[$key] > $receipt['execution_view']['decision_rows']) $this->refuse('ACTUAL_POOL_COUNTS_REQUIRED');
            }
            $observed = $arm === 'cheap' || in_array($id, $selection['selected_specialist_ids'] ?? [], true);
            if (($entry['observed'] ?? null) !== $observed || (! $observed && $entry['status'] !== 'not_selected')) $this->refuse('POOL_SELECTION_DRIFT');
            $reasons = $entry['dependency_reasons'] ?? null;
            if (! is_array($reasons) || ! array_is_list($reasons) || count($reasons) > 32
                || count(array_unique($reasons)) !== count($reasons)) $this->refuse('POOL_DEPENDENCY_REASONS_INVALID');
            foreach ($reasons as $reason) if (! is_string($reason) || strlen($reason) > 160) $this->refuse('POOL_DEPENDENCY_REASONS_INVALID');
            $dependent = $counts['raw_opportunities'] === 0 || $counts['matching_context_opportunities'] === 0
                || $counts['missing_quote_opportunities'] !== 0 || $counts['observed_quote_opportunities'] < $seal['declaration']['minimum_observed_opportunities'];
            if ($observed && (($dependent && $entry['status'] !== 'dependency')
                || (! $dependent && $reasons === [] && $entry['status'] !== ($counts['gate_reached_opportunities'] > 0 ? 'reached' : 'rejected'))
                || ($entry['status'] === 'dependency' && $reasons === [])
                || ($entry['status'] !== 'dependency' && $reasons !== []))) $this->refuse('POOL_STATUS_COUNTER_DRIFT');
            if (! $observed && ($reasons !== [] || array_sum($counts) !== 0)) $this->refuse('UNSELECTED_MEMBER_OBSERVATION_FORBIDDEN');
            if ($entry['status'] === 'rejected' && ($counts['observed_quote_opportunities'] < $seal['declaration']['minimum_observed_opportunities']
                || $counts['gate_reached_opportunities'] !== 0 || $counts['missing_quote_opportunities'] !== 0)) $this->refuse('DEPENDENCY_CANNOT_BE_REJECTION');
        }
    }

    private function nativeRequest(ModelVersion $model, array $request): array
    {
        $dataset = (string) ($request['replay_dataset_hash'] ?? '');
        $runtime = $this->councils->runtimeContractForModel($model, 'M5', $dataset,
            (string) data_get($request, 'execution_contract.execution_hash'), ['bundle_hash' => $dataset, 'manifest' => $request['mtf_snapshot_manifest']], 'XAUUSD');
        if (! $this->same($request['specialist_council_contract'] ?? [], $runtime)) $this->refuse('EXACT_NATIVE_PROGRAM_REQUIRED');
        return $runtime;
    }

    private function generationSeal(LabGeneration $generation): array
    {
        $marker = (array) data_get($generation->trigger_context, self::MARKER, []);
        $rows = LabEvidenceArtifact::where('artifact_type', self::ARTIFACT)->where('run_id', $marker['audit_id'] ?? '')->get();
        if ($rows->count() !== 1) $this->refuse('ORIGINAL_PREREGISTRATION_REQUIRED');
        $artifact = $rows->first(); $seal = $this->readModernArtifact($artifact);
        if (($marker['protocol'] ?? null) !== self::PROTOCOL || ($marker['artifact_hash'] ?? null) !== $artifact->sha256
            || ($seal['generation_id'] ?? null) !== (int) $generation->id || ($seal['source_hash'] ?? null) !== $this->evidence->codeHash()) $this->refuse('ORIGINAL_SOURCE_OR_GENERATION_DRIFT');
        $intent = (array) data_get($generation->trigger_context, 'native_specialist_council_intent', []);
        if (($intent['research_purpose'] ?? null) !== self::PURPOSE || ($intent['intent_hash'] ?? null) !== $seal['constructor_proof']['intent_hash']
            || ! $this->same($intent['depth_audit_declaration'] ?? [], $seal['declaration'])
            || $this->hash(array_diff_key($intent, ['intent_hash' => true])) !== $intent['intent_hash']) $this->refuse('ORIGINAL_INTENT_DRIFT');
        if (count($seal['model_hashes'] ?? []) !== 6 || count($seal['constructor_proof']['constructor_episode_ids'] ?? []) !== 6) $this->refuse('ORIGINAL_SIX_MODEL_PROOF_REQUIRED');
        foreach ($seal['constructor_proof']['constructor_episode_ids'] as $witness) {
            $agent = LabAgent::find($witness['agent_id']); $model = $agent?->modelVersion;
            if (! $model || (int) $agent->lab_generation_id !== (int) $generation->id || (int) $model->id !== $witness['model_id']
                || ($seal['model_hashes'][(string) $model->id] ?? null) !== app(SpecialistCouncilContractService::class)->modelHash($model)) $this->refuse('ORIGINAL_SIX_MODEL_DRIFT');
        }
        return [$seal, $artifact];
    }

    private function modelSeal(ModelVersion $model): array
    {
        $marker = (array) data_get($model->metadata, self::MARKER, []);
        $agent = LabAgent::where('model_version_id', $model->id)->firstOrFail();
        [$seal, $artifact] = $this->generationSeal($agent->generation);
        $arm = $marker['arm'] ?? '';
        if (! in_array($arm, ['cheap', 'deeper'], true) || ($marker['audit_id'] ?? null) !== $seal['audit_id']
            || ($marker['artifact_hash'] ?? null) !== $artifact->sha256 || ($marker['protocol'] ?? null) !== self::PROTOCOL
            || $seal['members'][$arm]['model_id'] !== (int) $model->id
            || ($seal['model_hashes'][(string) $model->id] ?? null) !== app(SpecialistCouncilContractService::class)->modelHash($model)) $this->refuse('ORIGINAL_MODEL_BINDING_DRIFT');
        return [$seal, $artifact, $arm];
    }

    private function terminalOriginalRun(array $seal, string $arm, bool $requireProjection = true): ?LabEvaluationRun
    {
        $rows = LabEvaluationRun::where('lab_agent_id', $seal['members'][$arm]['agent_id'])->get();
        if ($rows->isEmpty() || $rows->contains(fn ($run) => ! $this->evidence->isTerminalRun($run))) return null;
        if ($rows->count() !== 1) $this->refuse('ORIGINAL_PHASE_MUST_NOT_REPLAY');
        $run = $rows->first();
        if ((int) $run->model_version_id !== $seal['members'][$arm]['model_id'] || (int) $run->lab_generation_id !== $seal['generation_id']
            || $run->phase !== 'screening' || $run->mode !== 'incremental' || (int) $run->attempt !== 1
            || ! $run->finished_at || ! $run->started_at || $run->finished_at->lessThan($run->started_at)
            || ! $this->sha($run->response_hash)) $this->refuse('ORIGINAL_TERMINAL_SOURCE_REQUIRED');
        $response = $this->originalPayload($run, 'evaluation_response');
        if (array_key_exists('terminal_replay_envelope', $response)
            && data_get($response, 'terminal_replay_envelope.status') !== $run->status) $this->refuse('ORIGINAL_TERMINAL_ENVELOPE_INVALID');
        if ($run->request_hash !== null) $this->originalPayload($run, 'evaluation_request', $requireProjection);
        return $run;
    }

    private function originalPayload(LabEvaluationRun $run, string $type, bool $requireProjection = true): array
    {
        $rows = LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', $type)->get();
        if ($rows->count() !== 1 || ($type === 'evaluation_response' && $rows->first()->sha256 !== $run->response_hash)
            || ! $rows->first()->created_at || ($run->finished_at && $rows->first()->created_at->greaterThan($run->finished_at))) $this->refuse('EXACT_ORIGINAL_ARTIFACT_REQUIRED');
        $artifact = $rows->first(); $payload = $this->readModernArtifact($artifact);
        if ($type === 'evaluation_request' && data_get($artifact->metadata, 'request_hash') !== $run->request_hash) $this->refuse('ORIGINAL_REQUEST_HASH_DRIFT');
        if ($type === 'evaluation_request' && $requireProjection && (data_get($run->request_meta, 'request_hash') !== $run->request_hash
            || ! $this->same(data_get($run->request_meta, 'payload'), $payload)
            || data_get($artifact->metadata, 'raw_payload_hash') !== data_get($run->request_meta, 'payload_hash'))) $this->refuse('ORIGINAL_REQUEST_PROJECTION_DRIFT');
        return $payload;
    }

    private function publish(array $seal, LabEvidenceArtifact $artifact, array $outcome, array $runs): array
    {
        $classification = $outcome['status'] === 'technical' ? 'TECHNICAL_QUARANTINE' : 'INCONCLUSIVE';
        $publication = app(ResearchExperimentConversionKernelService::class)->record([
            'contract_version' => ResearchExperimentConversionKernelService::CONTRACT_VERSION, 'source' => ['type' => self::class, 'id' => $artifact->id],
            'scope' => ['symbol' => 'XAUUSD', 'laboratory_timeframe' => 'H1', 'execution_timeframe' => 'M5', 'contexts' => $seal['declaration']['contexts']],
            'claim' => ['target_stage' => 'decision_context', 'hypothesis' => 'instrument_gate_reached_depth_audit'],
            'identity' => ['baseline_epoch_hash' => $seal['identity']['native_contract_hash'], 'data_and_mtf_hash' => $seal['identity']['dataset_hash'],
                'runtime_and_contract_hash' => $seal['identity']['execution_hash'], 'intervention_hash' => $seal['identity']['declaration_hash'],
                'window_plan_hash' => $seal['identity']['evaluation_policy_hash'], 'evaluator_version' => self::PROTOCOL],
            'arms' => array_map(fn ($arm, $member) => ['role' => $arm, 'agent_id' => $member['agent_id']], array_keys($seal['members']), array_values($seal['members']))],
            ['audit_id' => $seal['audit_id'], 'preregistration_artifact_hash' => $artifact->sha256,
                'original_runs' => array_map(fn ($run) => ['run_id' => $run->run_id, 'request_hash' => $run->request_hash, 'response_hash' => $run->response_hash], $runs),
                'outcome' => $outcome, 'economic_authority' => false, 'skill_authority' => false, 'independent_evidence' => false, 'promotion_evidence' => false],
            $classification, [], ['code' => strtoupper($outcome['status']), 'no_credit_or_economic_claim' => true]);
        $this->closeReferences($seal, $artifact, $publication);
        return $publication;
    }

    /** Pure consumption of this owner's actual published witness, not a legacy verdict label. */
    public function publishedWitness(ResearchExperimentReceipt $receipt): ?array
    {
        if ($receipt->source_type !== self::class) return null;
        $publishedContract = (array) data_get($receipt->payload, 'contract', []);
        $publishedEvidence = (array) data_get($receipt->payload, 'evidence', []);
        $contractHash = hash('sha256', $this->json($publishedContract)); $evidenceHash = hash('sha256', $this->json($publishedEvidence));
        $receiptKey = hash('sha256', implode('|', [ResearchExperimentConversionKernelService::PROTOCOL,
            data_get($publishedContract, 'source.type'), data_get($publishedContract, 'source.id', 'none'), $contractHash, $evidenceHash]));
        if ($receipt->contract_hash !== $contractHash || $receipt->evidence_hash !== $evidenceHash || $receipt->receipt_key !== $receiptKey) $this->refuse('PUBLISHED_ORIGINAL_HASH_DRIFT');
        $artifact = LabEvidenceArtifact::find($receipt->source_id);
        if (! $artifact || $artifact->artifact_type !== self::ARTIFACT) $this->refuse('PUBLISHED_ORIGINAL_SOURCE_REQUIRED');
        $seal = $this->readModernArtifact($artifact);
        $arms = array_map(fn ($arm, $member) => ['role' => $arm, 'agent_id' => $member['agent_id']], array_keys($seal['members']), array_values($seal['members']));
        if (! $this->same($publishedContract['arms'] ?? [], $arms)
            || data_get($publishedContract, 'scope.symbol') !== 'XAUUSD' || data_get($publishedContract, 'scope.execution_timeframe') !== 'M5'
            || ! $this->same(data_get($publishedContract, 'scope.contexts'), $seal['declaration']['contexts'])
            || data_get($publishedContract, 'identity.baseline_epoch_hash') !== $seal['identity']['native_contract_hash']
            || data_get($publishedContract, 'identity.data_and_mtf_hash') !== $seal['identity']['dataset_hash']
            || data_get($publishedContract, 'identity.runtime_and_contract_hash') !== $seal['identity']['execution_hash']) $this->refuse('PUBLISHED_ORIGINAL_SCOPE_DRIFT');
        if (($seal['source_hash'] ?? null) !== $this->evidence->codeHash()) return $this->historicalPublishedWitness($receipt, $artifact, $seal);
        [$verified, $original] = $this->generationSeal(LabGeneration::findOrFail($seal['generation_id']));
        if ($original->id !== $artifact->id || data_get($receipt->payload, 'evidence.audit_id') !== $verified['audit_id']
            || data_get($receipt->payload, 'evidence.preregistration_artifact_hash') !== $artifact->sha256
            || ! in_array($receipt->classification, ['INCONCLUSIVE', 'TECHNICAL_QUARANTINE'], true)) $this->refuse('PUBLISHED_WITNESS_IDENTITY_DRIFT');
        foreach (['economic_authority', 'skill_authority', 'independent_evidence', 'promotion_evidence'] as $flag) {
            if (data_get($receipt->payload, 'evidence.'.$flag) !== false) $this->refuse('PUBLISHED_AUTHORITY_FORBIDDEN');
        }
        $cheap = $this->terminalOriginalRun($seal, 'cheap');
        if (! $cheap) $this->refuse('PUBLISHED_CHEAP_SOURCE_REQUIRED');
        $outcome = (array) data_get($receipt->payload, 'evidence.outcome', []);
        if (($outcome['status'] ?? null) === 'measured_native_reachability_depth_audit') {
            $sample = $this->readSelection($seal, $artifact);
            $deeper = $this->terminalOriginalRun($seal, 'deeper');
            if (! $sample || ! $deeper || $deeper->status !== 'completed') $this->refuse('PUBLISHED_DEEPER_SOURCE_REQUIRED');
            $deep = $this->attestResult(ModelVersion::findOrFail($seal['members']['deeper']['model_id']), $deeper, $this->originalPayload($deeper, 'evaluation_response'));
            $missed = 0; $unknown = 0;
            foreach ($deep['pool'] as $entry) if (in_array($entry['specialist_id'], $sample['selection']['selected_specialist_ids'], true)) {
                $missed += (int) ($entry['status'] === 'reached'); $unknown += (int) ($entry['status'] === 'dependency');
            }
            if ($unknown !== 0 || ($outcome['false_negative_count'] ?? null) !== $missed
                || ($outcome['sample_count'] ?? null) !== count($sample['selection']['selected_specialist_ids'])
                || ($outcome['sample_hash'] ?? null) !== $sample['selection']['sample_hash']) $this->refuse('PUBLISHED_MEASUREMENT_DRIFT');
        }
        return ['protocol' => self::PROTOCOL, 'status' => $outcome['status'] ?? 'dependency', 'receipt_key' => $receipt->receipt_key,
            'evidence_hash' => $receipt->evidence_hash, 'audit_id' => $seal['audit_id'], 'physical_question_hash' => $seal['physical_question_hash'],
            'criterion' => $seal['declaration']['criterion'], 'outcome' => $outcome, 'authority' => 'research_only',
            'economic_authority' => false, 'skill_authority' => false, 'independent_evidence' => false, 'promotion_evidence' => false];
    }

    /** Re-use original archived diagnostics, never admit/dispatch an old phase under new code. */
    private function historicalPublishedWitness(ResearchExperimentReceipt $publication, LabEvidenceArtifact $artifact, array $seal): array
    {
        $facts = (array) data_get($publication->payload, 'evidence', []);
        $contract = (array) data_get($publication->payload, 'contract', []);
        $outcome = (array) ($facts['outcome'] ?? []);
        $status = $outcome['status'] ?? '';
        if (($seal['protocol'] ?? null) !== self::PROTOCOL || $artifact->run_id !== ($seal['audit_id'] ?? null)
            || ($facts['audit_id'] ?? null) !== $seal['audit_id'] || ($facts['preregistration_artifact_hash'] ?? null) !== $artifact->sha256
            || data_get($contract, 'source.type') !== self::class || (int) data_get($contract, 'source.id') !== (int) $artifact->id
            || $publication->classification !== ($status === 'technical' ? 'TECHNICAL_QUARANTINE' : 'INCONCLUSIVE')
            || ! in_array($status, ['technical', 'dependency', 'measured_native_reachability_depth_audit'], true)) $this->refuse('HISTORICAL_PUBLICATION_IDENTITY_INVALID');
        foreach (['economic_authority', 'skill_authority', 'independent_evidence', 'promotion_evidence'] as $flag) {
            if (($facts[$flag] ?? null) !== false) $this->refuse('HISTORICAL_AUTHORITY_FORBIDDEN');
        }
        $runs = []; $receipts = []; $requests = []; $sourceManifest = null;
        foreach (['cheap', 'deeper'] as $arm) {
            $run = $this->terminalOriginalRun($seal, $arm, false);
            if ($run === null) {
                if ($arm === 'cheap' || $status === 'measured_native_reachability_depth_audit') $this->refuse('HISTORICAL_ORIGINAL_RUN_REQUIRED');
                continue;
            }
            $reference = collect((array) ($facts['original_runs'] ?? []))->firstWhere('run_id', $run->run_id);
            if (! $reference || ($reference['request_hash'] ?? null) !== $run->request_hash || ($reference['response_hash'] ?? null) !== $run->response_hash
                || $run->code_hash !== $seal['source_hash'] || ! $artifact->recorded_at || $run->started_at->lessThan($artifact->recorded_at)
                || ! $publication->created_at || $publication->created_at->lessThan($run->finished_at)) $this->refuse('HISTORICAL_ORIGINAL_RUN_HASH_OR_TIME_INVALID');
            $request = $this->originalPayload($run, 'evaluation_request', false);
            $release = (array) ($request['research_release'] ?? []);
            $reference = (array) ($release['source_artifact'] ?? []);
            $archive = app(ResearchReleaseSealService::class)->verifySourceArtifact($reference, false);
            $manifest = (array) ($archive['manifest'] ?? []);
            if (($archive['status'] ?? null) !== 'verified' || data_get($manifest, 'source_identity.source_hash') !== $seal['source_hash']
                || ($release['source_hash'] ?? null) !== $seal['source_hash']
                || data_get($manifest, 'source_identity.python_source_hash') !== ($release['python_source_hash'] ?? null)
                || ($release['dataset_hash'] ?? null) !== $seal['identity']['dataset_hash']
                || ($release['release_hash'] ?? null) !== app(ExecutionContractService::class)->hashParameters(array_diff_key($release,
                    ['release_hash' => true, 'sealed_at' => true, 'promotion_evidence' => true]))) $this->refuse('HISTORICAL_ARCHIVED_RELEASE_REQUIRED');
            if ($sourceManifest !== null && ($archive['artifact_hash'] ?? null) !== $sourceManifest['artifact_hash']) $this->refuse('HISTORICAL_RELEASE_PAIR_MISMATCH');
            $sourceManifest = ['artifact_hash' => $archive['artifact_hash'], 'files' => $manifest['files']];
            $response = $this->originalPayload($run, 'evaluation_response');
            if ($run->status === 'completed') {
                if (! app(ResearchReleaseSealService::class)->responseValid($release, (array) data_get($response, 'data_quality.research_release_receipt', []))) $this->refuse('HISTORICAL_WORKER_RELEASE_INVALID');
                $strategy = $this->originalStrategy($request, $seal['members'][$arm]['agent_id']);
                $signed = (array) ($strategy[self::FIELD] ?? []);
                $signedBody = $this->originalContractBody($signed);
                $nativeContract = (array) ($strategy['specialist_council_contract'] ?? []);
                $nativeBody = $this->originalContractBody($nativeContract);
                $native = (array) ($response['specialist_council_receipt'] ?? []);
                $depth = (array) ($response['native_reachability_depth_audit_receipt'] ?? []);
                $this->sealed($native, 'receipt'); $this->sealed($depth, 'receipt');
                if (($nativeContract['contract_hash'] ?? null) !== $seal['identity']['native_contract_hash']
                    || ($native['contract_hash'] ?? null) !== $nativeContract['contract_hash'] || ($native['status'] ?? null) !== 'computed'
                    || ! $this->same($nativeBody['members'] ?? [], $seal['native_members'])
                    || ! $this->evidence->nativeDecisionTraceHashValid($response, $native)
                    || ! $this->same($native, data_get($response, 'data_quality.specialist_council_receipt'))
                    || ! $this->same($depth, data_get($response, 'data_quality.native_reachability_depth_audit_receipt'))
                    || ! $this->same($signedBody['identity'] ?? [], $seal['identity']) || ! $this->same($signedBody['declaration'] ?? [], $seal['declaration'])
                    || ($signedBody['protocol'] ?? null) !== self::PROTOCOL || ($signedBody['audit_id'] ?? null) !== $seal['audit_id']
                    || ($signedBody['arm'] ?? null) !== $arm || ($depth['protocol'] ?? null) !== self::RECEIPT || ($depth['status'] ?? null) !== 'computed'
                    || ($depth['audit_id'] ?? null) !== $seal['audit_id'] || ($depth['arm'] ?? null) !== $arm
                    || ($depth['contract_hash'] ?? null) !== $signed['contract_hash'] || ($depth['identity_hash'] ?? null) !== $this->hash($seal['identity'])
                    || ! $this->same($depth['declaration'] ?? [], $seal['declaration']) || ! $this->same($depth['execution_view'] ?? [], $signedBody['execution_view'] ?? [])
                    || ! $this->same($depth['selection'] ?? null, $signedBody['selection'] ?? null)
                    || ! $this->same($depth['replay_executed_clock'] ?? [], $native['replay_executed_clock'] ?? [])) $this->refuse('HISTORICAL_ORIGINAL_NATIVE_PRODUCER_INVALID');
                foreach (['economic_authority', 'skill_authority', 'independent_evidence', 'promotion_evidence'] as $flag) if (($depth[$flag] ?? null) !== false) $this->refuse('HISTORICAL_AUTHORITY_FORBIDDEN');
                foreach (['observer_sha256' => 'ai-service-python/app/services/native_reachability_depth_audit.py',
                    'native_producer_sha256' => 'ai-service-python/app/services/specialist_council.py'] as $field => $path) {
                    if (($depth['source_artifacts'][$field] ?? null) !== ($manifest['files'][$path]['sha256'] ?? null)) $this->refuse('HISTORICAL_ARCHIVED_PRODUCER_SOURCE_INVALID');
                }
                $this->assertClock($depth, $signedBody); $this->assertPool($depth, $seal, $arm, $signedBody['selection']);
                app(NativeReachabilityDepthReceiptValidatorService::class)->validate($depth, $seal, $signedBody);
                $this->assertObserverTraceJoin($depth, $response);
                $receipts[$arm] = $depth;
            }
            $runs[$arm] = $run; $requests[$arm] = $request;
        }
        if (count($facts['original_runs'] ?? []) !== count($runs)) $this->refuse('HISTORICAL_ORIGINAL_RUN_SET_INVALID');
        if (($outcome['reason'] ?? null) === 'NO_POWERED_CHEAP_REJECTION_SAMPLE') {
            if (! isset($receipts['cheap']) || isset($runs['deeper'])) $this->refuse('HISTORICAL_EMPTY_REJECTION_SOURCE_INVALID');
            $sample = $this->historicalSample($seal, $artifact, $runs['cheap'], $receipts['cheap']);
            if ($sample['selection']['selected_specialist_ids'] !== [] || ($outcome['false_negative_count'] ?? null) !== null
                || ($outcome['whole_reject_pool_hash'] ?? null) !== $sample['reject_pool_hash']) $this->refuse('HISTORICAL_EMPTY_REJECTION_SOURCE_INVALID');
        }
        if ($status === 'measured_native_reachability_depth_audit') {
            if (count($receipts) !== 2) $this->refuse('HISTORICAL_COMPLETE_PAIR_REQUIRED');
            $sample = $this->historicalSample($seal, $artifact, $runs['cheap'], $receipts['cheap']);
            if (! $this->same($receipts['deeper']['selection'], $sample['selection'])
                || $runs['deeper']->started_at->lessThan($sample['recorded_at'])) $this->refuse('HISTORICAL_DEEPER_PRECEDES_SAMPLE');
            $missed = 0; $unknown = 0;
            foreach ($receipts['deeper']['pool'] as $entry) if (in_array($entry['specialist_id'], $sample['selection']['selected_specialist_ids'], true)) {
                $missed += (int) ($entry['status'] === 'reached'); $unknown += (int) ($entry['status'] === 'dependency');
            }
            if ($unknown !== 0 || ($outcome['false_negative_count'] ?? null) !== $missed
                || ($outcome['sample_count'] ?? null) !== count($sample['selection']['selected_specialist_ids'])
                || ($outcome['sample_hash'] ?? null) !== $sample['selection']['sample_hash']) $this->refuse('HISTORICAL_MEASUREMENT_DRIFT');
        }
        return ['protocol' => self::PROTOCOL, 'status' => $status, 'receipt_key' => $publication->receipt_key,
            'evidence_hash' => $publication->evidence_hash, 'audit_id' => $seal['audit_id'], 'physical_question_hash' => $seal['physical_question_hash'],
            'criterion' => $seal['declaration']['criterion'], 'outcome' => $outcome, 'source_artifact_hash' => $sourceManifest['artifact_hash'],
            'source_basis' => 'original_archived_release', 'authority' => 'research_only', 'economic_authority' => false,
            'skill_authority' => false, 'independent_evidence' => false, 'promotion_evidence' => false];
    }

    private function originalStrategy(array $request, int $agentId): array
    {
        if (! isset($request['strategies'])) return $request;
        $rows = array_values(array_filter((array) $request['strategies'], fn ($row) => is_array($row) && ($row['lab_agent_id'] ?? null) === $agentId));
        if (count($rows) !== 1 || count($request['strategies']) !== 1) $this->refuse('HISTORICAL_ORIGINAL_STRATEGY_AMBIGUOUS');
        return [...array_diff_key($request, ['strategies' => true]), ...$rows[0]];
    }

    private function originalContractBody(array $contract): array
    {
        try { $body = json_decode($contract['contract_json'] ?? '', true, 512, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { $this->refuse('HISTORICAL_ORIGINAL_CONTRACT_INVALID'); }
        if (! is_array($body) || ! $this->same($body, array_diff_key($contract, ['contract_hash' => true, 'contract_json' => true, 'server_seal' => true]))
            || ($contract['contract_hash'] ?? null) !== $this->hash($body)) $this->refuse('HISTORICAL_ORIGINAL_CONTRACT_INVALID');
        return $body;
    }

    private function historicalSample(array $seal, LabEvidenceArtifact $original, LabEvaluationRun $cheap, array $receipt): array
    {
        $rows = LabEvidenceArtifact::where('artifact_type', self::SAMPLE_ARTIFACT)->where('run_id', $seal['audit_id'])->get();
        if ($rows->count() !== 1) $this->refuse('HISTORICAL_ORIGINAL_SAMPLE_REQUIRED');
        $artifact = $rows->first(); $body = $this->readModernArtifact($artifact);
        $rejected = array_values(array_filter($receipt['pool'], fn ($entry) => $entry['status'] === 'rejected'));
        $ranked = $rejected;
        usort($ranked, fn ($left, $right) => strcmp(hash('sha256', $seal['declaration']['seed'].'|'.$seal['physical_question_hash'].'|'.$left['member_version_hash']),
            hash('sha256', $seal['declaration']['seed'].'|'.$seal['physical_question_hash'].'|'.$right['member_version_hash'])));
        $selected = array_slice($ranked, 0, $seal['declaration']['sample_cap']);
        $selection = ['protocol' => 'native_reachability_reject_sample_v1', 'cheap_run_id' => (int) $cheap->id,
            'cheap_response_hash' => $cheap->response_hash, 'cheap_receipt_hash' => $receipt['receipt_hash'], 'pool_hash' => $receipt['pool_hash'],
            'selected_specialist_ids' => array_column($selected, 'specialist_id'), 'selected_member_hashes' => array_column($selected, 'member_version_hash')];
        $selection['sample_hash'] = $this->hash($selection);
        if (! $this->same($body['selection'] ?? [], $selection) || ! $this->same($body['whole_pool'] ?? [], $receipt['pool'])
            || ! $this->same($body['reject_pool'] ?? [], $rejected) || ($body['reject_pool_hash'] ?? null) !== $this->hash($rejected)
            || ($body['audit_id'] ?? null) !== $seal['audit_id'] || ($body['preregistration_hash'] ?? null) !== $original->sha256
            || ($body['cheap_run_uuid'] ?? null) !== $cheap->run_id || ($body['cheap_request_hash'] ?? null) !== $cheap->request_hash
            || ($body['seed_hash'] ?? null) !== hash('sha256', $seal['declaration']['seed']) || ! $artifact->recorded_at
            || $artifact->recorded_at->lessThan($cheap->finished_at)) $this->refuse('HISTORICAL_ORIGINAL_POOL_OR_SAMPLE_DRIFT');
        return [...$body, 'recorded_at' => $artifact->recorded_at];
    }

    private function closeReferences(array $seal, LabEvidenceArtifact $artifact, array $publication): void
    {
        foreach ($seal['constructor_proof']['constructor_episode_ids'] as $witness) {
            $episode = AgentLearningEpisode::whereKey($witness['episode_id'])->lockForUpdate()->firstOrFail();
            if ((int) $episode->lab_agent_id !== $witness['agent_id'] || (int) $episode->model_version_id !== $witness['model_id'] || $episode->stage !== 'mutation_selection') $this->refuse('ORIGINAL_EPISODE_OWNER_MISMATCH');
            $key = self::PROTOCOL.'|neutral|'.$seal['audit_id'].'|'.$episode->id;
            $prior = AgentLearningSettlement::where('episode_id', $episode->id)->first();
            if ($prior) { if ($prior->source_key !== $key || $prior->source_type !== self::class || $prior->selection_reward !== 0.0 || $prior->evidence_state !== 'neutral') $this->refuse('NEUTRAL_SETTLEMENT_CONFLICT'); continue; }
            if (! in_array($episode->status, ['open', 'decision', 'running'], true)) $this->refuse('ORIGINAL_EPISODE_TERMINAL_CONFLICT');
            AgentLearningSettlement::create(['settlement_id' => (string) Str::uuid(), 'episode_id' => $episode->id, 'source_key' => $key,
                'source_type' => self::class, 'source_id' => $publication['receipt_id'], 'outcome_status' => 'authority_withheld',
                'failure_class' => 'research_only_depth_audit', 'evidence_state' => 'neutral', 'selection_reward' => 0.0, 'hard_failure' => false,
                'outcome' => ['protocol' => self::PROTOCOL, 'audit_id' => $seal['audit_id'], 'preregistration_hash' => $artifact->sha256,
                    'scientific_lesson_inferred' => false, 'promotion_evidence' => false], 'settled_at' => now()]);
            $episode->update(['status' => 'settled', 'settled_at' => now()]);
        }
        $references = array_values($seal['constructor_proof']['source_ids']);
        if (LabEvaluationRun::where('lab_agent_id', $seal['members']['deeper']['agent_id'])->doesntExist()) $references[] = [
            'agent_id' => $seal['members']['deeper']['agent_id'], 'model_version_id' => $seal['members']['deeper']['model_id']];
        foreach ($references as $reference) {
            $agent = LabAgent::whereKey($reference['agent_id'])->lockForUpdate()->firstOrFail();
            if ((int) $agent->model_version_id !== $reference['model_version_id'] || (int) $agent->lab_generation_id !== $seal['generation_id']
                || LabEvaluationRun::where('lab_agent_id', $agent->id)->exists()) $this->refuse('ORIGINAL_REFERENCE_ONLY_REQUIRED');
            if ($agent->lifecycle_status === 'draft') {
                $agent->update(['lifecycle_status' => 'completed', 'decision_reason' => 'NATIVE_DEPTH_AUDIT_NEUTRAL_REFERENCE_ONLY']);
                $this->evidence->recordLifecycle($agent, 'native_depth_audit_reference_disposition', ['audit_id' => $seal['audit_id'],
                    'original_receipt_key' => $publication['receipt_key'], 'no_evaluation_run_created' => true,
                    'economic_authority' => false, 'promotion_evidence' => false], 'mutation_selection', null, 1, self::class);
            } elseif (! in_array($agent->lifecycle_status, ['completed', 'quarantined', 'technical_quarantine', 'legacy_quarantine'], true)) $this->refuse('REFERENCE_ACTIVE_OWNER_FORBIDDEN');
        }
        foreach ($seal['members'] as $member) {
            $agent = LabAgent::findOrFail($member['agent_id']);
            if ($agent->lifecycle_status === 'evaluation_error' && $this->terminalOriginalRun($seal, $member === $seal['members']['cheap'] ? 'cheap' : 'deeper')?->status === 'technical_error') {
                $agent->update(['lifecycle_status' => 'technical_quarantine', 'decision_reason' => 'NATIVE_DEPTH_AUDIT_ORIGINAL_TECHNICAL']);
            }
        }
        $generation = LabGeneration::findOrFail($seal['generation_id']);
        $generation->update(['trigger_context' => [...(array) $generation->trigger_context, self::PHASE => [
            'protocol' => self::PROTOCOL, 'phase' => 'terminal', 'audit_id' => $seal['audit_id'], 'receipt_key' => $publication['receipt_key']]]]);
    }

    private function physicalQuestionHash(array $request, array $native, array $declaration): string
    {
        $streams = (array) data_get($request, 'mtf_snapshot_manifest.streams', []); $physical = [];
        if (count($streams) !== 4 || array_diff(array_keys($streams), ['M5', 'M15', 'H1', 'H4']) !== []) $this->refuse('ALL_FOUR_PHYSICAL_STREAMS_REQUIRED');
        foreach ($streams as $timeframe => $stream) {
            $path = $stream['path'] ?? null;
            if (! is_string($path) || ! is_file($path) || hash_file('sha256', $path) !== ($stream['sha256'] ?? null)) $this->refuse('ACTUAL_PHYSICAL_SOURCE_BYTES_REQUIRED');
            $rows = app(LabDatasetExportService::class)->rowsFromSnapshot($path, 40001);
            if ($rows === [] || count($rows) > 40000 || count($rows) !== ($stream['row_count'] ?? null)) $this->refuse('PHYSICAL_SOURCE_ROWS_INVALID');
            foreach ($rows as &$row) foreach ($row as $key => $value) {
                if (in_array($key, ['time', 'quote_time_utc', 'quote_available_after_utc'], true) && $value !== '') $row[$key] = CarbonImmutable::parse($value, 'UTC')->utc()->format('Y-m-d\TH:i:s.u\Z');
                elseif (in_array($key, ['volume_available', 'spread_available'], true)) $row[$key] = in_array($value, [true, 1, '1'], true);
                elseif (is_numeric($value)) $row[$key] = (float) $value;
            }
            unset($row); $physical[$timeframe] = $this->hash($rows);
        }
        $programmes = [];
        foreach ($native['members'] as $member) $programmes[] = ['strategy' => $member['base_strategy'] ?? $member['strategy'],
            'parameters' => $member['parameters'], 'role' => $member['role'], 'horizon' => $member['horizon'],
            'capital_weight' => $member['capital_weight'], 'risk_per_trade_percent' => $member['risk_per_trade_percent'],
            'scope' => $member['scope'] ?? [], 'context' => $this->withoutLabels($member['specialist_context_contract'] ?? []),
            'composition' => $this->withoutLabels($member['composition_runtime_contract'] ?? []),
            'operator' => $this->withoutLabels($member['operator_contract'] ?? []),
            'instrument' => $this->withoutLabels($member['instrument_research_assignment'] ?? [])];
        return $this->hash(['protocol' => self::PROTOCOL, 'physical_sources' => $physical, 'programmes' => $programmes,
            'account_policy' => $this->withoutLabels($native['policy']), 'initial_capital' => $declaration['initial_capital'],
            'execution' => $request['execution_contract']['parameters'],
            'contexts' => $declaration['contexts'], 'criterion' => $declaration['criterion']]);
    }

    private function withoutLabels(array $value): array
    {
        foreach ($value as $key => $item) {
            if (in_array((string) $key, ['id', 'version', 'version_id', 'model_version_id', 'council_id', 'council_version', 'source_model_hash',
                'passport_hash', 'contract_hash', 'contract_json', 'manifest_hash', 'assignment_hash', 'experiment_key', 'source_hash',
                'evaluator_version', 'generation_id', 'component_id', 'strategy_version', 'tactic_version', 'management_version'], true)) unset($value[$key]);
            elseif (is_array($item)) $value[$key] = $this->withoutLabels($item);
        }
        return $value;
    }

    private function readModernArtifact(LabEvidenceArtifact $artifact): array
    {
        if (data_get($artifact->metadata, 'storage_protocol') !== 'compressed_artifact_v2'
            || ! is_string($artifact->storage_path) || $artifact->storage_path === ''
            || ! $this->sha($artifact->sha256)) $this->refuse('MODERN_ORIGINAL_ARTIFACT_BYTES_REQUIRED');
        $payload = $this->evidence->readArtifactPayload($artifact);
        if (! is_array($payload)) $this->refuse('MODERN_ORIGINAL_ARTIFACT_BYTES_REQUIRED');
        return $payload;
    }

    private function assertObserverTraceJoin(array $receipt, array $response): void
    {
        $trace = $response['decision_trace'] ?? null;
        if (! is_array($trace) || ! array_is_list($trace)) $this->refuse('OBSERVER_ORIGINAL_NATIVE_TRACE_REQUIRED');
        $byIndex = [];
        foreach ($trace as $row) {
            $index = $row['candle_index'] ?? null;
            if (! is_int($index) || isset($byIndex[$index])) $this->refuse('OBSERVER_NATIVE_TRACE_INDEX_INVALID');
            $members = [];
            foreach ((array) ($row['member_decisions'] ?? []) as $member) {
                $hash = $member['member_version_hash'] ?? null;
                if (! $this->sha($hash) || isset($members[$hash])) $this->refuse('OBSERVER_NATIVE_TRACE_MEMBER_INVALID');
                $members[$hash] = $member;
            }
            $byIndex[$index] = ['row' => $row, 'members' => $members];
        }
        foreach ($receipt['events'] as $event) {
            $joined = $byIndex[$event['execution_index']] ?? null;
            $member = $joined['members'][$event['member_version_hash']] ?? null;
            if (! $member || ($member['specialist_id'] ?? null) !== $event['specialist_id']
                || ($member['closed_inputs_hash'] ?? null) !== $event['closed_input_hash']) $this->refuse('OBSERVER_NATIVE_CLOSED_INPUT_JOIN_INVALID');
            foreach (['signal_time', 'execution_time'] as $field) {
                try {
                    $time = CarbonImmutable::parse($event[$field])->utc();
                    if (! $time->equalTo(CarbonImmutable::parse($joined['row'][$field])->utc())
                        || ! $time->equalTo(CarbonImmutable::parse(data_get($joined['row'], 'source_clock.'.$field))->utc())) $this->refuse('OBSERVER_NATIVE_CLOCK_JOIN_INVALID');
                } catch (\Throwable) { $this->refuse('OBSERVER_NATIVE_CLOCK_JOIN_INVALID'); }
            }
        }
    }

    private function sealed(array $value, string $kind): void
    {
        $body = array_diff_key($value, [$kind.'_hash' => true, $kind.'_json' => true]);
        if (($value[$kind.'_hash'] ?? null) !== $this->hash($body) || ! is_string($value[$kind.'_json'] ?? null)
            || ! $this->same(json_decode($value[$kind.'_json'], true, 512, JSON_THROW_ON_ERROR), $body)) $this->refuse('IMMUTABLE_SEAL_INVALID');
    }
    private function sha(mixed $value): bool { return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1; }
    private function key(): string { $value = (string) config('services.internal_api.token'); if (strlen($value) < 32) $this->refuse('SERVER_SEAL_REQUIRED'); return $value; }
    private function same(mixed $left, mixed $right): bool { return $this->evidence->equivalentJsonValue($left, $right); }
    private function hash(array $value): string { return $this->epochs->parameterHash($value); }
    private function json(array $value): string { return json_encode($this->canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION); }
    private function canonical(array $value): array { if (! array_is_list($value)) ksort($value); foreach ($value as $key => $item) if (is_array($item)) $value[$key] = $this->canonical($item); return $value; }
    private function refuse(string $code): never { throw new LogicException('NATIVE_DEPTH_AUDIT_'.$code); }
}
