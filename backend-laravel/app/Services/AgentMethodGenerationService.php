<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Throwable;

/** Prospective research transfer only. It never edits a library, source model, or scheduler. */
class AgentMethodGenerationService
{
    public const PROTOCOL = 'generation_owned_method_policy_v1';
    public const METADATA = 'instrument_method_generation';
    private const PHYSICAL = ['base_strategy', 'architecture', 'strategy_architecture', 'tactic', 'tactic_contract',
        'risk_governor', 'trade_management', 'execution_contract', 'agent_constitution'];
    private const PROTECTED = ['causal_learning_cohort', 'cooperative_experiment_block', 'control_pair_contract',
        'activation_factorial', 'phase_scope_probe', 'prospective_repair', 'native_specialist_council_seed',
        'authorized_specialist_council_panel_seed', 'specialist_council', 'specialist_council_evaluation',
        'specialist_council_membership', 'prospective_scoped_composition_recipe', 'composition_passport',
        'composition_runtime_contract', 'confirmation_entry', 'runtime_ensemble', 'smart_composition.composition_passport'];

    public function __construct(private AgentMethodLearningService $learning,
        private InstrumentMethodLibraryService $library, private InstrumentMethodResearchBindingService $binding,
        private StrategyParameterSchemaService $schemas, private ResearchPaperEpochContractService $hashes) {}

    /** Called only after immutable original observations have been projected by the existing owner. */
    public function proposeFromOriginalRun(LabEvaluationRun $run, ?array $learned = null): array
    {
        $learned ??= $this->learning->learnFromRun($run);
        if (($learned['status'] ?? null) !== 'learned_diagnostic') return $this->dependency('METHOD_GENERATION_ORIGINAL_EXPERIENCE_REQUIRED');
        $proposals = [];
        try { $owned = $this->originalRunUsesOwnedOutcomes($run, $learned); }
        catch (Throwable $error) { return $this->dependency($this->safeReason($error)); }
        if ($owned) {
            $outcomes = $this->learning->learnOwnedOutcomesFromRun($run);
            if (($outcomes['status'] ?? null) !== 'learned_owned_outcomes') return $this->dependency($outcomes['reason'] ?? 'OWNED_METHOD_ORIGINAL_OUTCOMES_REQUIRED');
        }
        foreach ((array) ($learned['policy_hashes'] ?? []) as $hash) {
            $proposals[] = $this->proposalOnce($hash, $owned);
        }
        return ['status' => 'original_policy_proposals', 'run_id' => $run->run_id, 'proposals' => $proposals, ...$this->ceiling()];
    }

    /** Study carriers omit generation metadata; only their verified original source lineage can restore this mode. */
    private function originalRunUsesOwnedOutcomes(LabEvaluationRun $run, array $learned): bool
    {
        $model = $run->model_version_id === null ? null : ModelVersion::find($run->model_version_id);
        if (! $model) return false;
        if (data_get($model->metadata, self::METADATA.'.constructor.owned_outcome_learning') === true) return true;
        $study = data_get($model->metadata, AgentMethodStudyPreparationService::METADATA);
        if ($study === null) return false;
        $hash = data_get($model->metadata, InstrumentMethodResearchBindingService::METADATA.'.policy_hash');
        $this->need(is_array($study) && is_string($hash)
            && in_array($hash, (array) ($learned['policy_hashes'] ?? []), true), 'OWNED_METHOD_STUDY_ORIGINAL_POLICY_REQUIRED');
        $policy = $this->learning->policy($hash);
        $this->need(($policy['status'] ?? null) !== 'blocked'
            && app(AgentMethodStudyPreparationService::class)->verifyProvenance($study, $policy),
            'OWNED_METHOD_STUDY_ORIGINAL_HANDOFF_REQUIRED');
        $source = ModelVersion::find($study['source_model_id']);
        $sourcePolicy = $this->learning->policy($study['source_policy_hash']);
        $proof = (array) data_get($source?->metadata, self::METADATA, []);
        $this->need($source !== null && ($sourcePolicy['status'] ?? null) !== 'blocked'
            && $this->verifyProvenance($proof, $sourcePolicy), 'OWNED_METHOD_STUDY_ORIGINAL_SOURCE_REQUIRED');
        return ($proof['mode'] ?? null) === 'original_parent_owned_outcome';
    }

