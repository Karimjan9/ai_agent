<?php

namespace App\Services;

use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use App\Models\LabAgent;
use App\Models\ModelVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Independent, diagnostic research memory. Instrument definitions never change here. */
class AgentMethodLearningService
{
    public const POLICY_PROTOCOL = 'agent_method_policy_v1';
    public const CONTRACT_PROTOCOL = 'instrument_method_learning_v1';
    private int $policyInspectionDepth = 0;

    public function registerPolicy(array $input): array
    {
        return $this->registerPolicyBody($input);
    }

    private function registerPolicyBody(array $input, bool $serverDerivedRebase = false): array
    {
        try {
            $allowed = ['protocol', 'agent_key', 'revision', 'library_hash', 'library_contract',
                'library_contract_json', 'recipe_hashes', 'rules', 'default_action', 'learning_provenance'];
            $this->require(array_diff(array_keys($input), $allowed) === [], 'POLICY_UNSUPPORTED_FIELDS');
            $this->require(! isset($input['protocol']) || $input['protocol'] === self::POLICY_PROTOCOL, 'POLICY_PROTOCOL_INVALID');
            $this->require(is_string($input['agent_key'] ?? null) && preg_match('/^[A-Za-z0-9_.:\/-]{1,150}$/D', $input['agent_key']) === 1,
                'POLICY_AGENT_KEY_REQUIRED');
            $this->require(is_int($input['revision'] ?? null) && $input['revision'] >= 1, 'POLICY_REVISION_REQUIRED');
            $owner = app(InstrumentMethodLibraryService::class);
            if (isset($input['library_contract'])) {
                $library = $owner->verifyLibrary(array_intersect_key($input,
                    array_flip(['library_contract', 'library_hash', 'library_contract_json'])));
            } elseif (isset($input['recipe_hashes'])) {
                $library = $owner->librarySnapshot($input['recipe_hashes']);
            } else {
                $library = $owner->verifyLibrary($owner->inventory());
            }
            $this->require(($library['valid'] ?? false) === true, 'POLICY_LIBRARY_NOT_VERIFIED');
            $this->require(! isset($input['library_hash']) || $input['library_hash'] === $library['library_hash'], 'POLICY_LIBRARY_HASH_MISMATCH');
            $body = ['protocol' => self::POLICY_PROTOCOL, 'agent_key' => $input['agent_key'],
                'revision' => $input['revision'], 'library_hash' => $library['library_hash'],
                'library_contract' => $library['library_contract'],
                'rules' => $this->rules($input['rules'] ?? [], $library['library_contract']['recipe_hashes']),
                'default_action' => $input['default_action'] ?? 'BASELINE'];
            $this->require(in_array($body['default_action'], ['BASELINE', 'WAIT'], true), 'POLICY_DEFAULT_ACTION_INVALID');
            // Provenance is produced by this owner, rather than accepting caller scores or authority flags.
            if (isset($input['learning_provenance'])) {
                $rebase = ($input['learning_provenance']['protocol'] ?? null) === 'fresh_model_method_learning_rebase_v1';
                $this->require(! $rebase || $serverDerivedRebase, 'POLICY_REBASE_REQUIRES_SERVER_DERIVATION');
                $this->require($rebase
                    ? app(AgentMethodCandidateService::class)->verifyRebasedProvenance($input['learning_provenance'], $body)
                    : $this->verifiedProvenance($input['learning_provenance'], $body), 'POLICY_LEARNING_PROVENANCE_INVALID');
                $body['learning_provenance'] = $input['learning_provenance'];
            }
            $hash = $this->hash($body);
            return DB::transaction(function () use ($body, $hash): array {
                $existing = DB::table('agent_method_policy_revisions')->where('agent_key', $body['agent_key'])
                    ->where('revision', $body['revision'])->lockForUpdate()->first();
                $this->require(! $existing || $existing->policy_hash === $hash, 'POLICY_REVISION_IS_IMMUTABLE');
                if (! $existing) {
                    DB::table('agent_method_policy_revisions')->insert(['policy_hash' => $hash,
                        'agent_key' => $body['agent_key'], 'revision' => $body['revision'], 'library_hash' => $body['library_hash'],
                        'contract' => $this->json($body), 'created_at' => now(), 'updated_at' => now()]);
                }
                return [...$body, 'policy_hash' => $hash, 'policy_json' => $this->json($body),
                    'status' => 'registered_research_only', ...$this->ceiling()];
            });
        } catch (Throwable $error) {
            return $this->blocked($error->getMessage());
        }
    }

