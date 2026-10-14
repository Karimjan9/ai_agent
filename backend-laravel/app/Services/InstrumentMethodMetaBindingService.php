<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\ModelVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/** Optional, original-issuer-owned finite model. It cannot bind an already sealed policy or a native member. */
class InstrumentMethodMetaBindingService
{
    public const PROTOCOL = 'instrument_method_meta_model_binding_v1';
    public const METADATA = 'instrument_method_meta_model';
    public const PROOF_METADATA = 'instrument_method_meta_model_attachment';
    public const TRANSFER_PROTOCOL = 'instrument_method_meta_model_transfer_v1';
    public const PHYSICAL_FIELDS = ['symbol', 'timeframe', 'strategy', 'parameters_hash', 'account_risk_hash', 'execution_hash', 'clock_seconds'];

    public function __construct(private InstrumentMethodMetaModelService $models,
        private ResearchPaperEpochContractService $hashes) {}

    /** Reopen the issuer and target seal, never infer an asset from copied metadata. */
    public function assetForModel(ModelVersion $model): ?array
    {
        $metadata = (array) $model->metadata;
        if (! array_key_exists(self::METADATA, $metadata) && ! array_key_exists(self::PROOF_METADATA, $metadata)) return null;
        $ref = $metadata[self::METADATA] ?? null;
        $this->need(is_array($ref) && array_diff(array_keys($ref), ['protocol', 'model_hash']) === []
            && count($ref) === 2 && ($ref['protocol'] ?? null) === self::PROTOCOL
            && is_string($ref['model_hash'] ?? null), 'META_BINDING_EXACT_MODEL_REFERENCE_REQUIRED');
        $export = $this->export($ref['model_hash']);
        $this->attachment($model, $export);
        $this->sameModel($model, $export);
        $this->declaredScope($model, $export);
        $proof = $metadata[self::PROOF_METADATA];
        return ['model_hash' => $ref['model_hash'], 'reference' => $ref,
            'attachment_hash' => $proof['attachment_hash'], 'physical_basis_hash' => $proof['physical_basis_hash'],
            'original_training_binding_hash' => $proof['original_training_binding_hash']];
    }

    /** Reissue a fresh target attachment; neither the source seal nor trained weights are copied or edited. */
    public function reattachToUnusedChild(int $sourceModelId, int $targetModelId): array
    {
        try {
            return DB::transaction(function () use ($sourceModelId, $targetModelId): array {
                $source = ModelVersion::whereKey($sourceModelId)->lockForUpdate()->first();
                $target = ModelVersion::whereKey($targetModelId)->lockForUpdate()->first();
                $this->need($source !== null && $target !== null && $sourceModelId < $targetModelId,
                    'META_BINDING_ORIGINAL_PARENT_CHILD_REQUIRED');
                $this->need($this->unused($target), 'META_BINDING_UNUSED_PLAIN_MODEL_REQUIRED');
                $asset = $this->assetForModel($source);
                if ($asset === null) return ['status' => 'no_meta_asset', 'source_model_id' => $sourceModelId,
                    'model_id' => $targetModelId, ...$this->ceiling()];
                $metadata = (array) $target->metadata;
                $this->beforeOwnSeal($target, $metadata);
                $export = $this->export($asset['model_hash']);
                $this->sameModel($target, $export);
                $this->declaredScope($target, $export);
                $this->need($this->hash($this->physical($source)) === $this->hash($this->physical($target)),
                    'META_BINDING_TRANSFER_PHYSICAL_DRIFT');
                $sourceProof = (array) $source->metadata[self::PROOF_METADATA];
                $sourceTime = $this->stamp($sourceProof['attached_at']);
                $now = now()->utc()->toIso8601String();
                $this->need($target->created_at !== null && $source->created_at !== null
                    && $target->created_at->greaterThanOrEqualTo($source->created_at)
                    && $this->stamp($now)->greaterThanOrEqualTo($sourceTime)
                    && $this->stamp($now)->greaterThanOrEqualTo($target->created_at), 'META_BINDING_TRANSFER_CHRONOLOGY_REQUIRED');
                $depth = (int) data_get($sourceProof, 'transfer.depth', 0) + 1;
                $this->need($depth <= 16, 'META_BINDING_TRANSFER_DEPTH_REQUIRED');
                $transfer = ['protocol' => self::TRANSFER_PROTOCOL, 'source_model_id' => $sourceModelId,
                    'source_attachment_hash' => $asset['attachment_hash'], 'depth' => $depth];
                if (array_key_exists(self::METADATA, $metadata) || array_key_exists(self::PROOF_METADATA, $metadata)) {
                    $existing = $this->assetForModel($target);
                    $this->need($existing !== null && $existing['model_hash'] === $asset['model_hash']
                        && $this->hash((array) data_get($metadata, self::PROOF_METADATA.'.transfer', [])) === $this->hash($transfer),
                        'META_BINDING_TRANSFER_ATTACHMENT_IMMUTABLE');
                    return ['status' => 'original_meta_model_transfer_exists', 'source_model_id' => $sourceModelId,
                        'model_id' => $targetModelId, 'protocol' => self::PROTOCOL, ...$existing, ...$this->ceiling()];
                }
                $ref = ['protocol' => self::PROTOCOL, 'model_hash' => $asset['model_hash']];
                $proof = ['protocol' => self::PROTOCOL, 'target_model_id' => $targetModelId, 'model_hash' => $asset['model_hash'],
                    'physical_basis_hash' => $this->hash($this->physical($target)),
                    'original_training_binding_hash' => $asset['original_training_binding_hash'],
                    'attached_at' => $now, 'attached_before_own_policy' => true, 'transfer' => $transfer, ...$this->ceiling()];
                $proof['attachment_hash'] = $this->hash($proof); $proof['server_seal'] = $this->seal($proof);
                $target->update(['metadata' => [...$metadata, self::METADATA => $ref, self::PROOF_METADATA => $proof]]);
                $verified = $this->assetForModel($target->fresh());
                return ['status' => 'reattached_meta_model_prospectively', 'source_model_id' => $sourceModelId,
                    'model_id' => $targetModelId, 'protocol' => self::PROTOCOL, ...$verified, ...$this->ceiling()];
            });
        } catch (Throwable $error) { return ['status' => 'blocked', 'reason' => preg_match('/^(META_BINDING|META_MODEL|METHOD_CANDIDATE)_[A-Z0-9_]+$/D', $error->getMessage())
            ? $error->getMessage() : 'META_BINDING_ORIGINAL_OWNER_REFUSED', ...$this->ceiling()]; }
    }

