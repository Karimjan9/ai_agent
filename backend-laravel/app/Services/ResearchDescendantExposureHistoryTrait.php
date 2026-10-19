<?php

namespace App\Services;

use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use App\Models\ModelVersion;
use App\Models\ResearchExperimentWorkItem;
use App\Models\ResearchExposureCaptureRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Original derived ancestry, never a declaration that a tuned child was born cold. */
trait ResearchDescendantExposureHistoryTrait
{
    public const DESCENDANT_ORIGIN_PROTOCOL = 'canonical_four_arm_derived_origin_v1';

    /** Exists only while one canonical read/locked preparation invocation is on the stack. */
    private ?array $originalDescendantParentOperation = null;

    /**
     * Derive authority internally; a callback cannot supply a parent proof. The
     * fresh exit check precedes returning readiness or committing a birth, so
     * changed original files/products never escape under the entry proof.
     */
    public function withOriginalDescendantParentProof(int $certificateId, \Closure $operation): mixed
    {
        if ($this->originalDescendantParentOperation !== null) {
            if ($this->originalDescendantParentOperation['certificate_id'] !== $certificateId) {
                throw new LogicException('DESCENDANT_ORIGINAL_OPERATION_PARENT_SCOPE_MISMATCH');
            }

            return $operation();
        }
        $container = app();
        $ownerClass = ResearchWindowExposureInventoryService::class;
        $previousOwner = $container->isShared($ownerClass) && $container->resolved($ownerClass)
            ? $container->make($ownerClass) : null;
        $container->instance($ownerClass, $this);
        try {
            $proof = $this->rederiveOriginalDescendantParent($certificateId);
            $this->originalDescendantParentOperation = ['certificate_id' => $certificateId, ...$proof];
            $result = $operation();
            $fresh = $this->rederiveOriginalDescendantParent($certificateId);
            if ($this->hash($proof['prefix']) !== $this->hash($fresh['prefix'])) {
                throw new LogicException('DESCENDANT_ORIGINAL_OPERATION_PARENT_DRIFT');
            }

            return $result;
        } finally {
            $this->originalDescendantParentOperation = null;
            $container->forgetInstance($ownerClass);
            if ($previousOwner !== null) $container->instance($ownerClass, $previousOwner);
        }
    }

    /** Only the internally rederived proof may be reused inside its lexical operation. */
    public function inspectOriginalDescendantParent(int $certificateId): array
    {
        if ($this->originalDescendantParentOperation !== null) {
            if ($this->originalDescendantParentOperation['certificate_id'] !== $certificateId) {
                throw new LogicException('DESCENDANT_ORIGINAL_OPERATION_PARENT_SCOPE_MISMATCH');
            }

            return $this->originalDescendantParentOperation['certificate'];
        }

        return app(ScopedResearchCertificateService::class)->inspect($certificateId);
    }

