<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\ModelVersion;
use App\Models\ResearchExperimentWorkItem;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/** One prospective own-policy comparison; only the existing study owner dispatches it. */
class AgentMethodStudyPreparationService
{
    public const PROTOCOL = 'own_policy_study_rebase_v1';
    public const METADATA = 'instrument_method_policy_study_rebase';

    public function __construct(private AgentMethodLearningService $learning,
        private InstrumentMethodLibraryService $library, private InstrumentMethodResearchBindingService $bindings,
        private InstrumentMethodStudyService $studies, private ResearchPaperEpochContractService $hashes) {}

    /** No manual generation, replay, parameter intervention, library revision, or authority is produced here. */
    public function prepare(array $input): array
    {
        try {
            $this->need(array_diff(array_keys($input), ['source_model_id', 'libraries', 'study', 'source']) === []
                && count($input) === 4 && is_int($input['source_model_id']) && $input['source_model_id'] > 0
                && is_array($input['libraries']) && is_array($input['study']) && is_array($input['source']),
                'METHOD_POLICY_STUDY_EXACT_PREPARATION_REQUIRED');
            return DB::transaction(function () use ($input): array {
                $source = ModelVersion::whereKey($input['source_model_id'])->lockForUpdate()->first();
                $this->need($source !== null && $this->unused($source), 'METHOD_POLICY_STUDY_UNUSED_OWN_CHILD_REQUIRED');
                $this->need(! array_key_exists(InstrumentMethodMetaBindingService::METADATA, (array) $source->metadata)
                    && ! array_key_exists(InstrumentMethodMetaBindingService::PROOF_METADATA, (array) $source->metadata),
                    'METHOD_POLICY_STUDY_META_ASSET_TRANSFER_OWNER_REQUIRED');
                [$own, $baseline, $root] = $this->sourcePolicies($source);
                $basis = $this->baseline($source, $own);
                $definitions = $this->definitions($input['libraries'], $baseline, $own);
                $inputHash = $this->hash($input);
                $studyKey = 'own-policy-followup:'.$root;
                $existing = DB::table('instrument_method_studies')->where('study_key', $studyKey)->first();
                if ($existing) {
                    $design = $this->studies->originalDesign($existing->study_hash);
                    foreach ($design['arms'] as $arm) {
                        $model = ModelVersion::find($arm['model_id']);
                        $policy = $this->learning->policy($arm['policy_hash']);
                        $this->need($model && ($policy['status'] ?? null) !== 'blocked'
                            && data_get($model->metadata, self::METADATA.'.input_hash') === $inputHash
                            && data_get($model->metadata, self::METADATA.'.source_question_root') === $root,
                            'METHOD_POLICY_STUDY_ORIGINAL_PREPARATION_IS_IMMUTABLE');
                    }
                    $work = ResearchExperimentWorkItem::where('work_type', InstrumentMethodStudyExecutionService::WORK_TYPE)
                        ->where('payload->method_registration->study_hash', $existing->study_hash)->first();
                    $this->need($work !== null, 'METHOD_POLICY_STUDY_ORIGINAL_WORK_REQUIRED');
                    return ['status' => 'original_policy_study_exists', 'study_hash' => $existing->study_hash,
                        'work_id' => (int) $work->id, 'arms' => $design['arms'], ...$this->ceiling()];
                }
                $this->need(! ModelVersion::where('metadata->'.self::METADATA.'->source_question_root', $root)->exists(),
                    'METHOD_POLICY_STUDY_ONE_ORIGINAL_PREPARATION_REQUIRED');
                $this->need(array_diff(array_keys($input['study']), ['data_hash', 'execution_hash', 'scope', 'evaluated_rows',
                    'initial_balance', 'risk_per_trade', 'execution_costs', 'hard_limits', 'resource_caps', 'minimum_trades']) === [],
                    'METHOD_POLICY_STUDY_CALLER_CANNOT_REPLACE_ARMS_OR_KEY');
                // An unchanged selector is not a new experiment, independent sample, or reason to spend compute.
                if ($this->hash($this->studies->selectorClass($definitions['L1A1']))
                    === $this->hash($this->studies->selectorClass($definitions['L1A2']))) {
                    return ['status' => 'no_effect', 'reason' => 'METHOD_POLICY_STUDY_SELECTOR_CLASS_UNCHANGED',
                        'source_model_id' => (int) $source->id, 'source_question_root' => $root,
                        'carriers_created' => 0, 'original_work_created' => false, ...$this->ceiling()];
                }
                $arms = [];
                foreach (InstrumentMethodStudyExecutionService::ARMS as $armKey) {
                    $definition = $definitions[$armKey];
                    $model = ModelVersion::create(['name' => 'own-policy-'.$root.'-'.$armKey,
                        'strategy' => $basis['strategy'], 'version' => 'own_policy_study_v1', 'status' => 'draft',
                        'parameters' => $basis['parameters'], 'metadata' => $basis['metadata'],
                        'description' => 'Prospective exact-baseline own-policy diagnostic carrier; no independent or trading authority.']);
                    $proof = ['protocol' => self::PROTOCOL, 'source_model_id' => (int) $source->id,
                        'target_model_id' => (int) $model->id, 'source_policy_hash' => $own['policy_hash'],
                        'baseline_policy_hash' => $baseline['policy_hash'], 'source_question_root' => $root,
                        'input_hash' => $inputHash, 'arm' => $armKey, 'libraries' => $input['libraries'],
                        'baseline_hash' => $this->hash($basis), 'library_hash' => $definition['library_hash'],
                        'library_contract' => $definition['library_contract'], 'rules' => $definition['rules'],
                        'default_action' => $definition['default_action'], ...$this->ceiling()];
                    $proof['handoff_hash'] = $this->hash($proof); $proof['server_seal'] = $this->seal($proof);
                    $model->update(['metadata' => [...$basis['metadata'], self::METADATA => $proof]]);
                    $registered = $this->learning->registerStudyPolicy((int) $model->id);
                    $this->need(($registered['status'] ?? null) === 'registered_research_only',
                        $registered['reason'] ?? 'METHOD_POLICY_STUDY_OWN_CARRIER_REGISTRATION_REQUIRED');
                    $this->bindings->attachToUnusedModel((int) $model->id, $registered['policy_hash']);
                    $arms[$armKey] = ['model_id' => (int) $model->id, 'policy_hash' => $registered['policy_hash']];
                }
                $registered = $this->studies->preregister(['study_key' => $studyKey, 'arms' => $arms, ...$input['study']]);
                $this->need(($registered['status'] ?? null) === 'preregistered_diagnostic',
                    $registered['reason'] ?? 'METHOD_POLICY_STUDY_PREREGISTRATION_REQUIRED');
                $work = app(InstrumentMethodStudyExecutionService::class)->registerWork($registered['study_hash'], $input['source']);
                $this->need(($work['status'] ?? null) === 'original_work_registered',
                    $work['reason_code'] ?? $work['reason'] ?? 'METHOD_POLICY_STUDY_CANONICAL_WORK_REQUIRED');
                return ['status' => 'own_policy_study_registered', 'study_hash' => $registered['study_hash'],
                    'work_id' => $work['work_id'], 'arms' => $arms, 'source_model_id' => (int) $source->id,
                    'source_policy_hash' => $own['policy_hash'], 'baseline_policy_hash' => $baseline['policy_hash'],
                    'source_question_root' => $root, 'prior_source_outcomes_seen' => true,
                    'carriers_created' => 4, ...$this->ceiling()];
            });
        } catch (Throwable $error) { return $this->blocked($error); }
    }