    /** At most one frozen proposal per source policy; replay redelivery cannot create another search attempt. */
    public function proposalOnce(string $policyHash, bool $ownedOutcomes = false): array
    {
        if ($ownedOutcomes) return $this->ownedOutcomeProposalOnce($policyHash);
        try {
            return DB::transaction(function () use ($policyHash): array {
                $row = DB::table('agent_method_policy_revisions')->where('policy_hash', $policyHash)->lockForUpdate()->first();
                $this->need($row !== null, 'METHOD_GENERATION_SOURCE_POLICY_REQUIRED');
                $policy = $this->learning->policy($policyHash);
                $this->need(($policy['status'] ?? null) !== 'blocked', 'METHOD_GENERATION_SOURCE_POLICY_INVALID');
                foreach (DB::table('agent_method_policy_revisions')->where('agent_key', $policy['agent_key'])->orderBy('revision')->get() as $candidate) {
                    $body = json_decode($candidate->contract, true, 64, JSON_THROW_ON_ERROR);
                    if (data_get($body, 'learning_provenance.parent_policy_hash') !== $policyHash) continue;
                    if (data_get($body, 'learning_provenance.protocol') === AgentMethodLearningService::OWNED_OUTCOME_PROVENANCE) continue;
                    $original = $this->learning->policy($candidate->policy_hash);
                    $this->need(($original['status'] ?? null) !== 'blocked', 'METHOD_GENERATION_ORIGINAL_PROPOSAL_DRIFT');
                    return [...$original, 'proposal_status' => 'frozen_diagnostic_proposal', 'already_proposed' => true];
                }
                $next = (int) DB::table('agent_method_policy_revisions')->where('agent_key', $policy['agent_key'])->max('revision') + 1;
                return [...$this->learning->proposePolicy($policyHash, $next, 3), 'already_proposed' => false];
            });
        } catch (Throwable $error) { return $this->dependency($this->safeReason($error)); }
    }

    /** No unilateral policy intervention in the protected causal/abstain seats or an exact block. */
    public function prepareConstructorChild(ModelVersion $child, ?ModelVersion $parent, LabGeneration $generation,
        int $slot, array $niche): array
    {
        if (data_get($generation->trigger_context, 'instrument_method_generation_learning.enabled') !== true) {
            return ['status' => 'not_requested', ...$this->ceiling()];
        }
        if ($this->protected($child, $niche) || in_array(data_get($niche, 'role'), ['uncertainty_abstain', 'adversarial_guard'], true)) {
            return $this->dependency('METHOD_GENERATION_PROTECTED_OR_COMPARATIVE_OWNER_UNCHANGED');
        }
        $constructor = ['generation_id' => (int) $generation->id, 'slot' => $slot,
            'curated_recipe_hashes' => data_get($generation->trigger_context, 'instrument_method_generation_learning.recipe_hashes',
                [])];
        if ($parent && data_get($parent->metadata, InstrumentMethodResearchBindingService::METADATA.'.policy_hash') !== null) {
            if (! $this->unused($child)) return $this->dependency('METHOD_GENERATION_UNUSED_CONSTRUCTOR_CHILD_REQUIRED');
            $selection = $this->preferOwnedOutcomeProposal($parent);
            if (($selection['status'] ?? null) === 'blocked') return $this->dependency($selection['reason']);
            $constructor['owned_outcome_selection'] = $selection['reason'];
            if (($selection['status'] ?? null) === 'selected_owned_outcomes') $constructor['owned_outcome_learning'] = true;
        }
        return $this->prepareFreshChild($child, $parent, $constructor);
    }

