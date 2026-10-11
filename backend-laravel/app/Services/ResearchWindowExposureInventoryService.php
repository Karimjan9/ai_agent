<?php

namespace App\Services;

use App\Models\AgentLearningCausalExperiment;
use App\Models\CausalFoldReceipt;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use App\Models\ModelVersion;
use App\Models\ResearchExposureCaptureRecord;
use App\Models\ScopedResearchCertificate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Throwable;

/** Prospective candidate-scoped capture. Historical absence is never global unused proof. */
class ResearchWindowExposureInventoryService
{
    public const PROTOCOL = 'prospective_scoped_exposure_capture_v1';

    public const AUTHORITY_POLICY = 'independent_scoped_research_authority_v1';

    public function __construct(private ResearchPaperEpochContractService $epochs) {}

    /** Called by the original certificate registration transaction, before any future source event. */
    public function registerCapture(int $certificateId): array
    {
        if (! Schema::hasTable('research_exposure_capture_records')) {
            throw new LogicException('EXPOSURE_CAPTURE_MIGRATION_REQUIRED');
        }

        return DB::transaction(function () use ($certificateId): array {
            $registration = $this->registration($certificateId);
            $design = $registration['design'];
            $policy = $this->policy($design);
            foreach ((array) data_get($registration, 'source_snapshot.manifest.members', []) as $member) {
                if ((int) data_get($member, 'horizon.max_holding_seconds', 0) > $policy['holding_fence_seconds']) {
                    throw new LogicException('EXPOSURE_FROZEN_HOLDING_BELOW_SOURCE_HORIZON');
                }
            }
            $models = array_map('intval', array_keys((array) $registration['source_snapshot']['models']));
            sort($models);
            if ($models === [] || count($models) > 64) {
                throw new LogicException('EXPOSURE_FROZEN_MODEL_ROSTER_REQUIRED');
            }
            $key = $this->hash([self::PROTOCOL, 'capture_start', $certificateId]);
            $existing = ResearchExposureCaptureRecord::where('record_key', $key)->first();
            if ($existing) {
                $this->assertRecord($existing);
                $this->assertRegistrationMatches($registration, $existing->payload);

                return $existing->payload;
            }
            ModelVersion::whereIn('id', $models)->orderBy('id')->lockForUpdate()->get();
            $experiments = $this->modelExperiments($models);
            if (LabEvaluationRun::whereIn('model_version_id', $models)->exists()) {
                throw new LogicException('EXPOSURE_CAPTURE_CANNOT_COMPLETE_PREEXISTING_PARTIAL_HISTORY');
            }
            if (CausalFoldReceipt::whereIn('agent_learning_causal_experiment_id', $experiments)->exists()) {
                throw new LogicException('EXPOSURE_CAPTURE_CANNOT_COMPLETE_PREEXISTING_PARTIAL_FOLDS');
            }
            $clock = CarbonImmutable::now('UTC');
            if (! $clock->lessThan(CarbonImmutable::parse($design['validation_start']))
                || ! CarbonImmutable::parse($registration['preregistered_at'])->lessThan(CarbonImmutable::parse($design['validation_start']))) {
                throw new LogicException('EXPOSURE_CAPTURE_MUST_PRECEDE_FIRST_SOURCE_EVENT');
            }
            $payload = ['protocol' => self::PROTOCOL, 'certificate_id' => $certificateId,
                'source_hash' => $registration['source_hash'], 'design_hash' => $registration['design_hash'],
                'source_snapshot_hash' => $this->hash($registration['source_snapshot']), 'model_ids' => $models,
                'symbol' => $registration['source_snapshot']['symbol'] ?? 'XAUUSD',
                'source_experiment_ids' => $experiments,
                'capture_started_at' => $clock->toIso8601String(),
                'coverage_start_inclusive' => $design['validation_start'], 'coverage_end_exclusive' => $design['validation_end'],
                'run_high_water' => (int) LabEvaluationRun::max('id'),
                'artifact_high_water' => (int) LabEvidenceArtifact::max('id'),
                'fold_high_water' => (int) CausalFoldReceipt::max('id'),
                'ingress_owner' => [LabImmutableEvidenceService::class.'::attachRequest', CausalFoldExecutionService::class.'::run'],
                'byte_owner' => SpecialistCouncilDataUseService::class, 'policy' => $policy,
                'evaluator_release_hash' => app(LabImmutableEvidenceService::class)->codeHash(),
                'completeness_scope' => 'frozen_original_candidate_models_and_selection_design_after_capture_start',
                'historical_training_selection_inventory_complete' => false,
                'absence_of_legacy_records_proves_unused' => false];
            $this->append($certificateId, 'capture_start', null, $key, $payload);

            return $payload;
        });
    }

    /** Original durable fold ingress, before HTTP; aggregate publication cannot manufacture this receipt. */
    public function recordCausalFoldRequest(CausalFoldReceipt $fold, array $request, string $requestHash): array
    {
        return $this->captureCausalFoldRequest($fold, $request, $requestHash, false);
    }

    /** Re-attest the existing prefix after HTTP; this path is forbidden from creating a late receipt. */
    public function assertCausalFoldRequestCaptured(CausalFoldReceipt $fold, array $request, string $requestHash, array $prefix): void
    {
        $current = $this->captureCausalFoldRequest($fold, $request, $requestHash, true);
        if ($current !== $prefix) {
            throw new LogicException('EXPOSURE_ORIGINAL_FOLD_PREPUBLICATION_PROOF_REQUIRED');
        }
    }