    /** Carry an original learned proposal to a fresh, exact-baseline owned model once. */
    public function registerRebasedPolicy(string $sourceProposalHash, int $targetModelId): array
    {
        try {
            return DB::transaction(function () use ($sourceProposalHash, $targetModelId): array {
                $source = $this->policy($sourceProposalHash);
                $this->require(($source['status'] ?? null) !== 'blocked'
                    && data_get($source, 'learning_provenance.protocol') === 'frozen_method_learning_exposure_v1',
                    'POLICY_REBASE_VERIFIED_LEARNED_PROPOSAL_REQUIRED');
                $sourceBody = array_diff_key($source, array_flip(['policy_hash', 'policy_json']));
                $this->require($this->verifiedProvenance($source['learning_provenance'], $sourceBody), 'POLICY_REBASE_SOURCE_EXPOSURE_INVALID');
                $this->require(preg_match('/^model:([1-9][0-9]*)$/D', $source['agent_key'], $matches) === 1
                    && $targetModelId > 0 && $targetModelId !== (int) $matches[1], 'POLICY_REBASE_EXACT_FRESH_OWNER_REQUIRED');
                $target = ModelVersion::whereKey($targetModelId)->lockForUpdate()->first();
                $this->require($target && ! LabEvaluationRun::where('model_version_id', $targetModelId)->exists()
                    && ! LabAgent::where('model_version_id', $targetModelId)->exists()
                    && $target->promoted_at === null && $target->invalidated_at === null
                    && ! in_array($target->status, ['active', 'champion', 'paper', 'live', 'archived'], true)
                    && data_get($target->metadata, 'specialist_council') === null
                    && data_get($target->metadata, 'specialist_council_evaluation') === null, 'POLICY_REBASE_UNUSED_TARGET_REQUIRED');
                $candidate = data_get($target->metadata, 'instrument_method_candidate');
                $this->require(is_array($candidate) && $this->sha($candidate['candidate_key'] ?? null)
                    && is_int($candidate['lineage_depth'] ?? null) && $candidate['lineage_depth'] >= 1
                    && $candidate['lineage_depth'] <= 8, 'POLICY_REBASE_SERVER_CANDIDATE_REQUIRED');
                $owner = app(AgentMethodCandidateService::class);
                $provenance = ['protocol' => 'fresh_model_method_learning_rebase_v1', 'source_policy_hash' => $sourceProposalHash,
                    'source_model_id' => (int) $matches[1], 'target_model_id' => $targetModelId,
                    'candidate_key' => $candidate['candidate_key'], 'baseline_hash' => $this->hash($owner->baseline($target)),
                    'lineage_depth' => $candidate['lineage_depth'], 'research_only' => true,
                    'out_of_sample' => false, 'confirmation_proven' => false];
                return $this->registerPolicyBody(['agent_key' => 'model:'.$targetModelId, 'revision' => 1,
                    'library_hash' => $source['library_hash'], 'library_contract' => $source['library_contract'],
                    'rules' => $source['rules'], 'default_action' => $source['default_action'],
                    'learning_provenance' => $provenance], true);
            });
        } catch (Throwable $error) { return $this->blocked($error->getMessage()); }
    }

    /** Reinspect a frozen policy and its original library references, not the current library head. */
    public function policy(string $policyHash): array
    {
        $this->policyInspectionDepth++;
        try {
            $this->require($this->policyInspectionDepth <= 64, 'POLICY_PROVENANCE_DEPTH_EXCEEDED');
            $row = DB::table('agent_method_policy_revisions')->where('policy_hash', $policyHash)->first();
            $this->require($row !== null, 'POLICY_NOT_FOUND');
            $body = json_decode($row->contract, true, 64, JSON_THROW_ON_ERROR);
            $this->require($this->hash($body) === $policyHash && $body['agent_key'] === $row->agent_key
                && $body['revision'] === (int) $row->revision && $body['library_hash'] === $row->library_hash,
                'POLICY_CONTENT_MISMATCH');
            $library = app(InstrumentMethodLibraryService::class)->verifyLibrary([
                'library_contract' => $body['library_contract'], 'library_hash' => $body['library_hash']]);
            $this->require(($library['valid'] ?? false) === true, 'POLICY_LIBRARY_NOT_VERIFIED');
            $this->require($this->rules($body['rules'], $body['library_contract']['recipe_hashes']) === $body['rules'], 'POLICY_RULES_INVALID');
            if (isset($body['learning_provenance'])) {
                $this->require(($body['learning_provenance']['protocol'] ?? null) === 'fresh_model_method_learning_rebase_v1'
                    ? app(AgentMethodCandidateService::class)->verifyRebasedProvenance($body['learning_provenance'], $body)
                    : $this->verifiedProvenance($body['learning_provenance'], $body), 'POLICY_LEARNING_PROVENANCE_INVALID');
            }
            return [...$body, 'policy_hash' => $policyHash, 'policy_json' => $this->json($body)];
        } catch (Throwable $error) {
            return $this->blocked($error->getMessage());
        } finally {
            $this->policyInspectionDepth--;
        }
    }

    /** The only durable ingestion entrypoint reopens original immutable artifacts itself. */
    public function learnFromRun(LabEvaluationRun $suppliedRun): array
    {
        try {
            $proof = $this->originalProof((string) $suppliedRun->run_id);
            $settled = $this->settleOriginalRun($proof['run'], $proof['request'], $proof['response']);
            $this->require(($settled['status'] ?? null) === 'verified_diagnostic', $settled['reason'] ?? 'ORIGINAL_METHOD_EVIDENCE_REQUIRED');
            $keys = DB::transaction(function () use ($settled, $proof): array {
                $keys = [];
                foreach ($settled['experiences'] as $experience) {
                    $facts = [...$experience['facts'], 'request_artifact_hash' => $proof['request_artifact_hash'],
                        'response_artifact_hash' => $proof['response_artifact_hash']];
                    $existing = DB::table('agent_method_experiences')->where('experience_key', $experience['experience_key'])->lockForUpdate()->first();
                    $this->require(! $existing || $this->hash(json_decode($existing->facts, true)) === $this->hash($facts), 'EXPERIENCE_IS_IMMUTABLE');
                    if (! $existing) {
                        DB::table('agent_method_experiences')->insert(['experience_key' => $experience['experience_key'],
                            'policy_hash' => $experience['facts']['policy_hash'], 'run_id' => $settled['run_id'],
                            'recipe_hash' => $experience['recipe_hash'], 'context_key' => $experience['context_key'],
                            'facts' => $this->json($facts), 'created_at' => now(), 'updated_at' => now()]);
                    }
                    $keys[] = $experience['experience_key'];
                }
                return $keys;
            });
            return ['status' => 'learned_diagnostic', 'run_id' => $settled['run_id'], 'policy_hash' => $settled['policy_hash'],
                'policy_hashes' => $settled['policy_hashes'], 'experience_keys' => $keys,
                'observations' => $settled['observations'], ...$this->ceiling()];
        } catch (Throwable $error) {
            return $this->blocked($error->getMessage());
        }
    }