    /** Attach to an already constructed, still unused ordinary child without changing any physical treatment. */
    public function prepareFreshChild(ModelVersion $child, ?ModelVersion $parent, array $constructor = []): array
    {
        if (! Schema::hasTable('agent_method_policy_revisions')) return $this->dependency('METHOD_GENERATION_MIGRATION_REQUIRED');
        try {
            $this->need(! array_key_exists('teaching_pack', $constructor), 'METHOD_GENERATION_CODE_OWNED_TEACHING_PACK_REQUIRED');
            return DB::transaction(function () use ($child, $parent, $constructor): array {
                if ($parent !== null) {
                    $parent = ModelVersion::find($parent->id);
                    $this->need($parent !== null, 'METHOD_GENERATION_ORIGINAL_PARENT_REQUIRED');
                }
                $child = ModelVersion::whereKey($child->id)->lockForUpdate()->firstOrFail();
                $this->need($this->unused($child), 'METHOD_GENERATION_UNUSED_CONSTRUCTOR_CHILD_REQUIRED');
                $this->need($parent === null || (! array_key_exists(InstrumentMethodMetaBindingService::METADATA, (array) $parent->metadata)
                    && ! array_key_exists(InstrumentMethodMetaBindingService::PROOF_METADATA, (array) $parent->metadata)),
                    'METHOD_GENERATION_META_ASSET_TRANSFER_OWNER_REQUIRED');
                $this->need(! $this->protected($child), 'METHOD_GENERATION_EXISTING_TYPED_CONSTRUCTOR_REQUIRED');
                $baseline = $this->physical($child);
                $prior = data_get($child->metadata, self::METADATA);
                if ($prior !== null) {
                    $hash = data_get($child->metadata, InstrumentMethodResearchBindingService::METADATA.'.policy_hash');
                    $policy = is_string($hash) ? $this->learning->policy($hash) : [];
                    $this->need(($policy['status'] ?? null) !== 'blocked' && isset($policy['policy_hash']), 'METHOD_GENERATION_TARGET_POLICY_DRIFT');
                    return ['status' => 'original_child_policy_exists', 'model_id' => (int) $child->id, 'policy_hash' => $hash, ...$this->ceiling()];
                }
                $this->need(data_get($child->metadata, InstrumentMethodResearchBindingService::METADATA) === null, 'METHOD_GENERATION_CHILD_ALREADY_OWNED');
                $sourcePolicy = null; $proposal = null;
                if ($parent !== null && data_get($parent->metadata, InstrumentMethodResearchBindingService::METADATA.'.policy_hash') !== null) {
                    $this->need($parent->id < $child->id && ! $this->protected($parent), 'METHOD_GENERATION_SUPPORTED_PARENT_REQUIRED');
                    $this->need($this->physical($parent)['family'] === $baseline['family'], 'METHOD_GENERATION_FAMILY_TRANSFER_REFUSED');
                    $sourcePolicy = data_get($parent->metadata, InstrumentMethodResearchBindingService::METADATA.'.policy_hash');
                    $source = $this->learning->policy($sourcePolicy);
                    $this->need(($source['agent_key'] ?? null) === 'model:'.$parent->id, 'METHOD_GENERATION_EXACT_PARENT_OWNER_REQUIRED');
                    $owned = ($constructor['owned_outcome_learning'] ?? false) === true;
                    $proposal = $this->proposalOnce($sourcePolicy, $owned);
                    $this->need(($proposal['proposal_status'] ?? null) === ($owned ? 'frozen_owned_outcome_proposal' : 'frozen_diagnostic_proposal'),
                        'METHOD_GENERATION_PARENT_ORIGINAL_EXPERIENCES_REQUIRED');
                    $definition = $proposal;
                } else {
                    $definition = $this->curatedDefault($constructor['curated_recipe_hashes'] ?? null);
                }
                $depth = $parent && $proposal ? (int) data_get($parent->metadata, self::METADATA.'.lineage_depth', 0) + 1 : 0;
                $this->need($depth <= 16, 'METHOD_GENERATION_PROVENANCE_DEPTH_BUDGET_REQUIRED');
                $proof = ['protocol' => self::PROTOCOL, 'target_model_id' => (int) $child->id,
                    'source_model_id' => $proposal ? (int) $parent->id : null, 'source_policy_hash' => $sourcePolicy,
                    'source_proposal_hash' => $proposal['policy_hash'] ?? null,
                    'mode' => $proposal ? (($constructor['owned_outcome_learning'] ?? false) === true
                        ? 'original_parent_owned_outcome' : 'original_parent_observation') : 'curated_default',
                    'library_hash' => $definition['library_hash'], 'library_contract' => $definition['library_contract'],
                    'rules' => $definition['rules'], 'default_action' => $definition['default_action'],
                    'physical_hash' => $this->hash($baseline), 'constructor' => $constructor,
                    'lineage_depth' => $depth, ...$this->ceiling()];
                // Instruction is pinned separately from learned selection. It
                // cannot install a model asset or change a physical treatment.
                $catalog = app(CuratedInstrumentMethodCatalogService::class);
                $teaching = $catalog->teachingPack($definition['library_contract']['recipe_hashes']);
                if (($teaching['status'] ?? null) === 'operating_rules_taught') {
                    $this->need($catalog->verifyTeachingPack($teaching, $definition['library_contract']['recipe_hashes']),
                        'METHOD_GENERATION_TEACHING_PACK_INVALID');
                    $proof['teaching_pack'] = $teaching;
                } else {
                    // Existing custom recipes retain the original handoff;
                    // corrupt or incompletely installed mapped lessons refuse.
                    $this->need(($teaching['status'] ?? null) === 'unmapped'
                        && ($teaching['reason'] ?? null) === 'OPERATING_RECIPE_NOT_IN_REVIEWED_LESSONS',
                        'METHOD_GENERATION_TEACHING_PACK_REQUIRED');
                }
                $proof['handoff_hash'] = $this->hash($proof);
                $proof['server_seal'] = $this->seal($proof);
                $child->update(['metadata' => [...(array) $child->metadata, self::METADATA => $proof]]);
                $registered = $this->learning->registerGenerationPolicy((int) $child->id);
                $this->need(($registered['status'] ?? null) === 'registered_research_only', 'METHOD_GENERATION_CHILD_REGISTRATION_REQUIRED');
                $inspected = $this->learning->policy($registered['policy_hash']);
                $this->need(($inspected['status'] ?? null) !== 'blocked', $inspected['reason'] ?? 'METHOD_GENERATION_POLICY_REINSPECTION_REQUIRED');
                $attached = $this->binding->attachToUnusedModel((int) $child->id, $registered['policy_hash']);
                $this->need($this->hash($this->physical($child->fresh())) === $proof['physical_hash'], 'METHOD_GENERATION_PHYSICAL_TREATMENT_CHANGED');
                return ['status' => 'prospective_child_policy_attached', 'model_id' => (int) $child->id,
                    'policy_hash' => $registered['policy_hash'], 'source_proposal_hash' => $proof['source_proposal_hash'],
                    'binding' => $attached, ...$this->ceiling()];
            });
        } catch (Throwable $error) { return $this->dependency($this->safeReason($error)); }
    }