    /** Read only the sealed pre-delivery original; completed folds cannot create late provenance. */
    public function verifiedOriginalFoldRequest(CausalFoldReceipt $fold, int $certificateId): array
    {
        $original = CausalFoldReceipt::findOrFail($fold->id);
        $capture = ResearchExposureCaptureRecord::where('certificate_id', $certificateId)
            ->where('record_type', 'capture_start')->sole();
        $this->assertRecord($capture);
        $registration = $this->registration($certificateId);
        $this->assertRegistrationMatches($registration, $capture->payload);
        $key = $this->hash([self::PROTOCOL, 'causal_fold_ingress', $certificateId,
            (int) $original->id, (int) $original->attempt_count]);
        $record = ResearchExposureCaptureRecord::where('record_key', $key)->sole();
        $this->assertRecord($record);
        $body = $record->payload;
        $experiment = AgentLearningCausalExperiment::findOrFail($original->agent_learning_causal_experiment_id);
        $models = LabAgent::whereIn('id', [$experiment->guided_agent_id, $experiment->blinded_agent_id,
            $experiment->control_agent_id])->pluck('model_version_id')->map(fn ($id) => (int) $id)->all();
        $capturedModels = $body['model_ids'];
        sort($models);
        sort($capturedModels);
        if ($original->status !== 'completed' || ! $original->completed_at || ! $original->started_at
            || (int) $original->id <= $capture->payload['fold_high_water']
            || $original->started_at->lessThan($capture->payload['capture_started_at'])
            || $body['capture_record_id'] !== (int) $capture->id
            || $body['certificate_id'] !== $certificateId
            || $body['causal_fold_receipt_id'] !== (int) $original->id
            || $body['experiment_id'] !== (int) $experiment->id
            || $body['fold_index'] !== (int) $original->fold_index
            || $body['attempt_count'] !== (int) $original->attempt_count
            || ! $original->lease_token || $body['lease_token'] !== $original->lease_token
            || count($models) !== 3 || count(array_unique($models)) !== 3 || $capturedModels !== $models
            || array_diff($models, $capture->payload['model_ids']) !== []
            || ! in_array((int) $experiment->id, $capture->payload['source_experiment_ids'], true)
            || ($body['captured_before_evaluator_delivery'] ?? false) !== true
            || CarbonImmutable::parse($body['captured_at'])->greaterThan($original->completed_at)
            || $body['request_hash'] !== $original->request_hash
            || ! is_array($body['request'] ?? null)
            || $this->hash($body['request']) !== $original->request_hash
            || $this->hash((array) $original->request_payload) !== $original->request_hash
            || app(LabImmutableEvidenceService::class)->codeHash() !== $capture->payload['evaluator_release_hash']) {
            throw new LogicException('EXPOSURE_COMPLETED_ORIGINAL_FOLD_OWNER_DRIFT');
        }
        $inventory = app(SpecialistCouncilDataUseService::class)->capturedOriginalRequestIntervals(
            $body['request'], (int) $capture->payload['policy']['holding_fence_seconds']);
        if ($this->hash($inventory) !== $this->hash($body['inventory'])) {
            throw new LogicException('EXPOSURE_CAPTURED_SOURCE_BYTES_DRIFT');
        }
        $this->assertInventoryScope($inventory, $capture->payload);
        $this->assertInventoryInOriginalWindow($inventory, $registration['design']);
        $this->assertRequestHolding($body['request'], $capture->payload['policy']);

        return $body['request'];
    }