    /** Pure extraction for diagnostics/tests; this never writes caller-provided products. */
    public function settleOriginalRun(LabEvaluationRun $run, array $request, array $response): array
    {
        try {
            $products = $this->selectedProducts($run, $request, $response);
            $experiences = []; $policies = []; $observations = ['event_count' => 0, 'in_scope_count' => 0,
                'used_count' => 0, 'behavior_changed_count' => 0, 'veto_count' => 0, 'reason_counts' => []];
            foreach ($products as $product) {
                $settled = $this->settleContractRun($run, $request, $response, $product['contract'],
                    $product['receipt'], $product['expected_agent_key']);
                $this->require(($settled['status'] ?? null) === 'verified_diagnostic', $settled['reason'] ?? 'ORIGINAL_METHOD_EVIDENCE_REQUIRED');
                $this->require(! isset($policies[$settled['policy_hash']]), 'NATIVE_METHOD_POLICY_OWNER_DUPLICATE');
                $policies[$settled['policy_hash']] = true;
                $experiences = [...$experiences, ...$settled['experiences']];
                foreach (['event_count', 'in_scope_count', 'used_count', 'behavior_changed_count', 'veto_count'] as $key) $observations[$key] += $settled['observations'][$key];
                foreach ($settled['observations']['reason_counts'] as $reason => $count) $observations['reason_counts'][$reason] = ($observations['reason_counts'][$reason] ?? 0) + $count;
            }
            ksort($observations['reason_counts']);
            return ['status' => 'verified_diagnostic', 'run_id' => $run->run_id,
                'policy_hash' => count($policies) === 1 ? array_key_first($policies) : null,
                'policy_hashes' => array_keys($policies), 'observations' => $observations,
                'experiences' => $experiences, ...$this->ceiling()];
        } catch (Throwable $error) { return $this->blocked($error->getMessage()); }
    }