    /** The canonical owner supplies a durable work identity, never caller-born model IDs. */
    public function createOriginalScopedDescendantModels(ResearchExperimentWorkItem $work): array
    {
        return DB::transaction(function () use ($work): array {
            $current = ResearchExperimentWorkItem::whereKey($work->id)->lockForUpdate()->firstOrFail();
            $native = app(DescendantScopedExecutionService::class);
            $native->assertLease($work);
            if (! app(AutonomousModeService::class)->enabledForCommit($work->symbol, $work->timeframe)) {
                throw new LogicException('AUTONOMOUS_MODE_STOPPED');
            }
            $result = $this->withOriginalDescendantParentProof(
                (int) data_get($current->payload, 'source_component_certificate_id'),
                function () use ($work, $current, $native): array {
            $prepared = app(ScopedDescendantCandidatePreparationService::class)->inspectWork($current);
            if (($prepared['executable'] ?? false) !== true
                || data_get($current->result, 'registered_descendant') !== null) {
                throw new LogicException('DESCENDANT_ORIGINAL_LEASED_PROPOSAL_REQUIRED');
            }
            $key = $this->hash([self::DESCENDANT_ORIGIN_PROTOCOL, (int) $current->id]);
            if (ResearchExposureCaptureRecord::where('record_key', $key)->exists()) {
                throw new LogicException('DESCENDANT_ORIGINAL_BIRTH_ALREADY_CREATED');
            }
            $proposal = $prepared['proposal'];
            $parent = $this->originalDescendantParentPrefix((int) $proposal['source_component_certificate_id']);
            $started = CarbonImmutable::now('UTC')->toIso8601String();
            $runHigh = (int) LabEvaluationRun::max('id');
            $models = [];
            foreach (DescendantScopedProofService::ARMS as $arm) {
                $native->assertLease($work);
                $source = ModelVersion::findOrFail($proposal['source_model_ids'][in_array($arm, ['P', 'P+U'], true) ? 'P' : 'P+T']);
                $metadata = app(EvolutionaryAuthorityFoundryService::class)->scopedChildMetadata($source,
                    ['work_item_id' => (int) $current->id, 'proposal_hash' => $proposal['proposal_hash'],
                        'arm' => $arm, 'source_component_certificate_id' => $proposal['source_component_certificate_id'],
                        'new_version' => $source->version.'-scoped-'.$current->id], $proposal['parameter_vectors'][$arm]);
                $models[$arm] = ModelVersion::create(['name' => 'scoped descendant '.$current->id.' '.$arm,
                    'strategy' => $source->strategy, 'version' => $source->version.'-scoped-'.$current->id,
                    'status' => 'testing', 'parameters' => $proposal['parameter_vectors'][$arm], 'metadata' => $metadata]);
            }
            $identities = $this->originalDescendantModelIdentities($models);
            $payload = ['protocol' => self::DESCENDANT_ORIGIN_PROTOCOL, 'work_item_id' => (int) $current->id,
                'work_receipt_id' => (int) $current->receipt->id, 'proposal_hash' => $proposal['proposal_hash'],
                'work_receipt_hash' => $this->hash((array) $current->receipt->payload),
                'source_component_certificate_id' => (int) $proposal['source_component_certificate_id'],
                'created_before_first_exposure_at' => $started, 'run_high_water' => $runHigh,
                'evaluator_release_hash' => app(LabImmutableEvidenceService::class)->codeHash(),
                'models' => $identities, 'ancestry_proof' => $parent,
                'ancestry_is_cold_untrained' => false, 'caller_model_ids_accepted' => false,
                'authority' => false, 'promotion_evidence' => false];
            $birth = $this->append((int) $proposal['source_component_certificate_id'], 'descendant_birth', null, $key, $payload);

            return ['models' => $models, 'birth_record_id' => (int) $birth->id,
                'protocol' => self::DESCENDANT_ORIGIN_PROTOCOL, 'authority' => false];
                });
            $native->assertLease($work);
            if (! app(AutonomousModeService::class)->enabledForCommit($work->symbol, $work->timeframe)) {
                throw new LogicException('AUTONOMOUS_MODE_STOPPED');
            }

            return $result;
        });
    }

    /** Reopen the actual canonical birth and original parent products, not an ancestry label. */
    private function originalDescendantHistoryContext(array $registration, ResearchExposureCaptureRecord $capture): array
    {
        return $this->withOriginalDescendantParentProof(
            (int) data_get($registration, 'design.subject.source_component_certificate_id'),
            fn (): array => $this->deriveOriginalDescendantHistoryContext($registration, $capture));
    }