    private function captureCausalFoldRequest(CausalFoldReceipt $fold, array $request, string $requestHash, bool $requireExisting): array
    {
        $experiment = AgentLearningCausalExperiment::findOrFail($fold->agent_learning_causal_experiment_id);
        $models = LabAgent::whereIn('id', array_filter([$experiment->guided_agent_id,
            $experiment->blinded_agent_id, $experiment->control_agent_id]))->pluck('model_version_id')->map(fn ($id) => (int) $id)->all();
        $declared = $this->declaredScopes($models);
        if (! Schema::hasTable('research_exposure_capture_records')) {
            if ($declared !== []) {
                throw new LogicException('EXPOSURE_CAPTURE_MIGRATION_REQUIRED');
            }

            return [];
        }
        $starts = ResearchExposureCaptureRecord::where('record_type', 'capture_start')
            ->where(function ($query) use ($models): void {
                foreach ($models as $model) {
                    $query->orWhereJsonContains('payload->model_ids', $model);
                }
            })->orderBy('id')->get();
        if (array_diff($declared, $starts->pluck('certificate_id')->all()) !== []) {
            throw new LogicException('EXPOSURE_DECLARED_SCOPE_CAPTURE_BOUNDARY_MISSING');
        }
        $prefix = [];
        foreach ($starts as $start) {
            $prefix[] = DB::transaction(function () use ($start, $fold, $request, $requestHash, $experiment, $models, $requireExisting): int {
                $this->assertRecord($start);
                $registration = $this->registration((int) $start->certificate_id);
                $this->assertRegistrationMatches($registration, $start->payload);
                $original = CausalFoldReceipt::whereKey($fold->id)->lockForUpdate()->firstOrFail();
                if ((int) $original->id <= $start->payload['fold_high_water']
                    || ! in_array((int) $experiment->id, $start->payload['source_experiment_ids'], true)
                    || array_diff($models, $start->payload['model_ids']) !== []
                    || $original->status !== 'running' || (int) $original->attempt_count < 1
                    || ! $original->lease_token || $original->lease_token !== $fold->lease_token
                    || ! $original->started_at || $original->started_at->lessThan($start->payload['capture_started_at'])
                    || data_get($request, 'policy_context.causal_fold_job.experiment_id') !== (int) $experiment->id
                    || data_get($request, 'policy_context.causal_fold_job.fold_index') !== (int) $original->fold_index
                    || $this->hash($request) !== $requestHash) {
                    throw new LogicException('EXPOSURE_ORIGINAL_FOLD_INGRESS_OWNER_INVALID');
                }
                if (app(LabImmutableEvidenceService::class)->codeHash() !== $start->payload['evaluator_release_hash']) {
                    throw new LogicException('EXPOSURE_ORIGINAL_EVALUATOR_RELEASE_DRIFT');
                }
                if (($request['symbol'] ?? null) !== $start->payload['symbol']) {
                    throw new LogicException('EXPOSURE_ORIGINAL_INGRESS_SYMBOL_MISMATCH');
                }
                $inventory = app(SpecialistCouncilDataUseService::class)->capturedOriginalRequestIntervals($request,
                    (int) $start->payload['policy']['holding_fence_seconds']);
                $this->assertInventoryScope($inventory, $start->payload);
                $this->assertInventoryInOriginalWindow($inventory, $registration['design']);
                $this->assertRequestHolding($request, $start->payload['policy']);
                $payload = ['protocol' => self::PROTOCOL, 'certificate_id' => (int) $start->certificate_id,
                    'capture_record_id' => (int) $start->id, 'causal_fold_receipt_id' => (int) $original->id,
                    'experiment_id' => (int) $experiment->id, 'model_ids' => $models,
                    'fold_index' => (int) $original->fold_index, 'attempt_count' => (int) $original->attempt_count,
                    'lease_token' => $original->lease_token, 'request_hash' => $requestHash,
                    'request' => $request, 'inventory' => $inventory,
                    'captured_at' => CarbonImmutable::now('UTC')->toIso8601String(),
                    'captured_before_evaluator_delivery' => true];
                $key = $this->hash([self::PROTOCOL, 'causal_fold_ingress', (int) $start->certificate_id,
                    (int) $original->id, (int) $original->attempt_count]);
                $existing = ResearchExposureCaptureRecord::where('record_key', $key)->first();
                if ($existing) {
                    $this->assertRecord($existing);
                    $old = $existing->payload;
                    unset($old['captured_at'], $payload['captured_at']);
                    if ($this->hash($old) !== $this->hash($payload)) {
                        throw new LogicException('EXPOSURE_ORIGINAL_FOLD_INGRESS_IMMUTABLE');
                    }

                    return (int) $existing->id;
                }
                if ($requireExisting) {
                    throw new LogicException('EXPOSURE_ORIGINAL_FOLD_PREPUBLICATION_PROOF_REQUIRED');
                }

                return (int) $this->append((int) $start->certificate_id, 'causal_fold_ingress', null, $key, $payload)->id;
            });
        }

        return $prefix;
    }

    /** Real immutable ingress invokes this before evaluator delivery. A declared scope fails closed. */
    public function captureAttachedRequest(LabEvaluationRun $run, array $request, LabEvidenceArtifact $artifact): void
    {
        $declared = $this->declaredScopes([(int) $run->model_version_id]);
        if (! Schema::hasTable('research_exposure_capture_records')) {
            if ($declared !== []) {
                throw new LogicException('EXPOSURE_CAPTURE_MIGRATION_REQUIRED');
            }

            return;
        }
        $starts = ResearchExposureCaptureRecord::where('record_type', 'capture_start')
            ->whereJsonContains('payload->model_ids', (int) $run->model_version_id)->orderBy('id')->get();
        if (array_diff($declared, $starts->pluck('certificate_id')->all()) !== []) {
            throw new LogicException('EXPOSURE_DECLARED_SCOPE_CAPTURE_BOUNDARY_MISSING');
        }
        foreach ($starts as $start) {
            if (! in_array((int) $run->model_version_id, (array) ($start->payload['model_ids'] ?? []), true)) {
                continue;
            }
            $this->assertRecord($start);
            $registration = $this->registration((int) $start->certificate_id);
            $this->assertRegistrationMatches($registration, $start->payload);
            if ((int) $run->id <= $start->payload['run_high_water']
                || (int) $artifact->id <= $start->payload['artifact_high_water']
                || ! $run->started_at || $run->started_at->lessThan(CarbonImmutable::parse($start->payload['capture_started_at']))) {
                throw new LogicException('EXPOSURE_ORIGINAL_INGRESS_PRECEDES_CAPTURE_BOUNDARY');
            }
            if ($run->code_hash !== $start->payload['evaluator_release_hash']) {
                throw new LogicException('EXPOSURE_ORIGINAL_EVALUATOR_RELEASE_DRIFT');
            }
            if (($request['symbol'] ?? null) !== $start->payload['symbol']) {
                throw new LogicException('EXPOSURE_ORIGINAL_INGRESS_SYMBOL_MISMATCH');
            }
            $inventory = app(SpecialistCouncilDataUseService::class)->capturedOriginalRequestIntervals($request,
                (int) $start->payload['policy']['holding_fence_seconds']);
            $this->assertInventoryScope($inventory, $start->payload);
            $this->assertInventoryInOriginalWindow($inventory, $registration['design']);
            $this->assertRequestHolding($request, $start->payload['policy']);
            $payload = ['protocol' => self::PROTOCOL, 'certificate_id' => (int) $start->certificate_id,
                'capture_record_id' => (int) $start->id, 'evaluation_run_id' => (int) $run->id,
                'run_id' => $run->run_id, 'model_version_id' => (int) $run->model_version_id,
                'phase' => $run->phase, 'request_hash' => $run->request_hash,
                'request_artifact_id' => (int) $artifact->id, 'request_artifact_hash' => $artifact->sha256,
                'request_payload_hash' => $artifact->metadata['raw_payload_hash'] ?? null,
                'captured_at' => CarbonImmutable::now('UTC')->toIso8601String(), 'inventory' => $inventory];
            $key = $this->hash([self::PROTOCOL, 'request_ingress', (int) $start->certificate_id, (int) $run->id]);
            $existing = ResearchExposureCaptureRecord::where('record_key', $key)->first();
            if ($existing) {
                $this->assertRecord($existing);
                unset($payload['captured_at']);
                $original = $existing->payload;
                unset($original['captured_at']);
                if ($this->hash($payload) !== $this->hash($original)) {
                    throw new LogicException('EXPOSURE_ORIGINAL_INGRESS_IMMUTABLE');
                }

                continue;
            }
            $this->append((int) $start->certificate_id, 'request_ingress', (int) $run->id, $key, $payload);
        }
    }