    public function attachUnusedModel(int $modelId, string $modelHash): array
    {
        try {
            return DB::transaction(function () use ($modelId, $modelHash): array {
                $target = ModelVersion::whereKey($modelId)->lockForUpdate()->first();
                $this->need($target !== null && $this->unused($target), 'META_BINDING_UNUSED_PLAIN_MODEL_REQUIRED');
                $metadata = (array) $target->metadata;
                $this->beforeOwnSeal($target, $metadata);
                $this->physical($target);
                $export = $this->export($modelHash);
                $this->sameModel($target, $export);
                $ref = ['protocol' => self::PROTOCOL, 'model_hash' => $modelHash];
                if (array_key_exists(self::METADATA, $metadata) || array_key_exists(self::PROOF_METADATA, $metadata)) {
                    $existing = $metadata[self::METADATA] ?? null;
                    $this->need(is_array($existing) && count($existing) === 2 && $this->hash($existing) === $this->hash($ref),
                        'META_BINDING_MODEL_REFERENCE_IMMUTABLE');
                    $this->attachment($target, $export);
                    return ['status' => 'original_meta_model_binding_exists', 'model_id' => $modelId, ...$ref, ...$this->ceiling()];
                }
                $proof = ['protocol' => self::PROTOCOL, 'target_model_id' => $modelId, 'model_hash' => $modelHash,
                    'physical_basis_hash' => $this->hash($this->physical($target)),
                    'original_training_binding_hash' => $this->hash($export['artifact']['binding']),
                    'attached_at' => now()->utc()->toIso8601String(),
                    'attached_before_own_policy' => true, ...$this->ceiling()];
                $proof['attachment_hash'] = $this->hash($proof); $proof['server_seal'] = $this->seal($proof);
                $target->update(['metadata' => [...$metadata, self::METADATA => $ref, self::PROOF_METADATA => $proof]]);
                $this->attachment($target->fresh(), $export);
                return ['status' => 'attached_meta_model_prospectively', 'model_id' => $modelId, ...$ref, ...$this->ceiling()];
            });
        } catch (Throwable $error) { return ['status' => 'blocked', 'reason' => preg_match('/^(META_BINDING|META_MODEL|METHOD_CANDIDATE)_[A-Z0-9_]+$/D', $error->getMessage())
            ? $error->getMessage() : 'META_BINDING_ORIGINAL_OWNER_REFUSED', ...$this->ceiling()]; }
    }