    /** Reinspection uses the immutable target declaration, not current defaults or latest recipe revisions. */
    public function registrationInput(int $targetModelId): array
    {
        $target = ModelVersion::findOrFail($targetModelId);
        $this->need($this->unused($target), 'METHOD_GENERATION_UNUSED_CONSTRUCTOR_CHILD_REQUIRED');
        $proof = (array) data_get($target->metadata, self::METADATA, []);
        $input = ['agent_key' => 'model:'.$targetModelId, 'revision' => 1,
            'library_hash' => $proof['library_hash'] ?? null, 'library_contract' => $proof['library_contract'] ?? [],
            'rules' => $proof['rules'] ?? [], 'default_action' => $proof['default_action'] ?? null,
            'learning_provenance' => $proof];
        $this->need($this->verifyProvenance($proof, $input), 'METHOD_GENERATION_IMMUTABLE_HANDOFF_REQUIRED');
        return $input;
    }

    public function verifyProvenance(array $proof, array $body): bool
    {
        try {
            if (($proof['protocol'] ?? null) !== self::PROTOCOL || ! is_int($proof['target_model_id'] ?? null)
                || ! is_int($proof['lineage_depth'] ?? null) || $proof['lineage_depth'] < 0 || $proof['lineage_depth'] > 16
                || ($proof['handoff_hash'] ?? null) !== $this->hash(array_diff_key($proof, ['handoff_hash' => true, 'server_seal' => true]))
                || ! is_string($proof['server_seal'] ?? null)
                || ! hash_equals($this->seal(array_diff_key($proof, ['server_seal' => true])), $proof['server_seal'])) return false;
            foreach ($this->ceiling() as $field => $value) if (($proof[$field] ?? null) !== $value) return false;
            $target = ModelVersion::find($proof['target_model_id']);
            if (! $target || $this->hash((array) data_get($target->metadata, self::METADATA, [])) !== $this->hash($proof) || $this->protected($target)
                || $proof['physical_hash'] !== $this->hash($this->physical($target))
                || ($body['agent_key'] ?? null) !== 'model:'.$target->id || ($body['revision'] ?? null) !== 1
                || ($body['library_hash'] ?? null) !== $proof['library_hash']
                || $this->hash((array) ($body['library_contract'] ?? [])) !== $this->hash($proof['library_contract'])
                || $this->hash((array) ($body['rules'] ?? [])) !== $this->hash($proof['rules'])
                || ($body['default_action'] ?? null) !== $proof['default_action']) return false;
            if (($this->library->verifyLibrary(['library_contract' => $proof['library_contract'], 'library_hash' => $proof['library_hash']])['valid'] ?? false) !== true) return false;
            // Old proofs have no instruction pack and remain byte-for-byte
            // valid. New packs must belong to this exact, pinned recipe pool.
            if (array_key_exists('teaching_pack', $proof)
                && (! is_array($proof['teaching_pack'])
                    || ! app(CuratedInstrumentMethodCatalogService::class)->verifyTeachingPack(
                        $proof['teaching_pack'], $proof['library_contract']['recipe_hashes']))) return false;
            if ($proof['mode'] === 'curated_default') {
                if ($proof['source_model_id'] !== null || $proof['source_policy_hash'] !== null || $proof['source_proposal_hash'] !== null || $proof['lineage_depth'] !== 0) return false;
                foreach ($proof['library_contract']['recipe_hashes'] as $hash) $this->typedRecipe($hash);
            } elseif (in_array($proof['mode'], ['original_parent_observation', 'original_parent_owned_outcome'], true)) {
                $parent = ModelVersion::find($proof['source_model_id']);
                if (! $parent || $parent->id >= $target->id || $this->protected($parent)
                    || $this->physical($parent)['family'] !== $this->physical($target)['family']
                    || data_get($parent->metadata, InstrumentMethodResearchBindingService::METADATA.'.policy_hash') !== $proof['source_policy_hash']
                    || $proof['lineage_depth'] !== (int) data_get($parent->metadata, self::METADATA.'.lineage_depth', 0) + 1) return false;
                $proposal = $this->learning->policy($proof['source_proposal_hash']);
                if (($proposal['status'] ?? null) === 'blocked' || ($proposal['agent_key'] ?? null) !== 'model:'.$parent->id
                    || data_get($proposal, 'learning_provenance.parent_policy_hash') !== $proof['source_policy_hash']
                    || $this->hash($proposal['library_contract']) !== $this->hash($proof['library_contract'])
                    || $this->hash($proposal['rules']) !== $this->hash($proof['rules'])
                    || $proposal['default_action'] !== $proof['default_action']) return false;
                if ($proof['mode'] === 'original_parent_owned_outcome'
                    && (data_get($proposal, 'learning_provenance.protocol') !== AgentMethodLearningService::OWNED_OUTCOME_PROVENANCE
                        || data_get($proof, 'constructor.owned_outcome_learning') !== true)) return false;
            } else return false;
            $binding = data_get($target->metadata, InstrumentMethodResearchBindingService::METADATA);
            if ($binding !== null && (! is_array($binding) || ($binding['agent_key'] ?? null) !== $body['agent_key']
                || ($binding['policy_hash'] ?? null) !== $this->hash(array_diff_key($body, array_flip(['policy_hash', 'policy_json']))))) return false;
            return true;
        } catch (Throwable) { return false; }
    }