    /** Reopens all captured original bytes and checks every scoped run, including omitted/partial originals. */
    public function assessForCertificate(int $certificateId, array $window, array $manifest, array $runIds, array $foldReceiptIds = []): array
    {
        try {
            if (! Schema::hasTable('research_exposure_capture_records')) {
                throw new LogicException('EXPOSURE_CAPTURE_MIGRATION_REQUIRED');
            }
            $registration = $this->registration($certificateId);
            $capture = ResearchExposureCaptureRecord::where('certificate_id', $certificateId)->where('record_type', 'capture_start')->sole();
            $this->assertRecord($capture);
            $this->assertRegistrationMatches($registration, $capture->payload);
            $from = CarbonImmutable::parse((string) ($window['start_inclusive'] ?? ''))->utc();
            $until = CarbonImmutable::parse((string) ($window['end_exclusive'] ?? ''))->utc();
            if ($from->lessThan($capture->payload['coverage_start_inclusive'])
                || $until->greaterThan($capture->payload['coverage_end_exclusive'])
                || ! $until->greaterThan($from) || $until->greaterThan(now()->utc())
                || ! $this->epochs->researchIntervalDisjointFromPaper($from->toIso8601String(), $until->toIso8601String())) {
                throw new LogicException('EXPOSURE_CANDIDATE_OUTSIDE_CAPTURE_COVERAGE');
            }
            $planned = array_filter((array) ($registration['design']['validation_windows'] ?? []),
                fn ($planned) => CarbonImmutable::parse($planned['start_inclusive'])->equalTo($from)
                    && CarbonImmutable::parse($planned['end_exclusive'])->equalTo($until));
            if (count($planned) !== 1) {
                throw new LogicException('EXPOSURE_CANDIDATE_WINDOW_NOT_IN_ORIGINAL_DESIGN');
            }
            if (! array_is_list($runIds) || count($runIds) > 128
                || count(array_unique($runIds, SORT_REGULAR)) !== count($runIds)
                || count(array_filter($runIds, fn ($id) => is_int($id) && $id > 0)) !== count($runIds)) {
                throw new LogicException('EXPOSURE_ORIGINAL_RUN_REFERENCES_INVALID');
            }
            if (! array_is_list($foldReceiptIds) || count($foldReceiptIds) > 128
                || count(array_unique($foldReceiptIds, SORT_REGULAR)) !== count($foldReceiptIds)
                || count(array_filter($foldReceiptIds, fn ($id) => is_int($id) && $id > 0)) !== count($foldReceiptIds)) {
                throw new LogicException('EXPOSURE_ORIGINAL_FOLD_REFERENCES_INVALID');
            }
            $input = app(InstrumentResearchWindowService::class)->verifySealedReplayWindow($window, $manifest);
            $actualInventory = app(SpecialistCouncilDataUseService::class)->capturedOriginalRequestIntervals([
                'symbol' => $registration['source_snapshot']['symbol'] ?? 'XAUUSD', 'timeframe' => 'M5',
                'dataset_path' => $manifest['streams']['M5']['path'], 'replay_dataset_hash' => $manifest['bundle_hash'],
                'mtf_snapshot_manifest' => $manifest,
                'mtf_dataset_paths' => array_map(fn ($file) => $file['path'], $manifest['streams']),
            ], (int) $capture->payload['policy']['holding_fence_seconds']);
            $this->assertInventoryScope($actualInventory, [...$capture->payload,
                'coverage_start_inclusive' => $from->toIso8601String(), 'coverage_end_exclusive' => $until->toIso8601String()]);
            if (DB::table('specialist_council_data_uses as uses')
                ->join('specialist_council_data_events as events', 'events.id', '=', 'uses.event_id')
                ->whereIn('uses.use', ['training', 'selection'])->where('events.symbol', $capture->payload['symbol'])
                ->where('events.event_start', '<', $until)->where('events.event_end', '>', $from)->exists()) {
                throw new LogicException('CANDIDATE_INTERSECTS_ORIGINAL_PHYSICAL_EXPOSURE');
            }
            $models = $capture->payload['model_ids'];
            $runs = LabEvaluationRun::whereIn('model_version_id', $models)->orderBy('id')->limit(257)->get();
            if ($runs->count() > 256) {
                throw new LogicException('EXPOSURE_ORIGINAL_INGRESS_INVENTORY_BOUND_EXCEEDED');
            }
            if (array_diff($runIds, $runs->pluck('id')->map(fn ($id) => (int) $id)->all()) !== []) {
                throw new LogicException('EXPOSURE_ORIGINAL_INGRESS_CAPTURE_INCOMPLETE');
            }
            $records = ResearchExposureCaptureRecord::where('certificate_id', $certificateId)
                ->where('record_type', 'request_ingress')->orderBy('id')->limit(257)->get();
            if ($records->count() !== $runs->count()) {
                throw new LogicException('EXPOSURE_ORIGINAL_INGRESS_CAPTURE_INCOMPLETE');
            }
            $receipts = [];
            $found = [];
            foreach ($runs as $run) {
                $record = $records->firstWhere('evaluation_run_id', (int) $run->id);
                if (! $record) {
                    throw new LogicException('EXPOSURE_ORIGINAL_INGRESS_CAPTURE_INCOMPLETE');
                }
                $this->assertRecord($record);
                $body = $record->payload;
                if ($body['capture_record_id'] !== (int) $capture->id || $body['run_id'] !== $run->run_id
                    || $body['model_version_id'] !== (int) $run->model_version_id
                    || $body['request_hash'] !== $run->request_hash || $body['phase'] !== $run->phase
                    || $run->code_hash !== $capture->payload['evaluator_release_hash']) {
                    throw new LogicException('EXPOSURE_ORIGINAL_INGRESS_OWNER_DRIFT');
                }
                $artifacts = LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', 'evaluation_request')->get();
                if ($artifacts->count() !== 1) {
                    throw new LogicException('EXPOSURE_ORIGINAL_REQUEST_ARTIFACT_AMBIGUOUS');
                }
                $artifact = $artifacts->first();
                if ((int) $artifact->id !== $body['request_artifact_id'] || $artifact->sha256 !== $body['request_artifact_hash']
                    || data_get($artifact->metadata, 'storage_protocol') !== 'compressed_artifact_v2'
                    || $artifact->metadata['raw_payload_hash'] !== $body['request_payload_hash']
                    || data_get($run->request_meta, 'payload_hash') !== $body['request_payload_hash']) {
                    throw new LogicException('EXPOSURE_ORIGINAL_REQUEST_BYTES_REQUIRED');
                }
                $request = app(LabImmutableEvidenceService::class)->readArtifactPayload($artifact);
                if (! is_array($request)) {
                    throw new LogicException('EXPOSURE_ORIGINAL_REQUEST_BYTES_REQUIRED');
                }
                if (($request['symbol'] ?? null) !== $capture->payload['symbol']) {
                    throw new LogicException('EXPOSURE_ORIGINAL_INGRESS_SYMBOL_MISMATCH');
                }
                $inventory = app(SpecialistCouncilDataUseService::class)->capturedOriginalRequestIntervals($request,
                    (int) $capture->payload['policy']['holding_fence_seconds']);
                if ($this->hash($inventory) !== $this->hash($body['inventory'])) {
                    throw new LogicException('EXPOSURE_CAPTURED_SOURCE_BYTES_DRIFT');
                }
                $this->assertInventoryScope($inventory, $capture->payload);
                $this->assertInventoryInOriginalWindow($inventory, $registration['design']);
                $this->assertRequestHolding($request, $capture->payload['policy']);
                $isOriginal = in_array((int) $run->id, $runIds, true);
                if ($isOriginal && ($run->phase !== 'full_validation' || ! in_array($run->mode, ['full', 'replay'], true))) {
                    throw new LogicException('EXPOSURE_TRAINING_OR_SELECTION_CANNOT_BE_VALIDATION_ORIGINAL');
                }
                if ($isOriginal && ($inventory['dataset_hash'] !== $manifest['bundle_hash']
                    || $run->data_hash !== $manifest['bundle_hash'])) {
                    throw new LogicException('EXPOSURE_VALIDATION_ORIGINAL_DATA_MISMATCH');
                }
                foreach ($inventory['intervals'] as $interval) {
                    if (! $isOriginal && CarbonImmutable::parse($interval['start_inclusive'])->lessThan($until)
                        && CarbonImmutable::parse($interval['end_exclusive'])->greaterThan($from)) {
                        throw new LogicException('CANDIDATE_INTERSECTS_ORIGINAL_PHYSICAL_EXPOSURE');
                    }
                }
                if ($isOriginal) {
                    $found[] = (int) $run->id;
                }
                $receipts[] = ['record_id' => (int) $record->id, 'run_id' => (int) $run->id,
                    'payload_hash' => $record->payload_hash, 'inventory_hash' => $this->hash($inventory)];
            }
            sort($found);
            $expected = $runIds;
            sort($expected);
            if ($found !== $expected) {
                throw new LogicException('EXPOSURE_ORIGINAL_INGRESS_CAPTURE_INCOMPLETE');
            }
            $foldProof = $this->foldProof($capture, $from, $until, $manifest, $foldReceiptIds);
            $identity = ['protocol' => self::PROTOCOL, 'certificate_id' => $certificateId,
                'capture_record_id' => (int) $capture->id, 'capture_started_at' => $capture->payload['capture_started_at'],
                'coverage_start_inclusive' => $capture->payload['coverage_start_inclusive'],
                'coverage_end_exclusive' => $capture->payload['coverage_end_exclusive'],
                'source_hash' => $registration['source_hash'], 'design_hash' => $registration['design_hash'],
                'window_key' => $window['window_key'], 'dataset_hash' => $manifest['bundle_hash'],
                'actual_input_proof' => $input, 'actual_physical_inventory' => $actualInventory,
                'complete_input_proof' => true, 'complete_original_ingress_receipts' => $receipts,
                'original_run_ids' => $expected, 'prospective_scope_inventory_attested' => true,
                'complete_original_fold_ingress_receipts' => $foldProof['receipts'],
                'original_fold_receipt_ids' => $foldProof['original_fold_receipt_ids'],
                'original_training_selection_inventory_attested' => true,
                'historical_training_selection_inventory_complete' => false,
                'completeness_scope' => $capture->payload['completeness_scope'],
                'absence_of_recorded_use_proves_unused' => false, 'candidate_unused_demonstrated' => true,
                'known_shared_training_selection_overlap_checked' => true,
                'candidate_physical_time_overlap' => false, 'unresolved_provenance' => [],
                'status' => 'READY', 'ready' => true, 'reason_code' => 'PROSPECTIVE_ORIGINAL_CAPTURE_COMPLETE',
                'independent_evidence' => false, 'promotion_evidence' => false, 'paper_authority' => false];

            return [...$identity, 'readiness_hash' => $this->hash($identity)];
        } catch (Throwable $error) {
            $reason = preg_match('/^[A-Z0-9_]+(?::[A-Za-z0-9_.:-]+)?$/D', $error->getMessage())
                ? $error->getMessage() : 'EXPOSURE_ORIGINAL_OWNER_PROOF_UNAVAILABLE';
            $identity = ['protocol' => self::PROTOCOL, 'certificate_id' => $certificateId,
                'status' => 'BLOCKED_DEPENDENCY', 'ready' => false, 'reason_code' => $reason,
                'prospective_scope_inventory_attested' => false, 'original_training_selection_inventory_attested' => false,
                'historical_training_selection_inventory_complete' => false, 'candidate_unused_demonstrated' => false,
                'absence_of_recorded_use_proves_unused' => false, 'unresolved_provenance' => [$reason],
                'independent_evidence' => false, 'promotion_evidence' => false, 'paper_authority' => false];

            return [...$identity, 'readiness_hash' => $this->hash($identity)];
        }
    }

