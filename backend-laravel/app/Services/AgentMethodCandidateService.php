<?php

namespace App\Services;

use App\Models\LabEvaluationRun;
use App\Models\ModelVersion;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/** An explicit next research candidate, with original learning lineage and no scheduler authority. */
class AgentMethodCandidateService
{
    public const PROTOCOL = 'agent_method_candidate_v1';
    public const REBASE_PROTOCOL = 'fresh_model_method_learning_rebase_v1';
    private const MAX_LINEAGE = 8;
    private const BASELINE_METADATA = ['base_strategy', 'architecture', 'strategy_architecture', 'tactic', 'tactic_contract',
        'risk_governor', 'trade_management', 'execution_contract', 'agent_constitution', 'lab_symbol', 'lab_timeframe'];

    public function __construct(
        private AgentMethodLearningService $learning,
        private InstrumentMethodResearchBindingService $bindings,
        private StrategyParameterSchemaService $schemas,
        private ResearchPaperEpochContractService $hashes,
        private LabImmutableEvidenceService $evidence,
    ) {}

    public function evolve(string $sourcePolicyHash, int $revision, int $maxRecipes = 3): array
    {
        try {
            return DB::transaction(function () use ($sourcePolicyHash, $revision, $maxRecipes): array {
                $row = DB::table('agent_method_policy_revisions')->where('policy_hash', $sourcePolicyHash)->lockForUpdate()->first();
                $this->need($row !== null, 'METHOD_CANDIDATE_SOURCE_POLICY_REQUIRED');
                $policy = $this->learning->policy($sourcePolicyHash);
                $this->need(($policy['status'] ?? null) !== 'blocked' && preg_match('/^model:([1-9][0-9]*)$/D', $policy['agent_key'] ?? '', $match) === 1,
                    'METHOD_CANDIDATE_SOURCE_MODEL_OWNER_REQUIRED');
                $source = ModelVersion::whereKey((int) $match[1])->lockForUpdate()->first();
                $this->need($source !== null, 'METHOD_CANDIDATE_SOURCE_MODEL_REQUIRED');
                $baseline = $this->baseline($source);
                $sourceBinding = data_get($source->metadata, InstrumentMethodResearchBindingService::METADATA);
                $this->need(is_array($sourceBinding) && ($sourceBinding['agent_key'] ?? null) === $policy['agent_key']
                    && ($sourceBinding['policy_hash'] ?? null) === $sourcePolicyHash, 'METHOD_CANDIDATE_SOURCE_DECLARATION_REQUIRED');
                // The proposal owner reopens every original artifact and experience; no caller statistics enter this path.
                $proposal = $this->learning->proposePolicy($sourcePolicyHash, $revision, $maxRecipes);
                $this->need(($proposal['proposal_status'] ?? null) === 'frozen_diagnostic_proposal'
                    && ($proposal['learning_provenance']['protocol'] ?? null) === 'frozen_method_learning_exposure_v1',
                    'METHOD_CANDIDATE_VERIFIED_LEARNING_PROPOSAL_REQUIRED');
                $proposalHash = $proposal['policy_hash'];
                $depth = (int) data_get($source->metadata, 'instrument_method_candidate.lineage_depth', 0) + 1;
                $this->need($depth <= self::MAX_LINEAGE, 'METHOD_CANDIDATE_LINEAGE_BUDGET_EXCEEDED');
                $semantics = $this->hash(['source_policy_hash' => $sourcePolicyHash, 'rules' => $proposal['rules'],
                    'default_action' => $proposal['default_action'], 'library_hash' => $proposal['library_hash'],
                    'source_experiences' => $proposal['learning_provenance']['sources']]);
                $proof = ['protocol' => self::PROTOCOL, 'source_model_id' => (int) $source->id,
                    'source_policy_hash' => $sourcePolicyHash, 'proposal_policy_hash' => $proposalHash,
                    'baseline_hash' => $this->hash($baseline), 'library_hash' => $proposal['library_hash'],
                    'learning_semantics_hash' => $semantics, 'lineage_depth' => $depth, ...$this->ceiling()];
                $key = $this->hash($proof);
                $proof['candidate_key'] = $key;
                // A different proposal revision label cannot create another candidate for the same original learning question.
                $existing = ModelVersion::where('metadata->instrument_method_candidate->source_policy_hash', $sourcePolicyHash)
                    ->where('metadata->instrument_method_candidate->learning_semantics_hash', $semantics)
                    ->where('metadata->instrument_method_candidate->baseline_hash', $proof['baseline_hash'])->lockForUpdate()->first();
                if ($existing) {
                    $targetPolicyHash = data_get($existing->metadata, InstrumentMethodResearchBindingService::METADATA.'.policy_hash');
                    $targetPolicy = is_string($targetPolicyHash) ? $this->learning->policy($targetPolicyHash) : [];
                    $this->need(($targetPolicy['status'] ?? null) !== 'blocked'
                        && $this->verifyRebasedProvenance((array) ($targetPolicy['learning_provenance'] ?? []), $targetPolicy),
                        'METHOD_CANDIDATE_ORIGINAL_TARGET_DRIFT');
                    return $this->result($existing, (array) data_get($existing->metadata, 'instrument_method_candidate'), $targetPolicyHash, true);
                }
                $target = ModelVersion::create(['name' => 'method_research_'.substr($key, 0, 64),
                    'strategy' => $baseline['strategy'], 'version' => 'method_candidate_v1', 'status' => 'draft',
                    'parameters' => $baseline['parameters'], 'description' => 'Fresh exact research baseline with an original diagnostic method policy; canonical admission remains required.',
                    'metadata' => [...$baseline['metadata'], 'instrument_method_candidate' => $proof]]);
                $proof['target_model_id'] = (int) $target->id;
                $proof['candidate_key'] = $this->hash(array_diff_key($proof, ['candidate_key' => true]));
                $target->update(['metadata' => [...$baseline['metadata'], 'instrument_method_candidate' => $proof]]);
                $registered = $this->learning->registerRebasedPolicy($proposalHash, (int) $target->id);
                $this->need(($registered['status'] ?? null) === 'registered_research_only', 'METHOD_CANDIDATE_TARGET_POLICY_REGISTRATION_REQUIRED');
                $this->bindings->attachToUnusedModel((int) $target->id, $registered['policy_hash']);
                return $this->result($target->fresh(), $proof, $registered['policy_hash'], false);
            });
        } catch (Throwable $error) {
            return ['status' => 'blocked', 'reason' => preg_match('/^METHOD_CANDIDATE_[A-Z0-9_]+$/D', $error->getMessage())
                ? $error->getMessage() : 'METHOD_CANDIDATE_ORIGINAL_VERIFICATION_FAILED', ...$this->ceiling()];
        }
    }