    /** Server registration only; a caller may not submit a copied learned provenance. */
    public function registrationInput(int $targetId): array
    {
        $target = ModelVersion::findOrFail($targetId);
        $this->need($this->unused($target), 'METHOD_POLICY_STUDY_UNUSED_CARRIER_REQUIRED');
        $proof = (array) data_get($target->metadata, self::METADATA, []);
        $input = ['agent_key' => 'model:'.$targetId, 'revision' => 1,
            'library_hash' => $proof['library_hash'] ?? null, 'library_contract' => $proof['library_contract'] ?? [],
            'rules' => $proof['rules'] ?? [], 'default_action' => $proof['default_action'] ?? null,
            'learning_provenance' => $proof];
        $this->need($this->verifyProvenance($proof, $input), 'METHOD_POLICY_STUDY_ORIGINAL_OWN_HANDOFF_REQUIRED');
        return $input;
    }

    public function verifyProvenance(array $proof, array $body): bool
    {
        try {
            if (($proof['protocol'] ?? null) !== self::PROTOCOL || ! is_int($proof['source_model_id'] ?? null)
                || ! is_int($proof['target_model_id'] ?? null) || $proof['target_model_id'] <= $proof['source_model_id']
                || ! in_array($proof['arm'] ?? null, InstrumentMethodStudyExecutionService::ARMS, true)
                || ($proof['handoff_hash'] ?? null) !== $this->hash(array_diff_key($proof, ['handoff_hash' => true, 'server_seal' => true]))
                || ! is_string($proof['server_seal'] ?? null)
                || ! hash_equals($this->seal(array_diff_key($proof, ['server_seal' => true])), $proof['server_seal'])) return false;
            foreach ($this->ceiling() as $field => $value) if (($proof[$field] ?? null) !== $value) return false;
            $target = ModelVersion::find($proof['target_model_id']); $source = ModelVersion::find($proof['source_model_id']);
            if (! $target || ! $source || $this->hash((array) data_get($target->metadata, self::METADATA, [])) !== $this->hash($proof)) return false;
            [$own, $baseline, $root] = $this->sourcePolicies($source);
            $definition = $this->definitions($proof['libraries'], $baseline, $own)[$proof['arm']];
            $metadata = (array) $target->metadata;
            unset($metadata[self::METADATA], $metadata[InstrumentMethodResearchBindingService::METADATA], $metadata['lifecycle_sync']);
            if ($proof['source_policy_hash'] !== $own['policy_hash'] || $proof['baseline_policy_hash'] !== $baseline['policy_hash']
                || $proof['source_question_root'] !== $root || $proof['baseline_hash'] !== $this->hash($this->baseline($source, $own))
                || $proof['baseline_hash'] !== $this->hash(['strategy' => $target->strategy, 'parameters' => (array) $target->parameters, 'metadata' => $metadata])
                || ($body['agent_key'] ?? null) !== 'model:'.$target->id || ($body['revision'] ?? null) !== 1) return false;
            foreach (['library_hash', 'library_contract', 'rules', 'default_action'] as $field) {
                if ($this->hash(['value' => $proof[$field]]) !== $this->hash(['value' => $definition[$field]])
                    || $this->hash(['value' => $body[$field] ?? null]) !== $this->hash(['value' => $definition[$field]])) return false;
            }
            $binding = data_get($target->metadata, InstrumentMethodResearchBindingService::METADATA);
            if ($binding !== null && (($binding['agent_key'] ?? null) !== $body['agent_key']
                || ($binding['policy_hash'] ?? null) !== $this->hash(array_diff_key($body, ['policy_hash' => true, 'policy_json' => true])))) return false;
            return true;
        } catch (Throwable) { return false; }
    }