    private function curatedDefault(?array $declaredHashes = null): array
    {
        $hashes = $declaredHashes ?? config('services.instrument_methods.generation_recipe_hashes', []);
        $this->need(is_array($hashes) && array_is_list($hashes) && count($hashes) > 0 && count($hashes) <= 3
            && count(array_unique($hashes, SORT_REGULAR)) === count($hashes), 'METHOD_GENERATION_CURATED_DEFAULT_REQUIRED');
        $rules = [];
        foreach ($hashes as $hash) {
            $recipe = $this->typedRecipe($hash);
            $contextKey = $this->hash($recipe['context_predicate']);
            $rules[$contextKey] ??= ['context_predicate' => $recipe['context_predicate'], 'preferred_recipes' => []];
            $rules[$contextKey]['preferred_recipes'][] = $hash;
        }
        $library = $this->library->librarySnapshot($hashes);
        $this->need(($library['valid'] ?? false) === true, 'METHOD_GENERATION_CURATED_LIBRARY_INVALID');
        ksort($rules);
        return [...$library, 'rules' => array_values($rules), 'default_action' => 'BASELINE'];
    }

    private function ownedOutcomeProposalOnce(string $policyHash): array
    {
        try {
            return DB::transaction(function () use ($policyHash): array {
                $row = DB::table('agent_method_policy_revisions')->where('policy_hash', $policyHash)->lockForUpdate()->first();
                $this->need($row !== null, 'METHOD_GENERATION_SOURCE_POLICY_REQUIRED');
                $policy = $this->learning->policy($policyHash);
                $this->need(($policy['status'] ?? null) !== 'blocked', 'METHOD_GENERATION_SOURCE_POLICY_INVALID');
                foreach (DB::table('agent_method_policy_revisions')->where('agent_key', $policy['agent_key'])->orderBy('revision')->get() as $candidate) {
                    $body = json_decode($candidate->contract, true, 64, JSON_THROW_ON_ERROR);
                    if (data_get($body, 'learning_provenance.parent_policy_hash') !== $policyHash
                        || data_get($body, 'learning_provenance.protocol') !== AgentMethodLearningService::OWNED_OUTCOME_PROVENANCE) continue;
                    $original = $this->learning->policy($candidate->policy_hash);
                    $this->need(($original['status'] ?? null) !== 'blocked', 'METHOD_GENERATION_ORIGINAL_PROPOSAL_DRIFT');
                    return [...$original, 'proposal_status' => 'frozen_owned_outcome_proposal', 'already_proposed' => true];
                }
                $next = (int) DB::table('agent_method_policy_revisions')->where('agent_key', $policy['agent_key'])->max('revision') + 1;
                return [...$this->learning->proposeOutcomePolicy($policyHash, $next, 3), 'already_proposed' => false];
            });
        } catch (Throwable $error) { return $this->dependency($this->safeReason($error)); }
    }