    private function foldProof(ResearchExposureCaptureRecord $capture, CarbonImmutable $from,
        CarbonImmutable $until, array $manifest, array $originalIds): array
    {
        if ($this->modelExperiments($capture->payload['model_ids']) !== $capture->payload['source_experiment_ids']) {
            throw new LogicException('EXPOSURE_FROZEN_SOURCE_EXPERIMENT_ROSTER_DRIFT');
        }
        $folds = CausalFoldReceipt::whereIn('agent_learning_causal_experiment_id', $capture->payload['source_experiment_ids'])
            ->orderBy('id')->limit(257)->get();
        if ($folds->count() > 256 || array_diff($originalIds, $folds->pluck('id')->map(fn ($id) => (int) $id)->all()) !== []) {
            throw new LogicException('EXPOSURE_ORIGINAL_FOLD_CAPTURE_INCOMPLETE');
        }
        $records = ResearchExposureCaptureRecord::where('certificate_id', $capture->certificate_id)
            ->where('record_type', 'causal_fold_ingress')->orderBy('id')->limit(1025)->get();
        if ($records->count() > 1024) {
            throw new LogicException('EXPOSURE_ORIGINAL_FOLD_CAPTURE_BOUND_EXCEEDED');
        }
        $receipts = [];
        $matched = [];
        foreach ($folds as $fold) {
            $captures = $records->filter(fn ($record) => $record->payload['causal_fold_receipt_id'] === (int) $fold->id);
            if ($fold->status === 'planned' && (int) $fold->attempt_count === 0 && $captures->isEmpty()
                && ! in_array((int) $fold->id, $originalIds, true)) {
                continue;
            }
            if ($captures->count() !== (int) $fold->attempt_count || $captures->isEmpty()) {
                throw new LogicException('EXPOSURE_ORIGINAL_FOLD_CAPTURE_INCOMPLETE');
            }
            foreach ($captures as $record) {
                $this->assertRecord($record);
                $body = $record->payload;
                if ($body['capture_record_id'] !== (int) $capture->id
                    || $body['experiment_id'] !== (int) $fold->agent_learning_causal_experiment_id
                    || $body['fold_index'] !== (int) $fold->fold_index
                    || $body['attempt_count'] < 1 || $body['attempt_count'] > (int) $fold->attempt_count
                    || $this->hash($body['request']) !== $body['request_hash']) {
                    throw new LogicException('EXPOSURE_ORIGINAL_FOLD_INGRESS_OWNER_DRIFT');
                }
                if ($fold->status === 'completed' && ($this->hash((array) $fold->request_payload) !== $body['request_hash']
                    || $fold->request_hash !== $body['request_hash'] || ! $fold->completed_at
                    || CarbonImmutable::parse($body['captured_at'])->greaterThan($fold->completed_at))) {
                    throw new LogicException('EXPOSURE_ORIGINAL_FOLD_PREPUBLICATION_PROOF_REQUIRED');
                }
                $inventory = app(SpecialistCouncilDataUseService::class)->capturedOriginalRequestIntervals($body['request'],
                    (int) $capture->payload['policy']['holding_fence_seconds']);
                if ($this->hash($inventory) !== $this->hash($body['inventory'])) {
                    throw new LogicException('EXPOSURE_CAPTURED_FOLD_SOURCE_BYTES_DRIFT');
                }
                $this->assertInventoryScope($inventory, $capture->payload);
                $this->assertRequestHolding($body['request'], $capture->payload['policy']);
                $isOriginal = in_array((int) $fold->id, $originalIds, true);
                if ($isOriginal && ($inventory['dataset_hash'] !== $manifest['bundle_hash']
                    || ($fold->status === 'completed' && $fold->dataset_hash !== $manifest['bundle_hash']))) {
                    throw new LogicException('EXPOSURE_VALIDATION_ORIGINAL_FOLD_DATA_MISMATCH');
                }
                foreach ($inventory['intervals'] as $interval) {
                    if (! $isOriginal && CarbonImmutable::parse($interval['start_inclusive'])->lessThan($until)
                        && CarbonImmutable::parse($interval['end_exclusive'])->greaterThan($from)) {
                        throw new LogicException('CANDIDATE_INTERSECTS_ORIGINAL_FOLD_PHYSICAL_EXPOSURE');
                    }
                }
                $matched[] = (int) $fold->id;
                $receipts[] = ['record_id' => (int) $record->id, 'fold_receipt_id' => (int) $fold->id,
                    'attempt_count' => $body['attempt_count'], 'request_hash' => $body['request_hash'],
                    'payload_hash' => $record->payload_hash, 'inventory_hash' => $this->hash($inventory)];
            }
        }
        if ($records->count() !== count($receipts)) {
            throw new LogicException('EXPOSURE_ORIGINAL_FOLD_CAPTURE_ORPHANED');
        }
        $matched = array_values(array_intersect(array_unique($matched), $originalIds));
        sort($matched);
        $expected = $originalIds;
        sort($expected);
        if ($matched !== $expected) {
            throw new LogicException('EXPOSURE_ORIGINAL_FOLD_CAPTURE_INCOMPLETE');
        }

        return ['receipts' => $receipts, 'original_fold_receipt_ids' => $expected];
    }

