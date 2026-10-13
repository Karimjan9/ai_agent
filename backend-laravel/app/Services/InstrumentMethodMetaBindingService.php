<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\ModelVersion;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/** Optional, original-issuer-owned finite model. It cannot bind an already sealed policy or a native member. */
class InstrumentMethodMetaBindingService
{
    public const PROTOCOL = 'instrument_method_meta_model_binding_v1';
    public const METADATA = 'instrument_method_meta_model';
    public const PROOF_METADATA = 'instrument_method_meta_model_attachment';
    public const PHYSICAL_FIELDS = ['symbol', 'timeframe', 'strategy', 'parameters_hash', 'account_risk_hash', 'execution_hash', 'clock_seconds'];

    public function __construct(private InstrumentMethodMetaModelService $models,
        private ResearchPaperEpochContractService $hashes) {}

    public function attachUnusedModel(int $modelId, string $modelHash): array
    {
        try {
            return DB::transaction(function () use ($modelId, $modelHash): array {
                $target = ModelVersion::whereKey($modelId)->lockForUpdate()->first();
                $this->need($target !== null && $this->unused($target), 'META_BINDING_UNUSED_PLAIN_MODEL_REQUIRED');
                $metadata = (array) $target->metadata;
                $this->need(data_get($metadata, InstrumentMethodResearchBindingService::METADATA) === null
                    && data_get($metadata, AgentMethodGenerationService::METADATA) === null
                    && data_get($metadata, 'instrument_method_candidate') === null
                    && data_get($metadata, AgentMethodStudyPreparationService::METADATA) === null
                    && ! DB::table('agent_method_policy_revisions')->where('agent_key', 'model:'.$modelId)->exists(),
                    'META_BINDING_MUST_PRECEDE_OWN_POLICY_SEAL');
                $this->physical($target);
                $export = $this->export($modelHash);
                $this->sameModel($target, $export['artifact']['binding']);
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
        $this->sameModel($model, $training);
        $this->need(($request['strategy'] ?? null) === $model->strategy
            && is_array($request['parameters'] ?? null)
            && $this->hash($request['parameters']) === $this->hash((array) $model->parameters), 'META_BINDING_ACTUAL_MODEL_PARAMETERS_REQUIRED');
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
            'strategy' => $request['strategy'], 'parameters_hash' => $this->hash($request['parameters']),
            'account_risk_hash' => $this->models->physicalAccountRiskHash($request),
            'execution_hash' => $execution['execution_hash'], 'clock_seconds' => $duration];
        foreach (self::PHYSICAL_FIELDS as $field) $this->need($evaluation[$field] === ($training[$field] ?? null), 'META_BINDING_PHYSICAL_EVALUATION_DRIFT');
        return ['artifact' => $export['artifact'], 'expected_training_binding' => $export['expected_binding'],
            'evaluation_binding' => $evaluation];
    }

    /** Sparse asset ownership is separately attested before every future compiled request. */
    private function attachment(ModelVersion $model, array $export): void
    {
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
    }
    private function export(string $hash): array
    {
        $this->need(preg_match('/^[a-f0-9]{64}$/D', $hash) === 1, 'META_BINDING_ORIGINAL_MODEL_HASH_REQUIRED');
        $export = $this->models->exportOriginalModel($hash);
        $this->need(($export['status'] ?? null) === 'exported_diagnostic', $export['reason'] ?? 'META_BINDING_ORIGINAL_FITTED_MODEL_REQUIRED');
        return $export;
    }
    private function sameModel(ModelVersion $model, array $training): void
    {
        $this->need(($training['strategy'] ?? null) === $model->strategy
            && ($training['parameters_hash'] ?? null) === $this->hash((array) $model->parameters), 'META_BINDING_ORIGINAL_PHYSICAL_MODEL_REQUIRED');
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