    private function settleContractRun(LabEvaluationRun $run, array $request, array $response,
        array $contract, array $receipt, ?string $expectedAgentKey): array
    {
        try {
            $this->require($run->status === 'completed' && $run->started_at && $run->finished_at
                && $run->finished_at->greaterThanOrEqualTo($run->started_at), 'COMPLETED_ORIGINAL_RUN_REQUIRED');
            $this->require($run->phase !== 'paper' && ($request['evaluation_mode'] ?? null) !== 'paper'
                && ! app(ResearchPaperEpochContractService::class)->researchInputHasPaperData($request), 'PAPER_DATA_FORBIDDEN');
            $owner = app(LabImmutableEvidenceService::class);
            $this->require($this->sha($run->request_hash) && $this->sha($run->response_hash)
                && $owner->hash($response) === $run->response_hash, 'ORIGINAL_RESPONSE_HASH_MISMATCH');
            $this->require(is_array($contract) && ($contract['protocol'] ?? null) === self::CONTRACT_PROTOCOL,
                'ORIGINAL_METHOD_CONTRACT_REQUIRED');
            $body = array_diff_key($contract, array_flip(['contract_hash', 'contract_json']));
            $this->require($this->hash($body) === ($contract['contract_hash'] ?? null), 'METHOD_CONTRACT_HASH_MISMATCH');
            $this->require(! isset($contract['contract_json']) || $this->hash(json_decode($contract['contract_json'], true, 64, JSON_THROW_ON_ERROR)) === $contract['contract_hash'],
                'METHOD_CONTRACT_JSON_MISMATCH');
            $policyHash = data_get($contract, 'policy.policy_hash');
            $this->require(is_string($policyHash), 'ORIGINAL_POLICY_REQUIRED');
            $policy = $this->policy($policyHash);
            $this->require(($policy['status'] ?? null) !== 'blocked', $policy['reason'] ?? 'ORIGINAL_POLICY_INVALID');
            $this->require($expectedAgentKey === null || $policy['agent_key'] === $expectedAgentKey,
                'ORIGINAL_METHOD_POLICY_OWNER_MISMATCH');
            $this->require(! isset($contract['expected_agent_key']) || $contract['expected_agent_key'] === $policy['agent_key'],
                'ORIGINAL_METHOD_POLICY_OWNER_MISMATCH');
            $registered = DB::table('agent_method_policy_revisions')->where('policy_hash', $policyHash)->first();
            $this->require(CarbonImmutable::parse($registered->created_at)->lessThanOrEqualTo($run->started_at), 'POLICY_MUST_PRECEDE_RUN');
            $policyBody = array_diff_key($policy, array_flip(['policy_hash', 'policy_json']));
            $actualPolicy = array_diff_key($contract['policy'], array_flip(['policy_hash', 'policy_json']));
            $this->require($this->hash($actualPolicy) === $this->hash($policyBody)
                && $contract['library_hash'] === $policy['library_hash']
                && $this->hash($contract['library_contract'] ?? []) === $this->hash($policy['library_contract']),
                'ORIGINAL_POLICY_OR_LIBRARY_MISMATCH');
            foreach ([['policy_json', $contract['policy'], $policyHash],
                ['library_contract_json', $contract, $contract['library_hash']]] as [$jsonKey, $container, $expectedHash]) {
                $this->require(! isset($container[$jsonKey]) || $this->hash(json_decode($container[$jsonKey], true, 64, JSON_THROW_ON_ERROR)) === $expectedHash,
                    'METHOD_IDENTITY_JSON_COPY_MISMATCH');
            }
            $this->require($this->sha($contract['data_hash'] ?? null) && $run->data_hash === $contract['data_hash']
                && $this->sha($contract['execution_hash'] ?? null), 'ORIGINAL_DATA_EXECUTION_REQUIRED');
            $scope = $contract['scope'] ?? [];
            $start = CarbonImmutable::parse($scope['evaluated_start'] ?? '');
            $end = CarbonImmutable::parse($scope['evaluated_end'] ?? '');
            $this->require(isset($scope['evaluated_start'], $scope['evaluated_end']) && $start->lessThanOrEqualTo($end)
                && $end->lessThan(app(ResearchPaperEpochContractService::class)->cutoff())
                && is_string($scope['symbol'] ?? null) && is_string($scope['timeframe'] ?? null), 'PRE_2026_METHOD_SCOPE_REQUIRED');
            $recipes = [];
            foreach ($contract['recipes'] ?? [] as $recipe) {
                $hash = $recipe['recipe_hash'] ?? '';
                $this->require($this->sha($hash) && ! isset($recipes[$hash])
                    && in_array($hash, $policy['library_contract']['recipe_hashes'], true), 'ORIGINAL_RECIPE_IDENTITY_INVALID');
                $runtimeBody = array_diff_key($recipe, array_flip(['runtime_hash', 'runtime_json']));
                $this->require($this->hash($runtimeBody) === ($recipe['runtime_hash'] ?? null), 'ORIGINAL_RECIPE_RUNTIME_HASH_MISMATCH');
                $this->require(! isset($recipe['runtime_json']) || $this->hash(json_decode($recipe['runtime_json'], true, 64, JSON_THROW_ON_ERROR)) === $recipe['runtime_hash'],
                    'ORIGINAL_RECIPE_RUNTIME_JSON_MISMATCH');
                $declaration = app(InstrumentMethodLibraryService::class)->inspectRecipe($hash);
                $this->require(($declaration['status'] ?? null) === 'registered_research_only'
                    && $this->hash($recipe['recipe_revision'] ?? []) === $this->hash($declaration['contract'])
                    && $this->hash($recipe['context_predicate'] ?? []) === $this->hash($declaration['contract']['context_predicate']),
                    'ORIGINAL_RECIPE_DECLARATION_MISMATCH');
                foreach (['data_hash', 'execution_hash'] as $binding) $this->require(data_get($recipe, 'scope_bindings.'.$binding) === $contract[$binding], 'ORIGINAL_RECIPE_SCOPE_MISMATCH');
                foreach (['symbol', 'timeframe'] as $binding) $this->require(data_get($recipe, 'scope_bindings.'.$binding) === $scope[$binding], 'ORIGINAL_RECIPE_SCOPE_MISMATCH');
                $this->require(data_get($recipe, 'scope_bindings.pre_2026_only') === true, 'ORIGINAL_RECIPE_SCOPE_MISMATCH');
                $recipes[$hash] = $recipe;
            }
            $expectedRecipes = $policy['library_contract']['recipe_hashes'];
            $actualRecipes = array_keys($recipes); sort($expectedRecipes); sort($actualRecipes);
            $this->require($expectedRecipes === $actualRecipes && count($recipes) <= 16, 'ORIGINAL_RECIPE_POOL_MISMATCH');
            $pool = array_map(fn ($recipe) => ['recipe_hash' => $recipe['recipe_hash'], 'runtime_hash' => $recipe['runtime_hash']], array_values($recipes));
            usort($pool, fn ($a, $b) => strcmp($a['recipe_hash'], $b['recipe_hash']));
            $this->require($this->hash(['recipes' => $pool]) === ($contract['compiled_pool_hash'] ?? null), 'COMPILED_POOL_HASH_MISMATCH');
            $this->require(is_array($receipt) && ($receipt['protocol'] ?? null) === 'instrument_method_learning_receipt_v1'
                && ($receipt['contract_hash'] ?? null) === $contract['contract_hash']
                && ($receipt['policy_hash'] ?? null) === $policyHash
                && ($receipt['library_hash'] ?? null) === $policy['library_hash']
                && ($receipt['agent_key'] ?? null) === $policy['agent_key']
                && ($receipt['revision'] ?? null) === $policy['revision']
                && ($receipt['compiled_pool_hash'] ?? null) === $contract['compiled_pool_hash']
                && ($receipt['data_hash'] ?? null) === $contract['data_hash']
                && ($receipt['execution_hash'] ?? null) === $contract['execution_hash']
                && $this->hash($receipt['scope'] ?? []) === $this->hash($scope)
                && ($receipt['research_only'] ?? null) === true && ($receipt['promotion_evidence'] ?? null) === false
                && ($receipt['economic_authority'] ?? null) === false, 'FRESH_METHOD_PRODUCER_RECEIPT_REQUIRED');
            $receiptBody = array_diff_key($receipt, array_flip(['receipt_hash', 'receipt_json']));
            $this->require($this->hash($receiptBody) === ($receipt['receipt_hash'] ?? null), 'METHOD_PRODUCER_RECEIPT_HASH_MISMATCH');
            $this->require(! isset($receipt['receipt_json']) || $this->hash(json_decode($receipt['receipt_json'], true, 64, JSON_THROW_ON_ERROR)) === $receipt['receipt_hash'],
                'METHOD_PRODUCER_RECEIPT_JSON_MISMATCH');
            $events = $this->events($response, $contract, $recipes, $start, $end);
            $this->require($events !== [], 'ORIGINAL_METHOD_EVENTS_REQUIRED');
            $observations = $this->counts($events);
            foreach (['event_count', 'used_count', 'in_scope_count', 'behavior_changed_count', 'reason_counts'] as $key) {
                $this->require(isset($receipt[$key]) && $this->hash([$receipt[$key]]) === $this->hash([$observations[$key]]), 'METHOD_RECEIPT_COUNTER_MISMATCH');
            }
            $this->require(($receipt['events_hash'] ?? null) === $this->eventHash($events), 'METHOD_RECEIPT_EVENTS_HASH_MISMATCH');
            $grouped = [];
            foreach ($events as $event) {
                $key = $this->hash(['context' => $event['context'], 'recipe_hash' => $event['recipe_hash']]);
                $grouped[$key][] = $event;
            }
            $experiences = [];
            foreach ($grouped as $group) {
                $context = $group[0]['context']; $recipeHash = $group[0]['recipe_hash'];
                $contextKey = $this->hash($context);
                $facts = ['protocol' => 'agent_method_contextual_experience_v1', 'run_id' => $run->run_id,
                    'policy_hash' => $policyHash, 'library_hash' => $policy['library_hash'], 'data_hash' => $run->data_hash,
                    'execution_hash' => $contract['execution_hash'], 'contract_hash' => $contract['contract_hash'],
                    'request_hash' => $run->request_hash, 'response_hash' => $run->response_hash,
                    'scope' => $scope, 'context' => $context, 'events_hash' => $this->hash($group),
                    'observations' => $this->counts($group), 'recipe_hash' => $recipeHash, ...$this->ceiling()];
                $experiences[] = ['experience_key' => $this->hash(['run_id' => $run->run_id, 'policy_hash' => $policyHash,
                    'context_key' => $contextKey, 'recipe_hash' => $recipeHash]), 'context_key' => $contextKey,
                    'recipe_hash' => $recipeHash, 'facts' => $facts];
            }
            return ['status' => 'verified_diagnostic', 'run_id' => $run->run_id, 'policy_hash' => $policyHash,
                'observations' => $observations, 'experiences' => $experiences, ...$this->ceiling()];
        } catch (Throwable $error) {
            return $this->blocked($error->getMessage());
        }
    }