    /** Plain schema strategies only; richer programme owners must construct their own original successor. */
    public function baseline(ModelVersion $model): array
    {
        if (array_key_exists(InstrumentMethodMetaBindingService::METADATA, (array) $model->metadata)
            || array_key_exists(InstrumentMethodMetaBindingService::PROOF_METADATA, (array) $model->metadata)) {
            throw new LogicException('METHOD_CANDIDATE_META_ASSET_TRANSFER_OWNER_REQUIRED');
        }
        foreach (['specialist_council', 'specialist_council_evaluation', 'specialist_council_membership',
            'native_specialist_council_seed', 'authorized_specialist_council_panel_seed', 'prospective_scoped_composition_recipe',
            'composition_passport', 'composition_runtime_contract', 'confirmation_entry', 'runtime_ensemble',
            'smart_composition.composition_passport', 'specialist_context_contract', 'contextual_specialist_cell',
            'contextual_specialist_contract', 'session_specialist_contract', 'regime_specialist_contract'] as $field) {
            $this->need(! filled(data_get($model->metadata, $field)), 'METHOD_CANDIDATE_SOURCE_RUNTIME_REQUIRES_EXISTING_CONSTRUCTOR');
        }
        $metadata = Arr::only((array) $model->metadata, self::BASELINE_METADATA);
        $authorityView = $metadata;
        if (array_key_exists('execution_contract', $authorityView)) {
            // This envelope's promotion_evidence means canonical cost-policy
            // usability, not that a model earned paper/economic authority.
            $cost = $authorityView['execution_contract'];
            $this->need(is_array($cost) && is_string($cost['symbol'] ?? null) && is_string($cost['timeframe'] ?? null),
                'METHOD_CANDIDATE_EXACT_EXECUTION_ENVELOPE_REQUIRED');
            $canonical = app(ExecutionContractService::class)->for($cost['symbol'], $cost['timeframe']);
            $this->need($this->evidence->equivalentJsonValue($cost, $canonical), 'METHOD_CANDIDATE_EXACT_EXECUTION_ENVELOPE_REQUIRED');
            unset($authorityView['execution_contract']);
        }
        $this->need(! $this->authorityMetadata($authorityView), 'METHOD_CANDIDATE_RUNTIME_AUTHORITY_METADATA_REFUSED');
        $family = $this->schemas->family((string) ($metadata['base_strategy'] ?? $model->strategy));
        try { $this->schemas->validate($family, (array) $model->parameters); }
        catch (\InvalidArgumentException) { throw new LogicException('METHOD_CANDIDATE_EXACT_SCHEMA_BASELINE_REQUIRED'); }
        return ['strategy' => $model->strategy, 'parameters' => (array) $model->parameters, 'metadata' => $metadata];
    }