    /** The actual evaluation payload supplies seven physical facts; original training references are never substituted. */
    public function forModel(ModelVersion $model, array $request): ?array
    {
        $metadata = (array) $model->metadata;
        if (! array_key_exists(self::METADATA, $metadata) && ! array_key_exists(self::PROOF_METADATA, $metadata)) return null;
        $ref = $metadata[self::METADATA] ?? null;
        $this->need(is_array($ref) && count($ref) === 2 && ($ref['protocol'] ?? null) === self::PROTOCOL
            && isset($ref['model_hash']) && array_diff(array_keys($ref), ['protocol', 'model_hash']) === [], 'META_BINDING_EXACT_MODEL_REFERENCE_REQUIRED');
        $export = $this->export($ref['model_hash']); $this->attachment($model, $export);
        foreach (['strategies', 'specialist_council_contract', 'portfolio_members', 'composition_runtime_contract',
            'runtime_ensemble_policy', 'instrument_research_assignment', 'native_spread_context_study_contract',
            'native_reachability_depth_audit_contract', 'native_standalone_qualification', 'specialist_context_contract', 'mtf_pilot'] as $port) {
            $this->need(empty($request[$port]), 'META_BINDING_ORDINARY_EVALUATION_REQUIRED');
        }
        // The ordinary request schema does not transport these top-level controls.
        // Refuse them instead of hashing values which Python would discard.
        foreach (['hard_limits', 'risk_governor', 'risk_policy', 'capital_policy', 'capital_allocation'] as $port) {
            $this->need(! array_key_exists($port, $request), 'META_BINDING_UNSUPPORTED_TOP_LEVEL_CONTROL');
        }
        $this->need(! $this->hashes->researchInputHasPaperData($request), 'META_BINDING_PRE2026_EVALUATION_REQUIRED');
        $training = $export['artifact']['binding'];
        $this->sameModel($model, $export);
        try {
            $requestParameterHash = is_array($request['parameters'] ?? null) && is_string($request['strategy'] ?? null)
                ? $this->projectedHash($request['strategy'], $request['parameters'], $request['base_strategy'] ?? null) : null;
            $sameFamily = $this->models->physicalParameterFamily($request['strategy'], $request['base_strategy'] ?? null)
                === $this->models->physicalParameterFamily($model->strategy, data_get($model->metadata, 'base_strategy'));
        } catch (Throwable) { throw new LogicException('META_BINDING_ACTUAL_MODEL_PARAMETERS_REQUIRED'); }
        $this->need(($request['strategy'] ?? null) === $model->strategy
            && is_array($request['parameters'] ?? null) && $sameFamily
            && $requestParameterHash
                === $this->projectedHash($model->strategy, (array) $model->parameters, data_get($model->metadata, 'base_strategy')),
            'META_BINDING_ACTUAL_MODEL_PARAMETERS_REQUIRED');
        $execution = $request['execution_contract'] ?? null;
        $this->need(is_array($execution) && ($execution['protocol'] ?? null) === ExecutionContractService::PROTOCOL
            && ($execution['version'] ?? null) === ExecutionContractService::VERSION
            && is_array($execution['parameters'] ?? null)
            && app(ExecutionContractService::class)->hashParameters($execution['parameters']) === ($execution['execution_hash'] ?? null)
            && ($execution['symbol'] ?? null) === ($request['symbol'] ?? null)
            && ($execution['timeframe'] ?? null) === ($request['timeframe'] ?? null), 'META_BINDING_ACTUAL_EXECUTION_REQUIRED');
        if (array_key_exists('execution', $request)) {
            $this->need(is_array($request['execution'])
                && app(ExecutionContractService::class)->hashParameters($request['execution']) === $execution['execution_hash'],
                'META_BINDING_ACTUAL_EXECUTION_REQUIRED');
        }
        $duration = ['M1' => 60, 'M5' => 300, 'M15' => 900, 'M30' => 1800, 'H1' => 3600, 'H4' => 14400,
            'D1' => 86400][$request['timeframe'] ?? ''] ?? null;
        $this->need($duration !== null, 'META_BINDING_ACTUAL_CLOCK_REQUIRED');
        $evaluation = ['symbol' => $request['symbol'] ?? null, 'timeframe' => $request['timeframe'] ?? null,
            'strategy' => $request['strategy'], 'parameters_hash' => $requestParameterHash,
            'account_risk_hash' => $this->models->physicalAccountRiskHash($request),
            'execution_hash' => $execution['execution_hash'], 'clock_seconds' => $duration];
        foreach (self::PHYSICAL_FIELDS as $field) $this->need($evaluation[$field] === ($field === 'parameters_hash'
            ? $export['parameter_projection']['projected_parameters_hash'] : ($training[$field] ?? null)), 'META_BINDING_PHYSICAL_EVALUATION_DRIFT');
        return ['artifact' => $export['artifact'], 'expected_training_binding' => $export['expected_binding'],
            'evaluation_binding' => $evaluation, 'parameter_projection' => $export['parameter_projection']];
    }