    /** Exclude only independently verified policy derivation, never an unverified or trading metadata field. */
    public function baseline(ModelVersion $model, array $policy): array
    {
        $metadata = (array) $model->metadata;
        if (isset($metadata[AgentMethodGenerationService::METADATA])) {
            $this->need(data_get($policy, 'learning_provenance.protocol') === AgentMethodGenerationService::PROTOCOL
                && app(AgentMethodGenerationService::class)->verifyProvenance($metadata[AgentMethodGenerationService::METADATA], $policy),
                'METHOD_POLICY_STUDY_GENERATION_PROVENANCE_INVALID');
            unset($metadata[AgentMethodGenerationService::METADATA]);
        }
        if (isset($metadata['instrument_method_candidate'])) {
            $this->need(data_get($policy, 'learning_provenance.protocol') === AgentMethodCandidateService::REBASE_PROTOCOL
                && app(AgentMethodCandidateService::class)->verifyRebasedProvenance($policy['learning_provenance'], $policy),
                'METHOD_POLICY_STUDY_CANDIDATE_PROVENANCE_INVALID');
            unset($metadata['instrument_method_candidate']);
        }
        if (isset($metadata[self::METADATA])) {
            $this->need(data_get($policy, 'learning_provenance.protocol') === self::PROTOCOL
                && $this->verifyProvenance($metadata[self::METADATA], $policy), 'METHOD_POLICY_STUDY_CARRIER_PROVENANCE_INVALID');
            unset($metadata[self::METADATA]);
        }
        unset($metadata[InstrumentMethodResearchBindingService::METADATA], $metadata['lifecycle_sync']);
        app(AgentMethodCandidateService::class)->baseline($model); // Existing rich-owner/schema/authority guards still apply.
        return ['strategy' => $model->strategy, 'parameters' => (array) $model->parameters, 'metadata' => $metadata];
    }