    /** Pure policy registration/inspection guard; an original experience is reverified through its source proposal. */
    public function verifyRebasedProvenance(array $provenance, array $body): bool
    {
        try {
            $expectedFields = ['protocol', 'source_policy_hash', 'source_model_id', 'target_model_id', 'candidate_key',
                'baseline_hash', 'lineage_depth', 'research_only', 'out_of_sample', 'confirmation_proven'];
            if (($provenance['protocol'] ?? null) !== self::REBASE_PROTOCOL || array_diff(array_keys($provenance), $expectedFields) !== []
                || count($provenance) !== count($expectedFields) || ($provenance['research_only'] ?? null) !== true
                || ($provenance['out_of_sample'] ?? null) !== false || ($provenance['confirmation_proven'] ?? null) !== false
                || ! is_int($provenance['source_model_id'] ?? null) || ! is_int($provenance['target_model_id'] ?? null)
                || ! is_int($provenance['lineage_depth'] ?? null) || $provenance['lineage_depth'] < 1 || $provenance['lineage_depth'] > self::MAX_LINEAGE
                || $provenance['target_model_id'] <= $provenance['source_model_id']) return false;
            $source = ModelVersion::find($provenance['source_model_id']);
            $target = ModelVersion::find($provenance['target_model_id']);
            if (! $source || ! $target || $this->hash($this->baseline($source)) !== $provenance['baseline_hash']
                || $this->hash($this->baseline($target)) !== $provenance['baseline_hash']) return false;
            $targetMetadata = (array) $target->metadata;
            $allowedMetadata = [...array_keys($this->baseline($target)['metadata']), 'instrument_method_candidate', InstrumentMethodResearchBindingService::METADATA];
            if (array_diff(array_keys($targetMetadata), $allowedMetadata) !== []) return false;
            $proposal = $this->learning->policy((string) ($provenance['source_policy_hash'] ?? ''));
            if (($proposal['status'] ?? null) === 'blocked' || ($proposal['learning_provenance']['protocol'] ?? null) !== 'frozen_method_learning_exposure_v1'
                || ($proposal['agent_key'] ?? null) !== 'model:'.$source->id || ($body['agent_key'] ?? null) !== 'model:'.$target->id
                || ($body['revision'] ?? null) !== 1 || ($body['library_hash'] ?? null) !== $proposal['library_hash']
                || $this->hash((array) ($body['library_contract'] ?? [])) !== $this->hash($proposal['library_contract'])
                || $this->hash((array) ($body['rules'] ?? [])) !== $this->hash($proposal['rules'])
                || ($body['default_action'] ?? null) !== $proposal['default_action']) return false;
            $sourceBinding = data_get($source->metadata, InstrumentMethodResearchBindingService::METADATA);
            if (! is_array($sourceBinding) || ($sourceBinding['agent_key'] ?? null) !== $proposal['agent_key']
                || ($sourceBinding['policy_hash'] ?? null) !== $proposal['learning_provenance']['parent_policy_hash']) return false;
            $targetBinding = data_get($target->metadata, InstrumentMethodResearchBindingService::METADATA);
            if ($targetBinding !== null && (! is_array($targetBinding)
                || ($targetBinding['protocol'] ?? null) !== InstrumentMethodResearchBindingService::PROTOCOL
                || ($targetBinding['agent_key'] ?? null) !== $body['agent_key']
                || ($targetBinding['policy_hash'] ?? null) !== $this->hash(array_diff_key($body, ['policy_hash' => true, 'policy_json' => true])))) return false;
            $proof = (array) data_get($target->metadata, 'instrument_method_candidate', []);
            $key = $proof['candidate_key'] ?? null;
            if ($key !== $provenance['candidate_key'] || ($proof['protocol'] ?? null) !== self::PROTOCOL
                || ($proof['source_model_id'] ?? null) !== $provenance['source_model_id']
                || ($proof['target_model_id'] ?? null) !== (int) $target->id
                || ($proof['proposal_policy_hash'] ?? null) !== $provenance['source_policy_hash']
                || ($proof['source_policy_hash'] ?? null) !== $proposal['learning_provenance']['parent_policy_hash']
                || ($proof['baseline_hash'] ?? null) !== $provenance['baseline_hash'] || ($proof['library_hash'] ?? null) !== $proposal['library_hash']
                || ($proof['lineage_depth'] ?? null) !== $provenance['lineage_depth']
                || $provenance['lineage_depth'] !== (int) data_get($source->metadata, 'instrument_method_candidate.lineage_depth', 0) + 1
                || $this->hash(array_diff_key($proof, ['candidate_key' => true])) !== $key) return false;
            $semantics = $this->hash(['source_policy_hash' => $proof['source_policy_hash'], 'rules' => $proposal['rules'],
                'default_action' => $proposal['default_action'], 'library_hash' => $proposal['library_hash'],
                'source_experiences' => $proposal['learning_provenance']['sources']]);
            if (($proof['learning_semantics_hash'] ?? null) !== $semantics) return false;
            if (ModelVersion::where('metadata->instrument_method_candidate->source_policy_hash', $proof['source_policy_hash'])
                ->where('metadata->instrument_method_candidate->learning_semantics_hash', $semantics)
                ->where('metadata->instrument_method_candidate->baseline_hash', $proof['baseline_hash'])->count() !== 1) return false;
            foreach ($this->ceiling() as $field => $value) if (($proof[$field] ?? null) !== $value) return false;
            // Runtime parameters, risk and management remain exact; source authority and deployment are never inherited.
            $originalBasis = $this->evidence->modelRuntimeBasis($source);
            $newBasis = $this->evidence->modelRuntimeBasis($target);
            // The intentionally new owned policy is verified separately above;
            // every other physical treatment component must remain exact.
            unset($originalBasis['components'][InstrumentMethodResearchBindingService::METADATA],
                $newBasis['components'][InstrumentMethodResearchBindingService::METADATA]);
            if ($this->hash($originalBasis) !== $this->hash($newBasis)) return false;
            return true;
        } catch (Throwable) { return false; }
    }