    private function deriveOriginalDescendantHistoryContext(array $registration, ResearchExposureCaptureRecord $capture): array
    {
        if (($registration['scope'] ?? null) !== 'inheritance') {
            throw new LogicException('DESCENDANT_ORIGINAL_INHERITANCE_SCOPE_REQUIRED');
        }
        $roster = (array) data_get($registration, 'design.subject.arm_models');
        if (count($roster) !== 4 || array_diff(array_keys($roster), DescendantScopedProofService::ARMS) !== []) {
            throw new LogicException('DESCENDANT_ORIGINAL_EXACT_BIRTH_ROSTER_REQUIRED');
        }
        $models = []; $workIds = [];
        foreach ($roster as $arm => $identity) {
            $model = ModelVersion::findOrFail($identity['model_version_id'] ?? 0);
            $models[$arm] = $model;
            $workIds[] = (int) data_get($model->metadata, 'scoped_descendant_candidate.work_item_id');
        }
        if (count(array_unique($workIds)) !== 1 || $workIds[0] <= 0) {
            throw new LogicException('DESCENDANT_ORIGINAL_CANONICAL_WORK_ANCESTRY_REQUIRED');
        }
        $key = $this->hash([self::DESCENDANT_ORIGIN_PROTOCOL, $workIds[0]]);
        $birth = ResearchExposureCaptureRecord::where('record_key', $key)->sole();
        $this->assertRecord($birth);
        $payload = $birth->payload;
        $work = ResearchExperimentWorkItem::findOrFail($workIds[0]);
        $prepared = app(ScopedDescendantCandidatePreparationService::class)->inspectWork($work);
        $parentId = (int) data_get($registration, 'design.subject.source_component_certificate_id');
        if ($birth->record_type !== 'descendant_birth' || (int) $birth->certificate_id !== $parentId
            || ($payload['protocol'] ?? null) !== self::DESCENDANT_ORIGIN_PROTOCOL
            || ($prepared['executable'] ?? false) !== true
            || $payload['proposal_hash'] !== $prepared['proposal']['proposal_hash']
            || $payload['work_receipt_id'] !== (int) $work->receipt?->id
            || $payload['work_receipt_hash'] !== $this->hash((array) $work->receipt?->payload)
            || $payload['source_component_certificate_id'] !== $parentId
            || $payload['evaluator_release_hash'] !== app(LabImmutableEvidenceService::class)->codeHash()
            || $this->hash($payload['models']) !== $this->hash($this->originalDescendantModelIdentities($models))
            || $payload['ancestry_is_cold_untrained'] !== false || $payload['caller_model_ids_accepted'] !== false
            || CarbonImmutable::parse($birth->recorded_at)->greaterThan($capture->recorded_at)
            || (int) data_get($work->result, 'registered_descendant.trial_id') !== (int) $registration['source_id']
            || (int) data_get($work->result, 'registered_descendant.certificate.certificate_id') !== (int) $registration['certificate_id']) {
            throw new LogicException('DESCENDANT_ORIGINAL_BIRTH_OR_PROPOSAL_DRIFT');
        }
        $ids = array_column($payload['models'], 'model_version_id'); sort($ids);
        if ($ids !== $capture->payload['model_ids']
            || $this->hash((array) data_get($registration, 'design.subject.arm_parameters'))
                !== $this->hash($prepared['proposal']['parameter_vectors'])) {
            throw new LogicException('DESCENDANT_ORIGINAL_CAPTURE_MODEL_SCOPE_DRIFT');
        }
        $ancestry = $this->originalDescendantParentPrefix($parentId);
        if ($this->hash($ancestry) !== $this->hash($payload['ancestry_proof'])) {
            throw new LogicException('DESCENDANT_ORIGINAL_PARENT_PREFIX_DRIFT');
        }

        return ['owned_model_ids' => $ids,
            'origin_proof' => ['protocol' => self::DESCENDANT_ORIGIN_PROTOCOL,
                'birth_record_id' => (int) $birth->id, 'birth_payload_hash' => $birth->payload_hash,
                'work_item_id' => (int) $work->id, 'ancestry_is_cold_untrained' => false],
            'ancestry_proof' => $ancestry];
    }

    private function originalDescendantModelIdentities(array $models): array
    {
        $identities = [];
        foreach (DescendantScopedProofService::ARMS as $arm) {
            $model = $models[$arm]->fresh();
            $identities[$arm] = ['model_version_id' => (int) $model->id, 'strategy' => $model->strategy,
                'version' => $model->version, 'parameter_hash' => $this->hash((array) $model->parameters),
                'metadata_hash' => $this->hash(array_diff_key((array) $model->metadata, ['lifecycle_sync' => true])),
                'runtime_hash' => $this->hash(app(LabImmutableEvidenceService::class)->modelRuntimeBasis($model))];
        }
        return $identities;
    }