    private function preferOwnedOutcomeProposal(ModelVersion $parent): array
    {
        try {
            $source = data_get($parent->metadata, InstrumentMethodResearchBindingService::METADATA.'.policy_hash');
            $policy = $this->learning->policy($source);
            $this->need(($policy['status'] ?? null) !== 'blocked', 'OWNED_METHOD_PARENT_POLICY_INVALID');
            // Missing old observation fields may justify a v1 fallback, but a parent that
            // already declares owned-outcome learning must not silently downgrade its proof.
            $requiresOwned = data_get($parent->metadata, self::METADATA.'.constructor.owned_outcome_learning') === true
                || data_get($parent->metadata, self::METADATA.'.mode') === 'original_parent_owned_outcome'
                || data_get($policy, 'learning_provenance.protocol') === AgentMethodLearningService::OWNED_OUTCOME_PROVENANCE
                || data_get($policy, 'learning_provenance.mode') === 'original_parent_owned_outcome'
                || data_get($policy, 'learning_provenance.constructor.owned_outcome_learning') === true;
            if (! Schema::hasTable('agent_method_owned_outcomes')) {
                $this->need(! $requiresOwned, 'OWNED_METHOD_MIGRATION_NOT_AVAILABLE');
                return ['status' => 'legacy_observational_fallback', 'reason' => 'OWNED_METHOD_MIGRATION_NOT_AVAILABLE'];
            }
            $runs = LabEvaluationRun::where('model_version_id', $parent->id)->where('status', 'completed')->orderBy('id')->limit(65)->get();
            $this->need($runs->count() <= 64, 'OWNED_METHOD_ORIGINAL_RUN_BUDGET_EXCEEDED');
            $fallback = ['OWNED_METHOD_ORDINARY_SINGLE_POSITION_REQUIRED', 'OWNED_METHOD_SINGLE_POLICY_OWNER_REQUIRED',
                'OWNED_METHOD_METHOD_EVENT_INDEX_REQUIRED', 'OWNED_METHOD_ACCEPTED_ENTRY_LEDGER_LINK_REQUIRED',
                'OWNED_METHOD_ENTRY_METHOD_MISSING', 'OWNED_METHOD_ORIGINAL_CLOCK_MISSING'];
            foreach ($runs as $run) {
                $learned = $this->learning->learnOwnedOutcomesFromRun($run);
                if (($learned['status'] ?? null) === 'learned_owned_outcomes') continue;
                if (! $requiresOwned && in_array($learned['reason'] ?? null, $fallback, true)) continue;
                return ['status' => 'blocked', 'reason' => $learned['reason'] ?? 'OWNED_METHOD_ORIGINAL_VERIFICATION_FAILED'];
            }
            if (! DB::table('agent_method_owned_outcomes')->where('policy_hash', $source)->exists()) {
                $this->need(! $requiresOwned, 'OWNED_METHOD_NO_EXACT_MATURED_OUTCOMES');
                return ['status' => 'legacy_observational_fallback', 'reason' => 'OWNED_METHOD_NO_EXACT_MATURED_OUTCOMES'];
            }
            $proposal = $this->proposalOnce($source, true);
            if (($proposal['proposal_status'] ?? null) !== 'frozen_owned_outcome_proposal')
                return ['status' => 'blocked', 'reason' => $proposal['reason'] ?? 'OWNED_METHOD_PROPOSAL_NOT_VERIFIED'];
            if ($proposal['rules'] !== []) return ['status' => 'selected_owned_outcomes', 'reason' => 'OWNED_METHOD_POWERED_POSITIVE_ASSOCIATION'];
            $statistics = (array) data_get($proposal, 'learning_provenance.contextual_statistics', []);
            $powered = false;
            foreach ($statistics as $group) foreach ($group as $item) if ($item['matured_episodes'] >= AgentMethodLearningService::OWNED_OUTCOME_MINIMUM) $powered = true;
            return ['status' => 'selected_owned_outcomes', 'reason' => $powered
                ? 'OWNED_METHOD_POWERED_NONPOSITIVE_BASELINE' : 'OWNED_METHOD_UNDERPOWERED_BASELINE'];
        } catch (Throwable $error) { return ['status' => 'blocked', 'reason' => $this->safeReason($error)]; }
    }