    private function result(ModelVersion $model, array $proof, string $policyHash, bool $existing): array
    {
        $attempted = LabEvaluationRun::where('model_version_id', $model->id)->exists();
        return ['status' => $existing ? 'original_research_candidate_exists' : 'fresh_research_candidate',
            'candidate_key' => $proof['candidate_key'], 'model_id' => (int) $model->id, 'source_model_id' => $proof['source_model_id'],
            'source_policy_hash' => $proof['source_policy_hash'], 'proposal_policy_hash' => $proof['proposal_policy_hash'],
            'policy_hash' => $policyHash, 'library_hash' => $proof['library_hash'], 'baseline_hash' => $proof['baseline_hash'],
            'already_attempted' => $attempted, 'canonical_admission_required' => true, 'queued' => false, ...$this->ceiling()];
    }

    private function authorityMetadata(array $items): bool
    {
        foreach ($items as $key => $value) {
            $normalized = strtolower((string) $key);
            if (preg_match('/certificate|qualified|confirmed|skill_credit|mentor_authority|paper_authority|live_authority|deployment|promotion|economic_authority/', $normalized)) return true;
            if (is_array($value) && $this->authorityMetadata($value)) return true;
        }
        return false;
    }
    private function ceiling(): array { return ['research_only' => true, 'out_of_sample' => false, 'confirmation_proven' => false,
        'mastery_proven' => false, 'skill_credit' => false, 'mentor_authority' => false, 'paper_authority' => false,
        'economic_authority' => false, 'promotion_evidence' => false, 'live_authority' => false]; }
    private function need(bool $condition, string $reason): void { if (! $condition) throw new LogicException($reason); }
    private function hash(array $body): string { return $this->hashes->parameterHash($body); }
}