    private function modelExperiments(array $models): array
    {
        $agents = LabAgent::whereIn('model_version_id', $models)->pluck('id')->all();
        $ids = AgentLearningCausalExperiment::where(function ($query) use ($agents): void {
            $query->whereIn('guided_agent_id', $agents)->orWhereIn('blinded_agent_id', $agents)->orWhereIn('control_agent_id', $agents);
        })->orderBy('id')->limit(65)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (count($ids) > 64) {
            throw new LogicException('EXPOSURE_SOURCE_EXPERIMENT_ROSTER_BOUND_EXCEEDED');
        }

        return $ids;
    }

    /** A missing capture row cannot make an explicitly declared new scope look legacy at ingress. */
    private function declaredScopes(array $models): array
    {
        if (! Schema::hasTable('scoped_research_certificates')) {
            return [];
        }

        return ScopedResearchCertificate::where('record_type', 'preregistration')
            ->where('payload->design->authority_policy', self::AUTHORITY_POLICY)
            ->where(function ($query) use ($models): void {
                foreach ($models as $model) {
                    $query->orWhere('payload->source_snapshot->models->'.(int) $model.'->model_version_id', (int) $model);
                }
            })->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function registration(int $id): array
    {
        $registration = app(ScopedResearchCertificateService::class)->verifiedRegistration($id);
        if (($registration['design']['authority_policy'] ?? null) !== self::AUTHORITY_POLICY
            || ($registration['design']['source_hypothesis_only'] ?? false) === true) {
            throw new LogicException('EXPOSURE_ORIGINAL_PROSPECTIVE_DESIGN_REQUIRED');
        }

        return $registration;
    }

    private function policy(array $design): array
    {
        $policy = (array) ($design['exposure_policy'] ?? []);
        if (($policy['protocol'] ?? null) !== 'prospective_scoped_exposure_policy_v1'
            || ! is_int($policy['holding_fence_seconds'] ?? null) || $policy['holding_fence_seconds'] < 0
            || $policy['holding_fence_seconds'] > 31536000 || ($policy['execution_timeframe'] ?? null) !== 'M5'
            || ($policy['context_timeframes'] ?? null) !== ['H4', 'H1', 'M15']
            || ($policy['warmup_policy'] ?? null) !== 'all_original_closed_source_rows_inside_registered_window'
            || ($policy['selection_policy'] ?? null) !== 'frozen_before_first_event') {
            throw new LogicException('EXPOSURE_FROZEN_POLICY_REQUIRED');
        }

        return $policy;
    }

    private function assertRegistrationMatches(array $registration, array $capture): void
    {
        if ($registration['source_hash'] !== $capture['source_hash'] || $registration['design_hash'] !== $capture['design_hash']
            || $this->hash($registration['source_snapshot']) !== $capture['source_snapshot_hash']
            || $this->hash($this->policy($registration['design'])) !== $this->hash($capture['policy'])) {
            throw new LogicException('EXPOSURE_FROZEN_SOURCE_OR_DESIGN_DRIFT');
        }
    }

    private function assertInventoryScope(array $inventory, array $capture): void
    {
        foreach ($inventory['intervals'] as $interval) {
            if (CarbonImmutable::parse($interval['start_inclusive'])->lessThan($capture['coverage_start_inclusive'])
                || CarbonImmutable::parse($interval['end_exclusive'])->greaterThan($capture['coverage_end_exclusive'])
                || ! CarbonImmutable::parse($capture['capture_started_at'])->lessThan(CarbonImmutable::parse($interval['start_inclusive']))) {
                throw new LogicException('EXPOSURE_WARMUP_CONTEXT_OR_HOLDING_OUTSIDE_CAPTURE_SCOPE');
            }
        }
    }

    private function assertInventoryInOriginalWindow(array $inventory, array $design): void
    {
        foreach ((array) ($design['validation_windows'] ?? []) as $window) {
            $fits = true;
            foreach ($inventory['intervals'] as $interval) {
                if (CarbonImmutable::parse($interval['start_inclusive'])->lessThan($window['start_inclusive'])
                    || CarbonImmutable::parse($interval['end_exclusive'])->greaterThan($window['end_exclusive'])) {
                    $fits = false;
                }
            }
            if ($fits) {
                return;
            }
        }
        throw new LogicException('EXPOSURE_ORIGINAL_SOURCE_SPANS_UNREGISTERED_WINDOWS');
    }

    private function assertRequestHolding(array $request, array $policy): void
    {
        foreach (['max_holding_seconds', 'execution_policy.max_holding_seconds', 'specialist_council_runtime.account.max_holding_seconds'] as $path) {
            $seconds = data_get($request, $path);
            if ($seconds !== null && (! is_numeric($seconds) || (int) $seconds > $policy['holding_fence_seconds'])) {
                throw new LogicException('EXPOSURE_REQUEST_HOLDING_EXCEEDS_FROZEN_FENCE');
            }
        }
        foreach (['maximum_holding_bars', 'max_holding_bars', 'execution_policy.max_holding_bars',
            'prequential_confirmation.maximum_holding_bars', 'causal_confirmation.maximum_holding_bars'] as $path) {
            $bars = data_get($request, $path);
            if ($bars !== null && (! is_numeric($bars) || (int) $bars < 0
                || (int) $bars * 300 > $policy['holding_fence_seconds'])) {
                throw new LogicException('EXPOSURE_REQUEST_HOLDING_EXCEEDS_FROZEN_FENCE');
            }
        }
        $nativeContracts = [(array) ($request['specialist_council_runtime'] ?? []),
            (array) ($request['specialist_council_contract'] ?? [])];
        foreach ((array) ($request['strategies'] ?? []) as $strategy) {
            $nativeContracts[] = (array) ($strategy['specialist_council_contract'] ?? []);
        }
        foreach ($nativeContracts as $native) {
            foreach ((array) ($native['members'] ?? []) as $member) {
                $seconds = data_get($member, 'horizon.max_holding_seconds');
                if (! is_int($seconds) || $seconds < 0 || $seconds > $policy['holding_fence_seconds']) {
                    throw new LogicException('EXPOSURE_REQUEST_HOLDING_EXCEEDS_FROZEN_FENCE');
                }
            }
        }
        foreach ((array) data_get($request, 'policy_context.learning_confirmation_contracts', []) as $contract) {
            $bars = $contract['maximum_holding_bars'] ?? null;
            if (! is_int($bars) || $bars < 1 || $bars * 300 > $policy['holding_fence_seconds']) {
                throw new LogicException('EXPOSURE_REQUEST_HOLDING_EXCEEDS_FROZEN_FENCE');
            }
        }
    }

    private function append(int $certificate, string $type, ?int $run, string $key, array $payload): ResearchExposureCaptureRecord
    {
        $body = ['record_key' => $key, 'certificate_id' => $certificate, 'record_type' => $type,
            'evaluation_run_id' => $run, 'payload_hash' => $this->hash($payload), 'payload' => $payload,
            'recorded_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s')];

        return ResearchExposureCaptureRecord::create([...$body, 'server_seal' => $this->seal($body)]);
    }

    private function assertRecord(ResearchExposureCaptureRecord $row): void
    {
        $body = array_intersect_key($row->getAttributes(), array_flip(['record_key', 'certificate_id', 'record_type',
            'evaluation_run_id', 'payload_hash', 'recorded_at']));
        $body['certificate_id'] = (int) $row->certificate_id;
        $body['evaluation_run_id'] = $row->evaluation_run_id === null ? null : (int) $row->evaluation_run_id;
        $body['payload'] = $row->payload;
        if ($this->hash($row->payload) !== $row->payload_hash || ! hash_equals($row->server_seal, $this->seal($body))) {
            throw new LogicException('EXPOSURE_CAPTURE_SEAL_OR_PAYLOAD_DRIFT');
        }
    }

    private function hash(array $value): string
    {
        return $this->epochs->parameterHash($value);
    }

    private function seal(array $value): string
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true) ?: '';
        }
        if (strlen($key) < 32) {
            throw new LogicException('EXPOSURE_CAPTURE_SERVER_KEY_UNAVAILABLE');
        }

        return hash_hmac('sha256', self::PROTOCOL."\n".$this->hash($value), $key);
    }
}