    /** Resolve only the exact run owner; sibling arms/members cannot supply its policy. */
    private function selectedProducts(LabEvaluationRun $run, array $request, array $response): array
    {
        $selected = $request;
        if (isset($request['strategies'])) {
            $this->require(is_array($request['strategies']) && array_is_list($request['strategies']), 'ORIGINAL_STRATEGY_LIST_INVALID');
            $matches = array_values(array_filter($request['strategies'], fn ($strategy) => is_array($strategy)
                && isset($strategy['lab_agent_id']) && (int) $strategy['lab_agent_id'] === (int) $run->lab_agent_id));
            $this->require($run->lab_agent_id !== null && count($matches) === 1, 'ORIGINAL_EXACT_STRATEGY_OWNER_REQUIRED');
            $selected = $matches[0];
        }
        $native = $selected['specialist_council_contract'] ?? null;
        if ($native === null && ! isset($request['strategies'])) $native = $request['specialist_council_contract'] ?? null;
        if (is_array($native)) {
            $nativeReceipt = $response['specialist_council_receipt'] ?? data_get($response, 'data_quality.specialist_council_receipt');
            $this->require(is_array($nativeReceipt) && ($nativeReceipt['contract_hash'] ?? null) === ($native['contract_hash'] ?? null)
                && $this->hash(array_diff_key($native, array_flip(['contract_hash', 'contract_json']))) === $native['contract_hash'],
                'ORIGINAL_NATIVE_COUNCIL_CONTRACT_REQUIRED');
            $this->require(app(LabImmutableEvidenceService::class)->nativeDecisionTraceHashValid($response, $nativeReceipt),
                'ORIGINAL_NATIVE_METHOD_TRACE_HASH_MISMATCH');
            $products = [];
            foreach ($native['members'] ?? [] as $member) {
                if (! isset($member['method_learning'])) continue;
                $this->require(is_array($member['method_learning']) && is_int($member['model_version_id'] ?? null), 'NATIVE_METHOD_MEMBER_MODEL_REQUIRED');
                $receipts = array_values(array_filter($nativeReceipt['members'] ?? [], fn ($receipt) => is_array($receipt)
                    && ($receipt['specialist_id'] ?? null) === ($member['specialist_id'] ?? null)));
                $this->require(count($receipts) === 1 && ($receipts[0]['member_version_hash'] ?? null) === $this->hash([
                    'council_version' => $native['council_version'], 'member' => $member]), 'NATIVE_METHOD_MEMBER_RECEIPT_OWNER_MISMATCH');
                $this->require(is_array($receipts[0]['method_learning_receipt'] ?? null), 'NATIVE_METHOD_MEMBER_RECEIPT_REQUIRED');
                $products[] = ['contract' => $member['method_learning'], 'receipt' => $receipts[0]['method_learning_receipt'],
                    'expected_agent_key' => 'model:'.$member['model_version_id']];
            }
            $this->require($products !== [], 'ORIGINAL_NATIVE_METHOD_MEMBERS_REQUIRED');
            return $products;
        }
        $contract = $selected['instrument_method_learning'] ?? data_get($selected, 'policy_context.instrument_method_learning',
            data_get($selected, 'parameters.instrument_method_learning'));
        if ($contract === null && isset($request['strategies'])) $contract = data_get($request, 'policy_context.instrument_method_learning');
        $this->require(is_array($contract), 'ORIGINAL_METHOD_CONTRACT_REQUIRED');
        $receipt = data_get($response, 'data_quality.instrument_method_learning_receipt',
            data_get($response, 'data_quality.instrument_method_learning'));
        $this->require(is_array($receipt), 'FRESH_METHOD_PRODUCER_RECEIPT_REQUIRED');
        return [['contract' => $contract, 'receipt' => $receipt,
            'expected_agent_key' => $run->model_version_id === null ? null : 'model:'.$run->model_version_id]];
    }