    /** Exact immutable parent prefix already named by the independent original issuer. */
    private function originalDescendantParentPrefix(int $certificateId): array
    {
        if ($this->originalDescendantParentOperation !== null) {
            if ($this->originalDescendantParentOperation['certificate_id'] !== $certificateId) {
                throw new LogicException('DESCENDANT_ORIGINAL_OPERATION_PARENT_SCOPE_MISMATCH');
            }

            return $this->originalDescendantParentOperation['prefix'];
        }

        return $this->rederiveOriginalDescendantParent($certificateId)['prefix'];
    }

    /** Protected only as an original-owner test seam; no caller proof enters this method. */
    protected function rederiveOriginalDescendantParent(int $certificateId): array
    {
        $registry = app(ScopedResearchCertificateService::class);
        $registration = $registry->verifiedRegistration($certificateId);
        $parent = $registry->inspect($certificateId);
        $proof = (array) data_get($parent, 'original_authority.component');
        if (($parent['valid'] ?? false) !== true || ($parent['scope'] ?? null) !== 'component'
            || ($proof['confirmed'] ?? false) !== true || ! is_int($parent['authority_record_id'] ?? null)
            || ($proof['authority_type'] ?? null) !== 'context_bound_research_component'
            || ($parent['source_hash'] ?? null) !== $registration['source_hash']
            || ($parent['design_hash'] ?? null) !== $registration['design_hash']) {
            throw new LogicException('DESCENDANT_ORIGINAL_PARENT_ISSUER_REQUIRED');
        }
        $windows = $proof['original_windows'] ?? null;
        if (! is_array($windows) || ! array_is_list($windows) || count($windows) < 6 || count($windows) > 9) {
            throw new LogicException('DESCENDANT_ORIGINAL_PARENT_PRODUCT_PREFIX_REQUIRED');
        }
        $products = []; $seen = [];
        $immutable = app(LabImmutableEvidenceService::class);
        foreach ($windows as $window) {
            $witnesses = $window['original_arm_witnesses'] ?? [];
            if (array_keys($witnesses) !== ['candidate', 'control']) {
                throw new LogicException('DESCENDANT_ORIGINAL_PARENT_PRODUCT_PREFIX_REQUIRED');
            }
            foreach ($witnesses as $arm => $witness) {
                $run = LabEvaluationRun::findOrFail($witness['run_id'] ?? 0);
                $expectedModel = $proof[$arm === 'candidate' ? 'candidate_model_version_id' : 'control_model_version_id'] ?? 0;
                $actual = ['run_id' => (int) $run->id, 'request_hash' => $run->request_hash,
                    'response_hash' => $run->response_hash, 'model_version_id' => (int) $run->model_version_id,
                    'data_hash' => $run->data_hash, 'code_hash' => $run->code_hash];
                if (isset($seen[$run->id]) || $this->hash($actual) !== $this->hash($witness)
                    || $run->status !== 'completed' || (int) $run->model_version_id !== (int) $expectedModel
                    || ($immutable->learningEligibility($run)['complete'] ?? false) !== true
                    || ! $immutable->verifiedModelRuntimeIdentity($run)) {
                    throw new LogicException('DESCENDANT_ORIGINAL_PARENT_PRODUCT_PREFIX_DRIFT');
                }
                $seen[$run->id] = true; $artifacts = [];
                foreach (['evaluation_request', 'evaluation_response'] as $kind) {
                    $artifact = LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', $kind)->sole();
                    if (! $artifact->storage_path || data_get($artifact->metadata, 'storage_protocol') !== 'compressed_artifact_v2'
                        || ($kind === 'evaluation_response' && $artifact->sha256 !== $run->response_hash)
                        || ! is_array($immutable->readArtifactPayload($artifact))) {
                        throw new LogicException('DESCENDANT_ORIGINAL_PARENT_RAW_BYTES_REQUIRED');
                    }
                    $artifacts[$kind] = ['artifact_id' => (int) $artifact->id, 'sha256' => $artifact->sha256];
                }
                $products[] = ['run' => $actual, 'artifacts' => $artifacts];
            }
        }
        $prefix = ['certificate_id' => $certificateId, 'authority_record_id' => (int) $parent['authority_record_id'],
            'source_hash' => $registration['source_hash'], 'design_hash' => $registration['design_hash'],
            'original_products' => $products, 'derived_ancestry' => true, 'cold_untrained' => false];

        return ['certificate' => $parent, 'prefix' => $prefix];
    }
}