    private function sourcePolicies(ModelVersion $source): array
    {
        $ownHash = data_get($source->metadata, InstrumentMethodResearchBindingService::METADATA.'.policy_hash');
        $own = is_string($ownHash) ? $this->learning->policy($ownHash) : [];
        $proof = (array) data_get($source->metadata, AgentMethodGenerationService::METADATA, []);
        $this->need(($own['status'] ?? null) !== 'blocked' && ($own['agent_key'] ?? null) === 'model:'.$source->id
            && data_get($own, 'learning_provenance.protocol') === AgentMethodGenerationService::PROTOCOL
            && ($proof['mode'] ?? null) === 'original_parent_observation'
            && app(AgentMethodGenerationService::class)->verifyProvenance($proof, $own),
            'METHOD_POLICY_STUDY_VERIFIED_ORIGINAL_OWN_CHILD_REQUIRED');
        $baseline = $this->learning->policy($proof['source_policy_hash']);
        $this->need(($baseline['status'] ?? null) !== 'blocked', 'METHOD_POLICY_STUDY_ORIGINAL_PARENT_SELECTOR_REQUIRED');
        return [$own, $baseline, $proof['source_proposal_hash']];
    }

    private function definitions(array $libraries, array $baseline, array $own): array
    {
        $keys = array_keys($libraries); sort($keys);
        $this->need($keys === ['L1', 'L2'], 'METHOD_POLICY_STUDY_EXACT_LIBRARY_AXIS_REQUIRED');
        $knownKeys = [];
        foreach ($baseline['library_contract']['recipe_hashes'] as $hash) {
            $recipe = $this->library->inspectRecipe($hash);
            $this->need(($recipe['status'] ?? null) === 'registered_research_only', 'METHOD_POLICY_STUDY_ORIGINAL_RECIPE_CLASS_REQUIRED');
            $knownKeys[] = $recipe['contract']['recipe_key'];
        }
        $this->need(count(array_unique($knownKeys)) === count($knownKeys), 'METHOD_POLICY_STUDY_UNIQUE_ORIGINAL_RECIPE_CLASSES_REQUIRED');
        sort($knownKeys);
        $result = [];
        foreach ($libraries as $axis => $hashes) {
            $this->need(is_array($hashes) && array_is_list($hashes) && count($hashes) >= 1 && count($hashes) <= 3,
                'METHOD_POLICY_STUDY_BOUNDED_TYPED_LIBRARY_REQUIRED');
            $library = $this->library->librarySnapshot($hashes);
            $this->need(($library['valid'] ?? false) === true, 'METHOD_POLICY_STUDY_VERIFIED_LIBRARY_REQUIRED');
            $byKey = [];
            foreach ($hashes as $hash) {
                $recipe = $this->library->inspectRecipe($hash);
                $this->need(($recipe['status'] ?? null) === 'registered_research_only', 'METHOD_POLICY_STUDY_RECIPE_REQUIRED');
                foreach ($recipe['contract']['method_contracts'] as $method) $this->need($method['implementation']['kind'] === 'typed_ast',
                    'METHOD_POLICY_STUDY_PARAMETER_ADAPTER_NOT_A_POLICY_INTERVENTION');
                $key = $recipe['contract']['recipe_key'];
                $this->need(! isset($byKey[$key]), 'METHOD_POLICY_STUDY_UNIQUE_RECIPE_CLASSES_REQUIRED');
                $byKey[$key] = $hash;
            }
            $targetKeys = array_keys($byKey); sort($targetKeys);
            $this->need($targetKeys === $knownKeys, 'METHOD_POLICY_STUDY_WHOLE_LIBRARY_CLASS_SET_CHANGED');
            foreach (['A1' => $baseline, 'A2' => $own] as $agentAxis => $policy) {
                $rules = [];
                foreach ($policy['rules'] as $rule) {
                    $preferred = [];
                    foreach ($rule['preferred_recipes'] as $oldHash) {
                        $old = $this->library->inspectRecipe($oldHash);
                        $key = $old['contract']['recipe_key'] ?? null;
                        $this->need(($old['status'] ?? null) === 'registered_research_only' && isset($byKey[$key]),
                            'METHOD_POLICY_STUDY_EXACT_RECIPE_CLASS_MAPPING_REQUIRED');
                        $preferred[] = $byKey[$key];
                    }
                    $rules[] = ['context_predicate' => $rule['context_predicate'], 'preferred_recipes' => $preferred];
                }
                $result[$axis.$agentAxis] = ['library_hash' => $library['library_hash'], 'library_contract' => $library['library_contract'],
                    'rules' => $rules, 'default_action' => $policy['default_action']];
            }
        }
        $this->need($result['L1A1']['library_hash'] !== $result['L2A1']['library_hash'], 'METHOD_POLICY_STUDY_LIBRARY_AXIS_UNCHANGED');
        foreach (['A1', 'A2'] as $axis) $this->need($this->hash($this->studies->selectorClass($result['L1'.$axis]))
            === $this->hash($this->studies->selectorClass($result['L2'.$axis])), 'METHOD_POLICY_STUDY_SELECTOR_MAPPING_CHANGED');
        return $result;
    }