    /** A bounded observational proposal; neither scores nor historical outcomes prove confirmation. */
    public function proposePolicy(string $policyHash, int $revision, int $maxRecipes = 3): array
    {
        try {
            $policy = $this->policy($policyHash);
            $this->require(($policy['status'] ?? null) !== 'blocked', $policy['reason'] ?? 'POLICY_REQUIRED');
            $this->require($revision > $policy['revision'] && $maxRecipes >= 1 && $maxRecipes <= 8, 'POLICY_PROPOSAL_BOUNDS_INVALID');
            $rows = DB::table('agent_method_experiences')->where('policy_hash', $policyHash)->orderBy('experience_key')->get();
            $contextRecipes = []; $sources = [];
            foreach ($rows as $row) {
                $facts = $this->verifiedExperience($row);
                $sources[] = ['experience_key' => $row->experience_key, 'run_id' => $row->run_id,
                    'request_hash' => $facts['request_hash'], 'response_hash' => $facts['response_hash'],
                    'facts_hash' => $this->hash($facts)];
                if ($row->recipe_hash === null || $facts['observations']['in_scope_count'] === 0) continue;
                $slot = &$contextRecipes[$row->context_key][$row->recipe_hash];
                $slot ??= ['context' => $facts['context'], 'recipe_hash' => $row->recipe_hash,
                    'event_count' => 0, 'in_scope_count' => 0, 'used_count' => 0, 'behavior_changed_count' => 0, 'veto_count' => 0];
                foreach (['event_count', 'in_scope_count', 'used_count', 'behavior_changed_count', 'veto_count'] as $counter) $slot[$counter] += $facts['observations'][$counter];
                unset($slot);
            }
            $this->require($sources !== [], 'VERIFIED_PRIOR_EXPERIENCES_REQUIRED');
            ksort($contextRecipes); $rules = []; $diagnostics = [];
            foreach ($contextRecipes as $candidates) {
                $candidates = array_values($candidates);
                usort($candidates, fn ($a, $b) => ($b['used_count'] <=> $a['used_count'])
                    ?: ($b['behavior_changed_count'] <=> $a['behavior_changed_count'])
                    ?: ($a['veto_count'] <=> $b['veto_count']) ?: strcmp($a['recipe_hash'], $b['recipe_hash']));
                $eligible = array_values(array_filter($candidates, fn ($item) => $item['used_count'] > 0));
                if ($eligible === []) continue;
                $rules[] = ['context_predicate' => $eligible[0]['context'],
                    'preferred_recipes' => array_column(array_slice($eligible, 0, $maxRecipes), 'recipe_hash')];
                $diagnostics[] = $candidates;
                if (count($rules) === 16) break;
            }
            $provenance = ['protocol' => 'frozen_method_learning_exposure_v1', 'parent_policy_hash' => $policyHash,
                'sources' => $sources, 'selection' => 'observed_use_then_behavior_then_veto',
                'max_recipes' => $maxRecipes, 'contextual_statistics' => $diagnostics,
                'out_of_sample' => false, 'confirmation_proven' => false];
            $result = $this->registerPolicy(['agent_key' => $policy['agent_key'], 'revision' => $revision,
                'library_hash' => $policy['library_hash'], 'library_contract' => $policy['library_contract'],
                'rules' => $rules, 'default_action' => $policy['default_action'], 'learning_provenance' => $provenance]);
            return [...$result, 'proposal_status' => ($result['status'] ?? null) === 'blocked' ? 'blocked' : 'frozen_diagnostic_proposal'];
        } catch (Throwable $error) {
            return $this->blocked($error->getMessage());
        }
    }

    public function statistics(): array
    {
        return ['protocol' => 'instrument_and_agent_method_learning_statistics_v1',
            'instrument_library' => ['method_revisions' => DB::table('instrument_method_revisions')->count(),
                'recipe_revisions' => DB::table('instrument_recipe_revisions')->count()],
            'agent_learning' => ['policy_revisions' => DB::table('agent_method_policy_revisions')->count(),
                'contextual_experiences' => DB::table('agent_method_experiences')->count(),
                'original_runs' => DB::table('agent_method_experiences')->distinct()->count('run_id')], ...$this->ceiling()];
    }

    private function originalProof(string $runId): array
    {
        $owner = app(LabImmutableEvidenceService::class); $run = $owner->findRun($runId);
        $this->require($run && $run->status === 'completed', 'COMPLETED_ORIGINAL_RUN_REQUIRED');
        $this->require(($owner->learningEligibility($run)['complete'] ?? false) === true, 'COMPLETE_ORIGINAL_REPLAY_EVIDENCE_REQUIRED');
        $identity = $owner->verifiedModelRuntimeIdentity($run);
        $this->require(is_array($identity), 'ORIGINAL_MODEL_RUNTIME_IDENTITY_REQUIRED');
        $requestArtifact = LabEvidenceArtifact::where('run_id', $runId)->where('artifact_type', 'evaluation_request')
            ->where('sha256', $identity['request_artifact_hash'])->oldest('id')->first();
        $responseArtifact = LabEvidenceArtifact::where('run_id', $runId)->where('artifact_type', 'evaluation_response')
            ->where('sha256', $run->response_hash)->oldest('id')->first();
        $this->require($requestArtifact && $responseArtifact, 'ORIGINAL_METHOD_ARTIFACTS_REQUIRED');
        $request = $owner->readArtifactPayload($requestArtifact); $response = $owner->readArtifactPayload($responseArtifact);
        $this->require(is_array($request) && is_array($response), 'ORIGINAL_METHOD_ARTIFACT_PAYLOAD_REQUIRED');
        if (! $requestArtifact->storage_path) $this->require($owner->hash($request) === $requestArtifact->sha256, 'ORIGINAL_REQUEST_ARTIFACT_HASH_MISMATCH');
        if (! $responseArtifact->storage_path) $this->require($owner->hash($response) === $responseArtifact->sha256, 'ORIGINAL_RESPONSE_ARTIFACT_HASH_MISMATCH');
        $this->require(data_get($requestArtifact->metadata, 'request_hash') === $run->request_hash, 'ORIGINAL_REQUEST_IDENTITY_MISMATCH');
        return ['run' => $run, 'request' => $request, 'response' => $response,
            'request_artifact_hash' => $requestArtifact->sha256, 'response_artifact_hash' => $responseArtifact->sha256];
    }

    private function verifiedExperience(object $row): array
    {
        $proof = $this->originalProof($row->run_id);
        $settled = $this->settleOriginalRun($proof['run'], $proof['request'], $proof['response']);
        $this->require(($settled['status'] ?? null) === 'verified_diagnostic', 'PRIOR_ORIGINAL_EXPERIENCE_INVALID');
        foreach ($settled['experiences'] as $experience) {
            if ($experience['experience_key'] !== $row->experience_key) continue;
            $facts = [...$experience['facts'], 'request_artifact_hash' => $proof['request_artifact_hash'], 'response_artifact_hash' => $proof['response_artifact_hash']];
            $this->require($row->policy_hash === $experience['facts']['policy_hash'] && $row->context_key === $experience['context_key']
                && $row->recipe_hash === $experience['recipe_hash'] && $this->hash(json_decode($row->facts, true)) === $this->hash($facts),
                'PRIOR_EXPERIENCE_CONTENT_MISMATCH');
            return $facts;
        }
        throw new \LogicException('PRIOR_EXPERIENCE_NOT_ORIGINAL');
    }