    private function typedRecipe(string $hash): array
    {
        $recipe = $this->library->inspectRecipe($hash);
        $this->need(($recipe['status'] ?? null) === 'registered_research_only', 'METHOD_GENERATION_CURATED_RECIPE_INVALID');
        foreach ($recipe['contract']['method_contracts'] as $method) {
            $this->need(($method['implementation']['kind'] ?? null) === 'typed_ast', 'METHOD_GENERATION_PARAMETER_ADAPTER_REQUIRES_EXACT_CONSTRUCTOR');
        }
        return $recipe['contract'];
    }
    private function physical(ModelVersion $model): array
    {
        $family = $this->schemas->family((string) data_get($model->metadata, 'base_strategy', $model->strategy));
        $this->schemas->validate($family, (array) $model->parameters);
        return ['family' => $family, 'strategy' => $model->strategy, 'parameters' => (array) $model->parameters,
            'components' => array_intersect_key((array) $model->metadata, array_flip(self::PHYSICAL))];
    }
    private function protected(ModelVersion $model, array $niche = []): bool
    {
        foreach (self::PROTECTED as $field) if (filled(data_get($model->metadata, $field)) || filled(data_get($niche, $field))) return true;
        return in_array(data_get($niche, 'arm'), ['control', 'uncertainty_abstain', 'adversarial_guard'], true);
    }
    private function unused(ModelVersion $model): bool
    {
        return ! LabAgent::where('model_version_id', $model->id)->exists() && ! LabEvaluationRun::where('model_version_id', $model->id)->exists()
            && $model->promoted_at === null && $model->invalidated_at === null
            && in_array($model->status, ['draft', 'testing'], true);
    }
    private function ceiling(): array { return ['research_only' => true, 'out_of_sample' => false, 'confirmation_proven' => false,
        'mastery_proven' => false, 'skill_credit' => false, 'economic_authority' => false, 'paper_authority' => false,
        'promotion_evidence' => false, 'live_authority' => false]; }
    private function dependency(string $reason): array { return ['status' => 'dependency', 'reason' => $reason, ...$this->ceiling()]; }
    private function need(bool $condition, string $reason): void { if (! $condition) throw new LogicException($reason); }
    private function hash(array $body): string { return $this->hashes->parameterHash($body); }
    private function seal(array $body): string
    {
        $key = config('app.key');
        $this->need(is_string($key) && $key !== '', 'METHOD_GENERATION_SERVER_SEAL_KEY_REQUIRED');
        return hash_hmac('sha256', self::PROTOCOL.':'.$this->hash($body), $key);
    }
    private function safeReason(Throwable $error): string { return preg_match('/^(METHOD_GENERATION_|OWNED_METHOD_)[A-Z0-9_]+$/D', $error->getMessage()) ? $error->getMessage() : 'METHOD_GENERATION_ORIGINAL_VERIFICATION_FAILED'; }
}