    private function unused(ModelVersion $model): bool
    {
        return ! LabAgent::where('model_version_id', $model->id)->exists() && ! LabEvaluationRun::where('model_version_id', $model->id)->exists()
            && $model->promoted_at === null && $model->invalidated_at === null && in_array($model->status, ['draft', 'testing'], true);
    }
    private function seal(array $body): string
    {
        $key = config('app.key'); $this->need(is_string($key) && $key !== '', 'METHOD_POLICY_STUDY_SERVER_KEY_REQUIRED');
        return hash_hmac('sha256', self::PROTOCOL.':'.$this->hash($body), $key);
    }
    private function ceiling(): array { return ['research_only' => true, 'out_of_sample' => false, 'confirmation_proven' => false,
        'mastery_proven' => false, 'skill_credit' => false, 'economic_authority' => false, 'paper_authority' => false,
        'promotion_evidence' => false, 'live_authority' => false]; }
    private function blocked(Throwable $error): array { return ['status' => 'blocked', 'reason' => preg_match('/^[A-Z][A-Z0-9_]+$/D', $error->getMessage())
        ? $error->getMessage() : 'METHOD_POLICY_STUDY_PREPARATION_REFUSED', ...$this->ceiling()]; }
    private function hash(array $body): string { return $this->hashes->parameterHash($body); }
    private function need(bool $condition, string $reason): void { if (! $condition) throw new LogicException($reason); }
}