    private function verifiedProvenance(array $provenance, array $body): bool
    {
        if (($provenance['protocol'] ?? null) !== 'frozen_method_learning_exposure_v1'
            || ($provenance['selection'] ?? null) !== 'observed_use_then_behavior_then_veto'
            || ($provenance['out_of_sample'] ?? null) !== false || ($provenance['confirmation_proven'] ?? null) !== false
            || ! is_array($provenance['sources'] ?? null) || $provenance['sources'] === []
            || ! is_int($provenance['max_recipes'] ?? null) || $provenance['max_recipes'] < 1 || $provenance['max_recipes'] > 8
            || array_diff(array_keys($provenance), ['protocol', 'parent_policy_hash', 'sources', 'selection', 'max_recipes',
                'contextual_statistics', 'out_of_sample', 'confirmation_proven']) !== []) return false;
        $parent = $this->policy((string) ($provenance['parent_policy_hash'] ?? ''));
        if (($parent['status'] ?? null) === 'blocked' || $parent['agent_key'] !== $body['agent_key']
            || $parent['revision'] >= $body['revision'] || $parent['library_hash'] !== $body['library_hash']) return false;
        $keys = []; $contextRecipes = [];
        foreach ($provenance['sources'] as $source) {
            if (isset($keys[$source['experience_key'] ?? ''])) return false;
            $row = DB::table('agent_method_experiences')->where('experience_key', $source['experience_key'] ?? '')->first();
            if (! $row || $row->policy_hash !== $parent['policy_hash']) return false;
            $facts = $this->verifiedExperience($row);
            if (($source['facts_hash'] ?? null) !== $this->hash($facts) || ($source['run_id'] ?? null) !== $row->run_id
                || ($source['request_hash'] ?? null) !== $facts['request_hash'] || ($source['response_hash'] ?? null) !== $facts['response_hash']) return false;
            $keys[$source['experience_key']] = true;
            if ($row->recipe_hash === null || $facts['observations']['in_scope_count'] === 0) continue;
            $slot = &$contextRecipes[$row->context_key][$row->recipe_hash];
            $slot ??= ['context' => $facts['context'], 'recipe_hash' => $row->recipe_hash,
                'event_count' => 0, 'in_scope_count' => 0, 'used_count' => 0, 'behavior_changed_count' => 0, 'veto_count' => 0];
            foreach (['event_count', 'in_scope_count', 'used_count', 'behavior_changed_count', 'veto_count'] as $counter) $slot[$counter] += $facts['observations'][$counter];
            unset($slot);
        }
        ksort($contextRecipes); $rules = []; $diagnostics = [];
        foreach ($contextRecipes as $candidates) {
            $candidates = array_values($candidates);
            usort($candidates, fn ($a, $b) => ($b['used_count'] <=> $a['used_count'])
                ?: ($b['behavior_changed_count'] <=> $a['behavior_changed_count'])
                ?: ($a['veto_count'] <=> $b['veto_count']) ?: strcmp($a['recipe_hash'], $b['recipe_hash']));
            $eligible = array_values(array_filter($candidates, fn ($item) => $item['used_count'] > 0));
            if ($eligible === []) continue;
            $rules[] = ['context_predicate' => $eligible[0]['context'],
                'preferred_recipes' => array_column(array_slice($eligible, 0, $provenance['max_recipes']), 'recipe_hash')];
            $diagnostics[] = $candidates;
            if (count($rules) === 16) break;
        }
        return $this->hash($rules) === $this->hash($body['rules']) && $this->hash($diagnostics) === $this->hash($provenance['contextual_statistics'] ?? []);
    }

    private function events(array $response, array $contract, array $recipes, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $trace = $response['decision_trace'] ?? null;
        $this->require(is_array($trace) && array_is_list($trace), 'ORIGINAL_METHOD_TRACE_REQUIRED');
        $events = []; $keys = [];
        foreach ($trace as $row) {
            $candidates = $row['method_learning_events'] ?? (isset($row['method_learning']) ? [$row['method_learning']] : []);
            $this->require(is_array($candidates) && array_is_list($candidates), 'METHOD_TRACE_EVENTS_INVALID');
            foreach ($candidates as $event) {
                if (($event['policy_hash'] ?? null) !== $contract['policy']['policy_hash']) continue;
                $body = array_diff_key($event, array_flip(['receipt_hash']));
                $this->require(($event['protocol'] ?? null) === 'instrument_method_decision_v1'
                    && ($event['receipt_hash'] ?? null) === $this->hash($body)
                    && ($event['contract_hash'] ?? null) === $contract['contract_hash']
                    && ($event['library_hash'] ?? null) === $contract['library_hash']
                    && ($event['compiled_pool_hash'] ?? null) === $contract['compiled_pool_hash']
                    && ($event['agent_key'] ?? null) === $contract['policy']['agent_key']
                    && is_string($event['decision_id'] ?? null) && in_array($event['phase'] ?? null, ['entry', 'exit'], true)
                    && is_array($event['context'] ?? null) && ($event['context_hash'] ?? null) === $this->hash($event['context']), 'METHOD_TRACE_IDENTITY_INVALID');
                $at = CarbonImmutable::parse($event['decision_at'] ?? ''); $observed = CarbonImmutable::parse($event['observed_at'] ?? '');
                $this->require(isset($event['decision_at'], $event['observed_at']) && $observed->lessThanOrEqualTo($at)
                    && $at->lessThan(app(ResearchPaperEpochContractService::class)->cutoff())
                    && (! ($event['in_scope'] ?? false) || $observed->betweenIncluded($start, $end)), 'METHOD_TRACE_AS_OF_OR_SCOPE_INVALID');
                foreach (['in_scope', 'used', 'behavior_changed'] as $flag) $this->require(is_bool($event[$flag] ?? null), 'METHOD_TRACE_BOOLEAN_REQUIRED');
                $this->require(is_string($event['reason'] ?? null) && isset($event['outputs']) && is_array($event['outputs']), 'METHOD_TRACE_OUTPUT_REQUIRED');
                $this->require(($event['research_only'] ?? null) === true && ($event['promotion_evidence'] ?? null) === false
                    && ($event['economic_authority'] ?? null) === false, 'METHOD_TRACE_AUTHORITY_FORBIDDEN');
                $changed = ($event['phase'] === 'entry' && (($event['outputs']['confirmation'] ?? null) === false
                    || ($event['outputs']['risk_multiplier'] ?? 1) < 1))
                    || ($event['phase'] === 'exit' && ($event['outputs']['exit'] ?? null) === true);
                $this->require($event['behavior_changed'] === $changed && (! $event['used'] || $event['in_scope']), 'METHOD_TRACE_BEHAVIOR_MISMATCH');
                $recipeHash = $event['recipe_hash'] ?? null;
                $this->require(($recipeHash === null && ($event['runtime_hash'] ?? null) === null && ! $event['used'])
                    || (isset($recipes[$recipeHash]) && $event['runtime_hash'] === $recipes[$recipeHash]['runtime_hash']), 'METHOD_TRACE_RECIPE_MISMATCH');
                $allowedTargets = $event['phase'] === 'entry' ? ['confirmation', 'risk_multiplier'] : ['exit'];
                $this->require(array_diff(array_keys($event['outputs']), $allowedTargets) === [], 'METHOD_TRACE_OUTPUT_PHASE_INVALID');
                foreach ($event['outputs'] as $target => $value) {
                    $this->require($target === 'risk_multiplier'
                        ? ((is_float($value) || is_int($value)) && is_finite((float) $value) && $value >= 0 && $value <= 1)
                        : is_bool($value), 'METHOD_TRACE_OUTPUT_TYPE_INVALID');
                }
                if ($recipeHash !== null) {
                    $targets = array_values(array_intersect(array_column($recipes[$recipeHash]['operators'], 'target'), $allowedTargets));
                    $outputTargets = array_keys($event['outputs']); sort($targets); sort($outputTargets);
                    $this->require($targets === $outputTargets && $event['used'] === ($targets !== [])
                        && $event['reason'] === ($targets !== [] ? 'recipe_evaluated' : 'recipe_target_unreached'), 'METHOD_TRACE_DECLARED_TARGET_MISMATCH');
                }
                $key = $event['decision_id'].'|'.$event['phase'];
                $this->require(! isset($keys[$key]), 'METHOD_TRACE_DUPLICATE_DECISION'); $keys[$key] = true;
                $events[] = $event;
            }
        }
        return $events;
    }