    /** Sparse asset ownership is separately attested before every future compiled request. */
    private function attachment(ModelVersion $model, array $export, int $hops = 0): void
    {
        $this->need($hops <= 16, 'META_BINDING_TRANSFER_DEPTH_REQUIRED');
        $proof = (array) data_get($model->metadata, self::PROOF_METADATA, []);
        $ref = data_get($model->metadata, self::METADATA);
        $this->need(is_array($ref) && count($ref) === 2 && $this->hash($ref) === $this->hash(['protocol' => self::PROTOCOL, 'model_hash' => $export['artifact']['artifact_hash']]),
            'META_BINDING_EXACT_MODEL_REFERENCE_REQUIRED');
        $this->need(($proof['protocol'] ?? null) === self::PROTOCOL && ($proof['target_model_id'] ?? null) === (int) $model->id
            && ($proof['model_hash'] ?? null) === $ref['model_hash'] && ($proof['attached_before_own_policy'] ?? null) === true
            && is_string($proof['attached_at'] ?? null)
            && ($proof['attachment_hash'] ?? null) === $this->hash(array_diff_key($proof, ['attachment_hash' => true, 'server_seal' => true]))
            && is_string($proof['server_seal'] ?? null)
            && hash_equals($this->seal(array_diff_key($proof, ['server_seal' => true])), $proof['server_seal'])
            && ($proof['physical_basis_hash'] ?? null) === $this->hash($this->physical($model))
            && ($proof['original_training_binding_hash'] ?? null) === $this->hash($export['artifact']['binding']),
            'META_BINDING_ORIGINAL_ATTACHMENT_REQUIRED');
        foreach ($this->ceiling() as $field => $value) $this->need(($proof[$field] ?? null) === $value, 'META_BINDING_AUTHORITY_FORBIDDEN');
        if (array_key_exists('transfer', $proof)) {
            $transfer = $proof['transfer'];
            $this->need(is_array($transfer) && array_diff(array_keys($transfer), ['protocol', 'source_model_id', 'source_attachment_hash', 'depth']) === []
                && ($transfer['protocol'] ?? null) === self::TRANSFER_PROTOCOL && is_int($transfer['source_model_id'] ?? null)
                && $transfer['source_model_id'] > 0 && $transfer['source_model_id'] < (int) $model->id
                && is_int($transfer['depth'] ?? null) && $transfer['depth'] >= 1 && $transfer['depth'] <= 16,
                'META_BINDING_ORIGINAL_TRANSFER_REQUIRED');
            $source = ModelVersion::find($transfer['source_model_id']);
            $this->need($source !== null, 'META_BINDING_ORIGINAL_TRANSFER_REQUIRED');
            $this->attachment($source, $export, $hops + 1);
            $this->sameModel($source, $export);
            $this->declaredScope($source, $export);
            $sourceProof = (array) data_get($source->metadata, self::PROOF_METADATA, []);
            $this->need($transfer['source_attachment_hash'] === $sourceProof['attachment_hash']
                && $transfer['depth'] === (int) data_get($sourceProof, 'transfer.depth', 0) + 1
                && $this->hash($this->physical($source)) === $proof['physical_basis_hash'], 'META_BINDING_ORIGINAL_TRANSFER_REQUIRED');
            $this->need($source->created_at !== null && $model->created_at !== null
                && $model->created_at->greaterThanOrEqualTo($source->created_at)
                && $this->stamp($proof['attached_at'])->greaterThanOrEqualTo($this->stamp($sourceProof['attached_at']))
                && $this->stamp($proof['attached_at'])->greaterThanOrEqualTo($model->created_at), 'META_BINDING_TRANSFER_CHRONOLOGY_REQUIRED');
        }
    }

    private function beforeOwnSeal(ModelVersion $model, array $metadata): void
    {
        $this->need(data_get($metadata, InstrumentMethodResearchBindingService::METADATA) === null
            && data_get($metadata, AgentMethodGenerationService::METADATA) === null
            && data_get($metadata, 'instrument_method_candidate') === null
            && data_get($metadata, AgentMethodStudyPreparationService::METADATA) === null
            && ! DB::table('agent_method_policy_revisions')->where('agent_key', 'model:'.$model->id)->exists(),
            'META_BINDING_MUST_PRECEDE_OWN_POLICY_SEAL');
    }