    private function counts(array $events): array
    {
        $counts = ['event_count' => count($events), 'in_scope_count' => 0, 'used_count' => 0,
            'behavior_changed_count' => 0, 'veto_count' => 0, 'reason_counts' => []];
        foreach ($events as $event) {
            foreach (['in_scope' => 'in_scope_count', 'used' => 'used_count', 'behavior_changed' => 'behavior_changed_count'] as $flag => $counter) $counts[$counter] += (int) $event[$flag];
            $counts['veto_count'] += (int) ($event['phase'] === 'entry' && ($event['outputs']['confirmation'] ?? null) === false);
            $counts['reason_counts'][$event['reason']] = ($counts['reason_counts'][$event['reason']] ?? 0) + 1;
        }
        ksort($counts['reason_counts']);
        return $counts;
    }

    private function rules(array $rules, array $pool): array
    {
        $this->require(array_is_list($rules) && count($rules) <= 16, 'POLICY_RULE_BOUNDS_INVALID');
        foreach ($rules as $rule) {
            $this->require(is_array($rule) && array_diff(array_keys($rule), ['context_predicate', 'preferred_recipes']) === []
                && is_array($rule['context_predicate'] ?? null) && ($rule['context_predicate'] === [] || ! array_is_list($rule['context_predicate']))
                && is_array($rule['preferred_recipes'] ?? null) && array_is_list($rule['preferred_recipes'])
                && count($rule['preferred_recipes']) >= 1 && count($rule['preferred_recipes']) <= 8
                && count(array_unique($rule['preferred_recipes'])) === count($rule['preferred_recipes']), 'POLICY_RULE_INVALID');
            foreach ($rule['context_predicate'] as $key => $value) {
                $this->require(in_array($key, ['symbol', 'timeframe', 'regime', 'market_regime', 'volatility', 'volatility_regime',
                    'session', 'market_session', 'venue_phase', 'market_venue_phase', 'direction', 'spread', 'spread_context',
                    'spread_liquidity_state', 'transition_state'], true), 'POLICY_CONTEXT_INVALID');
                $options = is_array($value) ? $value : [$value];
                $this->require(array_is_list($options) && count($options) >= 1 && count($options) <= 32
                    && array_filter($options, fn ($option) => ! is_string($option) || $option === '' || strlen($option) > 100) === [], 'POLICY_CONTEXT_INVALID');
            }
            foreach ($rule['preferred_recipes'] as $recipe) $this->require(is_string($recipe) && in_array($recipe, $pool, true), 'POLICY_RECIPE_NOT_IN_LIBRARY');
        }
        return $rules;
    }

    private function ceiling(): array { return ['research_only' => true, 'out_of_sample' => false, 'confirmation_proven' => false,
        'skill_credit' => false, 'mentor_authority' => false, 'economic_authority' => false, 'promotion_evidence' => false]; }
    private function blocked(string $reason): array { return ['status' => 'blocked', 'reason' => $reason, ...$this->ceiling()]; }
    private function require(bool $condition, string $reason): void { if (! $condition) throw new \LogicException($reason); }
    private function sha(mixed $value): bool { return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1; }
    private function hash(array $body): string { return app(ResearchPaperEpochContractService::class)->parameterHash($body); }
    private function eventHash(array $events): string
    {
        $digest = hash_init('sha256');
        foreach ($events as $event) {
            $encoded = $this->json($event);
            hash_update($digest, pack('N2', 0, strlen($encoded)));
            hash_update($digest, $encoded);
        }
        return hash_final($digest);
    }
    private function json(array $body): string
    {
        $canonical = function ($value) use (&$canonical) {
            if (! is_array($value)) return $value;
            if (! array_is_list($value)) ksort($value);
            return array_map($canonical, $value);
        };
        return json_encode($canonical($body), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