    private function declaredScope(ModelVersion $model, array $export): void
    {
        foreach (['lab_symbol' => 'symbol', 'lab_timeframe' => 'timeframe'] as $key => $field) {
            if (array_key_exists($key, (array) $model->metadata)) $this->need($model->metadata[$key] === $export['artifact']['binding'][$field],
                'META_BINDING_TRANSFER_SCOPE_DRIFT');
        }
        $execution = data_get($model->metadata, 'execution_contract');
        if ($execution !== null) $this->need(is_array($execution)
            && ($execution['execution_hash'] ?? null) === $export['artifact']['binding']['execution_hash'], 'META_BINDING_TRANSFER_SCOPE_DRIFT');
    }

    private function stamp(mixed $value): CarbonImmutable
    {
        try {
            if ($value instanceof \DateTimeInterface) return CarbonImmutable::instance($value)->utc();
            $this->need(is_string($value) && preg_match('/(Z|[+-][0-9]{2}:[0-9]{2})$/D', $value) === 1,
                'META_BINDING_TRANSFER_CHRONOLOGY_REQUIRED');
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) { throw new LogicException('META_BINDING_TRANSFER_CHRONOLOGY_REQUIRED'); }
    }
    private function export(string $hash): array
    {
        $this->need(preg_match('/^[a-f0-9]{64}$/D', $hash) === 1, 'META_BINDING_ORIGINAL_MODEL_HASH_REQUIRED');
        $export = $this->models->exportOriginalModel($hash, true);
        $this->need(($export['status'] ?? null) === 'exported_diagnostic', $export['reason'] ?? 'META_BINDING_ORIGINAL_FITTED_MODEL_REQUIRED');
        return $export;
    }
    private function sameModel(ModelVersion $model, array $export): void
    {
        $training = $export['artifact']['binding'];
        $this->need(($training['strategy'] ?? null) === $model->strategy
            && $this->models->physicalParameterFamily($model->strategy, data_get($model->metadata, 'base_strategy'))
                === $this->models->physicalParameterFamily($export['parameter_projection']['strategy'], $export['parameter_projection']['base_strategy'])
            && ($export['parameter_projection']['projected_parameters_hash'] ?? null)
                === $this->projectedHash($model->strategy, (array) $model->parameters, data_get($model->metadata, 'base_strategy')),
            'META_BINDING_ORIGINAL_PHYSICAL_MODEL_REQUIRED');
    }
    private function projectedHash(string $strategy, array $parameters, ?string $baseStrategy): string
    {
        return $this->hash($this->models->projectPhysicalParameters($strategy, $parameters, $baseStrategy));
    }
    private function physical(ModelVersion $model): array
    {
        $fields = ['base_strategy', 'architecture', 'strategy_architecture', 'tactic', 'tactic_contract',
            'risk_governor', 'trade_management', 'execution_contract', 'agent_constitution'];
        $view = clone $model; $metadata = (array) $view->metadata;
        unset($metadata[self::METADATA], $metadata[self::PROOF_METADATA]); $view->metadata = $metadata;
        app(AgentMethodCandidateService::class)->baseline($view);
        return ['strategy' => $model->strategy, 'parameters' => (array) $model->parameters,
            'components' => array_intersect_key($metadata, array_flip($fields))];
    }
    private function unused(ModelVersion $model): bool
    {
        return ! LabAgent::where('model_version_id', $model->id)->exists() && ! LabEvaluationRun::where('model_version_id', $model->id)->exists()
            && $model->promoted_at === null && $model->invalidated_at === null && in_array($model->status, ['draft', 'testing'], true);
    }
    private function seal(array $body): string
    {
        $key = config('app.key'); $this->need(is_string($key) && $key !== '', 'META_BINDING_SERVER_KEY_REQUIRED');
        return hash_hmac('sha256', self::PROTOCOL.':'.$this->hash($body), $key);
    }
    private function hash(array $body): string { return $this->hashes->parameterHash($body); }
    private function ceiling(): array { return ['research_only' => true, 'out_of_sample' => false, 'confirmation_proven' => false,
        'independent_validation' => false, 'economic_authority' => false, 'paper_authority' => false,
        'mastery_proven' => false, 'skill_credit' => false, 'promotion_evidence' => false, 'live_authority' => false]; }
    private function need(bool $condition, string $reason): void { if (! $condition) throw new LogicException($reason); }
}
