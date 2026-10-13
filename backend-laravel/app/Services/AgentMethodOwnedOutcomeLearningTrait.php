<?php

namespace App\Services;

use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Append-only personal research outcomes; legacy invocation memory is untouched. */
trait AgentMethodOwnedOutcomeLearningTrait
{
    public const OWNED_OUTCOME_PROTOCOL = 'agent_method_owned_outcome_v2';
    public const OWNED_OUTCOME_PROVENANCE = 'frozen_owned_method_learning_outcome_v2';
    public const OWNED_OUTCOME_MINIMUM = 5;
    private const OWNED_OUTCOME_MAX_EVENTS = 200000;
    private const OWNED_OUTCOME_MAX_EPISODES = 10000;

    /** Supplied products/scores are never accepted; historical originals remain seen research. */
    public function learnOwnedOutcomesFromRun(LabEvaluationRun $suppliedRun): array
    {
        try {
            $proof = $this->originalProof((string) $suppliedRun->run_id);
            $extracted = $this->ownedOriginalOutcomes($proof);
            $written = DB::transaction(function () use ($extracted): array {
                $keys = []; $duplicates = 0; $oldCache = [];
                foreach ($extracted['outcomes'] as $outcome) {
                    $old = DB::table('agent_method_owned_outcomes')->where('policy_hash', $extracted['policy_hash'])
                        ->where('physical_episode_hash', $outcome['physical_episode_hash'])->lockForUpdate()->first();
                    if ($old) {
                        $stored = $this->verifiedOwnedOutcomeRow($old, $oldCache);
                        $this->require($stored['episode_content_hash'] === $outcome['facts']['episode_content_hash'],
                            'OWNED_METHOD_PHYSICAL_EPISODE_CONFLICT');
                        $duplicates++; $keys[] = $old->outcome_key; continue;
                    }
                    DB::table('agent_method_owned_outcomes')->insert(['outcome_key' => $outcome['outcome_key'],
                        'policy_hash' => $extracted['policy_hash'], 'physical_episode_hash' => $outcome['physical_episode_hash'],
                        'context_key' => $outcome['context_key'], 'bundle_hash' => $outcome['bundle_hash'],
                        'source_proof_hash' => $outcome['facts']['source_proof_hash'], 'run_id' => $extracted['run_id'],
                        'facts' => $this->json($outcome['facts']), 'created_at' => now()]);
                    $keys[] = $outcome['outcome_key'];
                }
                return ['outcome_keys' => $keys, 'duplicate_episodes_excluded' => $duplicates];
            });
            return ['status' => 'learned_owned_outcomes', 'protocol' => self::OWNED_OUTCOME_PROTOCOL,
                'run_id' => $extracted['run_id'], 'policy_hash' => $extracted['policy_hash'], ...$written,
                'matured_episodes' => count($extracted['outcomes']), 'censored_excluded' => $extracted['censored_excluded'],
                'post_paper_labels_excluded' => $extracted['post_paper_labels_excluded'],
                'wait_veto_utility' => 'unknown_without_exact_counterfactual', ...$this->ownedOutcomeCeiling()];
        } catch (Throwable $error) { return $this->blocked($this->ownedOutcomeReason($error)); }
    }

    /** One prospectively consumed higher revision; evidence never edits the instrument library. */
    public function proposeOutcomePolicy(string $parentPolicyHash, int $revision, int $maxRecipes = 3): array
    {
        try {
            $parent = $this->policy($parentPolicyHash);
            $this->require(($parent['status'] ?? null) !== 'blocked', 'OWNED_METHOD_PARENT_POLICY_REQUIRED');
            $this->require($revision > $parent['revision'] && $maxRecipes >= 1 && $maxRecipes <= 8,
                'OWNED_METHOD_PROPOSAL_BOUNDS_INVALID');
            $rows = DB::table('agent_method_owned_outcomes')->where('policy_hash', $parentPolicyHash)->orderBy('outcome_key')->get();
            $this->require($rows->count() <= self::OWNED_OUTCOME_MAX_EPISODES, 'OWNED_METHOD_OUTCOME_BUDGET_EXCEEDED');
            $facts = []; $sources = []; $cache = [];
            foreach ($rows as $row) {
                $fact = $this->verifiedOwnedOutcomeRow($row, $cache);
                $facts[] = $fact;
                $sources[] = ['outcome_key' => $row->outcome_key, 'run_id' => $row->run_id,
                    'source_proof_hash' => $fact['source_proof_hash'], 'facts_hash' => $this->hash($fact)];
            }
            $this->require($sources !== [], 'OWNED_METHOD_ORIGINAL_OUTCOMES_REQUIRED');
            $selection = $this->ownedOutcomeSelection($facts, $maxRecipes);
            $provenance = ['protocol' => self::OWNED_OUTCOME_PROVENANCE, 'parent_policy_hash' => $parentPolicyHash,
                'sources' => $sources, 'selection' => 'fixed_5_owned_matured_positive_mean_net_cash_v2',
                'minimum_matured_episodes' => self::OWNED_OUTCOME_MINIMUM, 'max_recipes' => $maxRecipes,
                'contextual_statistics' => $selection['statistics'], 'physical_controls_hash' => $selection['physical_controls_hash'],
                'observation_scope' => 'own_seen_original_episode_association', 'wait_veto_utility' => 'unknown_without_exact_counterfactual',
                ...$this->ownedOutcomeCeiling()];
            $provenance['server_seal'] = $this->ownedOutcomeSeal($provenance);
            $result = $this->registerPolicyBody(['agent_key' => $parent['agent_key'], 'revision' => $revision,
                'library_hash' => $parent['library_hash'], 'library_contract' => $parent['library_contract'],
                'rules' => $selection['rules'], 'default_action' => 'BASELINE', 'learning_provenance' => $provenance],
                serverDerivedOutcome: true);
            return [...$result, 'proposal_status' => ($result['status'] ?? null) === 'blocked' ? 'blocked' : 'frozen_owned_outcome_proposal',
                'outcome_ranking' => $selection['rules'] === [] ? 'insufficient_or_nonpositive_baseline' : 'positive_observed_association'];
        } catch (Throwable $error) { return $this->blocked($this->ownedOutcomeReason($error)); }
    }

    private function verifiedOutcomeProvenance(array $proof, array $body): bool
    {
        try {
            $allowed = ['protocol', 'parent_policy_hash', 'sources', 'selection', 'minimum_matured_episodes', 'max_recipes',
                'contextual_statistics', 'physical_controls_hash', 'observation_scope', 'wait_veto_utility', 'server_seal',
                ...array_keys($this->ownedOutcomeCeiling())];
            if (array_diff(array_keys($proof), $allowed) !== [] || ($proof['protocol'] ?? null) !== self::OWNED_OUTCOME_PROVENANCE
                || ($proof['selection'] ?? null) !== 'fixed_5_owned_matured_positive_mean_net_cash_v2'
                || ($proof['minimum_matured_episodes'] ?? null) !== self::OWNED_OUTCOME_MINIMUM
                || ! is_int($proof['max_recipes'] ?? null) || $proof['max_recipes'] < 1 || $proof['max_recipes'] > 8
                || ($proof['observation_scope'] ?? null) !== 'own_seen_original_episode_association'
                || ($proof['wait_veto_utility'] ?? null) !== 'unknown_without_exact_counterfactual'
                || ! is_array($proof['sources'] ?? null) || $proof['sources'] === [] || count($proof['sources']) > self::OWNED_OUTCOME_MAX_EPISODES
                || ! is_string($proof['server_seal'] ?? null)
                || ! hash_equals($this->ownedOutcomeSeal(array_diff_key($proof, ['server_seal' => true])), $proof['server_seal'])) return false;
            foreach ($this->ownedOutcomeCeiling() as $key => $value) if (($proof[$key] ?? null) !== $value) return false;
            $parent = $this->policy($proof['parent_policy_hash']);
            if (($parent['status'] ?? null) === 'blocked' || $parent['agent_key'] !== $body['agent_key']
                || $parent['revision'] >= $body['revision'] || $parent['library_hash'] !== $body['library_hash']
                || $body['default_action'] !== 'BASELINE') return false;
            $facts = []; $keys = []; $cache = [];
            foreach ($proof['sources'] as $source) {
                if (! is_array($source) || array_diff(array_keys($source), ['outcome_key', 'run_id', 'source_proof_hash', 'facts_hash']) !== []
                    || isset($keys[$source['outcome_key'] ?? ''])) return false;
                $row = DB::table('agent_method_owned_outcomes')->where('outcome_key', $source['outcome_key'] ?? '')->first();
                if (! $row || $row->policy_hash !== $parent['policy_hash']) return false;
                $fact = $this->verifiedOwnedOutcomeRow($row, $cache);
                if (($source['run_id'] ?? null) !== $row->run_id || ($source['source_proof_hash'] ?? null) !== $fact['source_proof_hash']
                    || ($source['facts_hash'] ?? null) !== $this->hash($fact)) return false;
                $keys[$source['outcome_key']] = true; $facts[] = $fact;
            }
            $selection = $this->ownedOutcomeSelection($facts, $proof['max_recipes']);
            return $this->hash($selection['rules']) === $this->hash($body['rules'])
                && $this->hash($selection['statistics']) === $this->hash($proof['contextual_statistics'])
                && $selection['physical_controls_hash'] === $proof['physical_controls_hash'];
        } catch (Throwable) { return false; }
    }

    private function ownedOriginalOutcomes(array $proof): array
    {
        $run = $proof['run']; $request = $proof['request']; $response = $proof['response'];
        $this->require(empty($request['specialist_council_contract']) && empty($request['portfolio_members'])
            && empty($request['strategies']) && empty($response['specialist_council_receipt'])
            && empty(data_get($response, 'data_quality.specialist_council_receipt')), 'OWNED_METHOD_ORDINARY_SINGLE_POSITION_REQUIRED');
        $settled = $this->settleOriginalRun($run, $request, $response);
        $this->require(($settled['status'] ?? null) === 'verified_diagnostic' && count($settled['policy_hashes'] ?? []) === 1,
            $settled['reason'] ?? 'OWNED_METHOD_COMPLETE_METHOD_OWNER_REQUIRED');
        $policyHash = $settled['policy_hash']; $products = $this->selectedProducts($run, $request, $response);
        $this->require(count($products) === 1, 'OWNED_METHOD_SINGLE_POLICY_OWNER_REQUIRED');
        $contract = $products[0]['contract']; $policy = $contract['policy'];
        $this->require($run->model_version_id !== null && ($policy['agent_key'] ?? null) === 'model:'.$run->model_version_id,
            'OWNED_METHOD_EXACT_MODEL_OWNER_REQUIRED');
        $owner = app(LabImmutableEvidenceService::class);
        $originals = [];
        foreach (['decision_trace' => 'decision_trace', 'trade_ledger' => 'trade_ledger'] as $type => $key) {
            $artifact = LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', $type)->oldest('id')->first();
            $this->require($artifact !== null && data_get($artifact->metadata, 'complete') === true, 'OWNED_METHOD_FULL_ORIGINAL_PRODUCTS_REQUIRED');
            $body = $owner->readArtifactPayload($artifact);
            $this->require(is_array($body) && ($artifact->storage_path || $owner->hash($body) === $artifact->sha256)
                && $owner->hash($body) === $owner->hash((array) ($response[$key] ?? [])), 'OWNED_METHOD_ORIGINAL_PRODUCT_HASH_MISMATCH');
            $originals[$key] = $body; $originals[$key.'_artifact_hash'] = $artifact->sha256;
        }
        $trace = $originals['decision_trace']; $ledger = $originals['trade_ledger'];
        $this->require(array_is_list($trace) && array_is_list($ledger) && count($trace) <= self::OWNED_OUTCOME_MAX_EVENTS
            && count($ledger) <= self::OWNED_OUTCOME_MAX_EPISODES && ($response['total_trades'] ?? null) === count($ledger),
            'OWNED_METHOD_ORIGINAL_RESOURCE_OR_LEDGER_INVALID');
        $clock = data_get($response, 'data_quality.replay_executed_clock');
        $this->require($clock !== null && $clock !== [], 'OWNED_METHOD_ORIGINAL_CLOCK_MISSING');
        $seconds = ['M1' => 60, 'M5' => 300, 'M15' => 900, 'H1' => 3600, 'H4' => 14400, 'D1' => 86400][$request['timeframe'] ?? ''] ?? null;
        $this->require(is_array($clock) && ($clock['protocol'] ?? null) === 'replay_executed_clock_v1'
            && ($clock['owner'] ?? null) === 'ordinary_single_position_v1' && ($clock['complete'] ?? null) === true
            && ($clock['semantics'] ?? null) === 'previous_closed_candle_next_open_v1'
            && ($clock['duration_seconds'] ?? null) === $seconds && $seconds !== null
            && ($clock['dataset_hash'] ?? null) === $run->data_hash && ($request['replay_dataset_hash'] ?? null) === $run->data_hash
            && ($clock['execution_hash'] ?? null) === data_get($request, 'execution_contract.execution_hash')
            && ($clock['receipt_hash'] ?? null) === $this->hash(array_diff_key($clock, array_flip(['receipt_hash', 'receipt_json']))),
            'OWNED_METHOD_ORIGINAL_EXECUTED_CLOCK_REQUIRED');
        $this->require(($clock['index_basis'] ?? null) === 'evaluated_frame_zero_based_v1'
            && ($clock['execution_timeframe'] ?? null) === ($request['timeframe'] ?? null)
            && ($clock['promotion_evidence'] ?? null) === false && is_string($clock['receipt_json'] ?? null)
            && strlen($clock['receipt_json']) <= 8192 && hash('sha256', $clock['receipt_json']) === $clock['receipt_hash'],
            'OWNED_METHOD_ORIGINAL_CLOCK_SHAPE_INVALID');
        foreach (['input_rows', 'evaluation_offset_rows', 'decision_rows', 'first_evaluation_index', 'last_evaluation_index'] as $field)
            $this->require(is_int($clock[$field] ?? null) && $clock[$field] >= 0 && $clock[$field] <= self::OWNED_OUTCOME_MAX_EVENTS,
                'OWNED_METHOD_ORIGINAL_CLOCK_ROWS_INVALID');
        $this->require($clock['first_evaluation_index'] >= 1 && $clock['last_evaluation_index'] >= $clock['first_evaluation_index']
            && $clock['last_evaluation_index'] === $clock['input_rows'] - $clock['evaluation_offset_rows'] - 1
            && $clock['decision_rows'] === $clock['last_evaluation_index'] - $clock['first_evaluation_index'] + 1
            && $this->sha($clock['index_set_hash'] ?? null) && $this->sha($clock['schedule_hash'] ?? null),
            'OWNED_METHOD_ORIGINAL_CLOCK_COVERAGE_INVALID');
        $indexHash = hash_init('sha256'); hash_update($indexHash, "replay-executed-clock-v1:indices\n");
        for ($index = $clock['first_evaluation_index']; $index <= $clock['last_evaluation_index']; $index++) hash_update($indexHash, $index."\n");
        $this->require(hash_final($indexHash) === $clock['index_set_hash'], 'OWNED_METHOD_ORIGINAL_CLOCK_INDEX_HASH_INVALID');
        $start = $this->ownedUtc($clock['execution_start'] ?? null); $end = $this->ownedUtc($clock['execution_end'] ?? null);
        $this->require($start <= $end && $end < app(ResearchPaperEpochContractService::class)->cutoff(), 'OWNED_METHOD_PRE2026_CLOCK_REQUIRED');
        $this->require($this->ownedUtc($clock['signal_start'] ?? null)->addSeconds($seconds) <= $start
            && $this->ownedUtc($clock['signal_end'] ?? null)->addSeconds($seconds) <= $end,
            'OWNED_METHOD_CLOSED_SIGNAL_CLOCK_REQUIRED');
        $execution = $request['execution_contract'] ?? [];
        $this->require(($execution['protocol'] ?? null) === ExecutionContractService::PROTOCOL && is_array($execution['parameters'] ?? null)
            && app(ExecutionContractService::class)->hashParameters($execution['parameters']) === ($execution['execution_hash'] ?? null)
            && data_get($response, 'execution_contract.execution_hash') === $execution['execution_hash']
            && app(ExecutionContractService::class)->hashParameters((array) data_get($response, 'execution_contract.parameters')) === $execution['execution_hash'],
            'OWNED_METHOD_ACTUAL_EXECUTION_COSTS_REQUIRED');
        $controls = ['strategy' => $request['strategy'] ?? null, 'base_strategy' => $request['base_strategy'] ?? null,
            'parameters_hash' => $this->hash((array) ($request['parameters'] ?? [])), 'source_hash' => $run->code_hash,
            'execution_hash' => $execution['execution_hash'], 'clock_seconds' => $seconds,
            'account_risk_hash' => app(InstrumentMethodMetaModelService::class)->physicalAccountRiskHash($request),
            'runtime_basis_hash' => $this->hash((array) ($request['composition_runtime_contract'] ?? []))];
        $this->require($this->sha($controls['source_hash']) && $this->sha($controls['execution_hash']), 'OWNED_METHOD_SOURCE_CONTROLS_REQUIRED');
        $controlsHash = $this->hash($controls);
        $source = ['run_id' => $run->run_id, 'request_hash' => $run->request_hash, 'response_hash' => $run->response_hash,
            'request_artifact_hash' => $proof['request_artifact_hash'], 'response_artifact_hash' => $proof['response_artifact_hash'],
            'trace_artifact_hash' => $originals['decision_trace_artifact_hash'], 'ledger_artifact_hash' => $originals['trade_ledger_artifact_hash'],
            'clock_hash' => $clock['receipt_hash'], 'data_hash' => $run->data_hash, 'physical_controls_hash' => $controlsHash];
        $sourceHash = $this->hash($source); $accepted = []; $entryMethods = []; $exitMethods = [];
        foreach ($trace as $row) {
            if (isset($row['method_learning'])) {
                $method = $row['method_learning']; $index = $row['candle_index'] ?? null;
                $this->require(is_array($method) && ($method['policy_hash'] ?? null) === $policyHash
                    && ($method['agent_key'] ?? null) === $policy['agent_key'], 'OWNED_METHOD_FOREIGN_POLICY_FORBIDDEN');
                $this->require(is_int($index) && $index >= 0, 'OWNED_METHOD_METHOD_EVENT_INDEX_REQUIRED');
                if ($method['phase'] === 'entry') {
                    $this->require(! isset($entryMethods[$index]), 'OWNED_METHOD_AMBIGUOUS_ENTRY_LINK'); $entryMethods[$index] = $method;
                } else $exitMethods[$index][] = $method;
            }
            if (($row['event_type'] ?? null) !== 'signal_evaluation' || ($row['accepted'] ?? null) !== true) continue;
            $this->require(in_array($row['action'] ?? null, ['BUY', 'SELL'], true) && is_int($row['candle_index'] ?? null), 'OWNED_METHOD_ACCEPTED_ENTRY_TRACE_REQUIRED');
            $time = $this->ownedUtc($row['candle_time'] ?? null)->toIso8601String(); $key = $time.'|'.$row['action'];
            $this->require(! isset($accepted[$key]), 'OWNED_METHOD_AMBIGUOUS_ACCEPTED_ENTRY'); $accepted[$key] = $row;
        }
        $balance = $request['initial_balance'] ?? null;
        $this->require($this->ownedNumber($balance) && $balance > 0, 'OWNED_METHOD_INITIAL_ACCOUNT_REQUIRED');
        $balance = (float) $balance; $outcomes = []; $seen = []; $censored = 0; $paper = 0; $lastExit = null;
        foreach ($ledger as $trade) {
            $this->require(empty($trade['portfolio_member']) && empty($trade['specialist_id'])
                && (! isset($trade['model_version_id']) || $trade['model_version_id'] === $run->model_version_id), 'OWNED_METHOD_FOREIGN_POSITION_FORBIDDEN');
            $entry = $this->ownedUtc($trade['entry_time'] ?? null); $exit = $this->ownedUtc($trade['exit_time'] ?? null);
            $signal = $this->ownedUtc($trade['signal_time'] ?? null); $available = $exit->addSeconds($seconds);
            $this->require($signal->addSeconds($seconds) <= $entry && $entry <= $exit && $entry >= $start && $exit <= $end
                && ($lastExit === null || $entry > $lastExit), 'OWNED_METHOD_NON_CAUSAL_OR_OVERLAPPING_POSITION');
            $lastExit = $exit;
            $this->require($this->ownedNumber($trade['balance'] ?? null) && $this->ownedNumber($trade['execution_cost_percent'] ?? null)
                && is_string($trade['exit_reason'] ?? null), 'OWNED_METHOD_COST_INCLUSIVE_CLOSE_REQUIRED');
            $net = round((float) $trade['balance'] - $balance, 8); $balance = (float) $trade['balance'];
            if (str_contains(strtolower($trade['exit_reason']), 'end_of_data') || ($trade['outcome_matured'] ?? true) !== true
                || ($trade['censored'] ?? false) !== false) { $censored++; continue; }
            if ($available >= app(ResearchPaperEpochContractService::class)->cutoff()) { $paper++; continue; }
            $entryRow = $accepted[$entry->toIso8601String().'|'.($trade['direction'] ?? '')] ?? null;
            $this->require(is_array($entryRow), 'OWNED_METHOD_ACCEPTED_ENTRY_LEDGER_LINK_REQUIRED');
            $index = $entryRow['candle_index']; $method = $entryMethods[$index] ?? null;
            $this->require(is_array($method), 'OWNED_METHOD_ENTRY_METHOD_MISSING');
            $this->require($this->ownedUtc($method['observed_at']) <= $this->ownedUtc($method['decision_at'])
                && $this->ownedUtc($method['observed_at'])->equalTo($signal->addSeconds($seconds))
                && $this->ownedUtc($method['decision_at']) <= $entry
                && strtoupper($method['context']['direction'] ?? '') === ($trade['direction'] ?? null)
                && ($method['outputs']['confirmation'] ?? true) !== false && ($method['outputs']['risk_multiplier'] ?? 1) > 0,
                'OWNED_METHOD_ACTUAL_ENTRY_METHOD_LINK_REQUIRED');
            $path = []; $consumed = [];
            $include = function (array $event) use (&$path, &$consumed): void {
                if ($event['recipe_hash'] === null) return;
                $path[$event['recipe_hash']] = true;
                if ($event['used']) $consumed[$event['recipe_hash']] = true;
            };
            $include($method);
            foreach ($exitMethods as $exitIndex => $events) {
                if ($exitIndex <= $index) continue;
                foreach ($events as $event) {
                    $at = $this->ownedUtc($event['decision_at']);
                    if ($at >= $entry && $at <= $exit) $include($event);
                }
            }
            $path = array_keys($path); $consumed = array_keys($consumed); sort($path); sort($consumed);
            $single = count($path) === 1 && in_array($path[0], $consumed, true) && ($method['in_scope'] ?? false) === true ? $path[0] : null;
            $context = $method['context']; $contextKey = $this->hash($context);
            $physical = ['agent_key' => $policy['agent_key'], 'symbol' => $contract['scope']['symbol'],
                'timeframe' => $request['timeframe'], 'entry_time' => $entry->toIso8601String(), 'direction' => $trade['direction']];
            $physicalHash = $this->hash($physical); $bundleHash = $this->hash(['recipe_path' => $path, 'consumed_recipes' => $consumed]);
            $content = [...$physical, 'context' => $context, 'observed_at' => $this->ownedUtc($method['observed_at'])->toIso8601String(),
                'exit_time' => $exit->toIso8601String(), 'label_available_at' => $available->toIso8601String(),
                'net_cash' => $net, 'execution_cost_percent' => (float) $trade['execution_cost_percent'],
                'exit_reason' => $trade['exit_reason'], 'recipe_path' => $path, 'consumed_recipes' => $consumed,
                'rankable_recipe_hash' => $single, 'physical_controls_hash' => $controlsHash];
            $this->require(! isset($seen[$physicalHash]), 'OWNED_METHOD_DUPLICATE_POSITION_LEDGER'); $seen[$physicalHash] = true;
            $facts = ['protocol' => self::OWNED_OUTCOME_PROTOCOL, 'policy_hash' => $policyHash, 'library_hash' => $policy['library_hash'],
                'physical_episode_hash' => $physicalHash, 'context_key' => $contextKey, 'bundle_hash' => $bundleHash,
                'source_proof' => $source, 'source_proof_hash' => $sourceHash,
                'entry_event_hash' => $this->hash($entryRow), 'method_entry_hash' => $this->hash($method), 'close_hash' => $this->hash($trade),
                'episode' => $content, 'episode_content_hash' => $this->hash($content),
                'association' => count($path) > 1 ? 'multi_recipe_bundle_not_individual_effect' : ($single ? 'single_recipe_owned_association' : 'baseline_or_unconsumed'),
                'wait_veto_utility' => 'unknown_without_exact_counterfactual', ...$this->ownedOutcomeCeiling()];
            $facts['server_seal'] = $this->ownedOutcomeSeal($facts);
            $outcomeKey = $this->hash(['protocol' => self::OWNED_OUTCOME_PROTOCOL, 'policy_hash' => $policyHash, 'physical_episode_hash' => $physicalHash]);
            $outcomes[] = ['outcome_key' => $outcomeKey, 'physical_episode_hash' => $physicalHash,
                'context_key' => $contextKey, 'bundle_hash' => $bundleHash, 'facts' => $facts];
        }
        $this->require($this->ownedNumber($response['final_balance'] ?? null) && abs($balance - $response['final_balance']) <= 0.011,
            'OWNED_METHOD_FULL_ACCOUNT_RECONCILIATION_FAILED');
        return ['run_id' => $run->run_id, 'policy_hash' => $policyHash, 'outcomes' => $outcomes,
            'censored_excluded' => $censored, 'post_paper_labels_excluded' => $paper];
    }

    private function verifiedOwnedOutcomeRow(object $row, array &$cache = []): array
    {
        if (! isset($cache[$row->run_id])) {
            $original = $this->ownedOriginalOutcomes($this->originalProof($row->run_id));
            $cache[$row->run_id] = array_column($original['outcomes'], null, 'outcome_key');
        }
        $derived = $cache[$row->run_id][$row->outcome_key] ?? null;
        $this->require(is_array($derived) && $derived['facts']['policy_hash'] === $row->policy_hash
            && $derived['physical_episode_hash'] === $row->physical_episode_hash && $derived['context_key'] === $row->context_key
            && $derived['bundle_hash'] === $row->bundle_hash && $derived['facts']['source_proof_hash'] === $row->source_proof_hash
            && $this->hash(json_decode($row->facts, true, 64, JSON_THROW_ON_ERROR)) === $this->hash($derived['facts']),
            'OWNED_METHOD_ORIGINAL_OUTCOME_CHANGED');
        return $derived['facts'];
    }

    private function ownedOutcomeSelection(array $facts, int $maxRecipes): array
    {
        $controls = []; $groups = [];
        foreach ($facts as $fact) {
            $episode = $fact['episode']; $controls[$episode['physical_controls_hash']] = true;
            $recipe = $episode['rankable_recipe_hash']; if ($recipe === null) continue;
            $key = $fact['context_key']; $groups[$key][$recipe] ??= ['context' => $episode['context'], 'recipe_hash' => $recipe,
                'matured_episodes' => 0, 'net_cash_sum' => 0.0];
            $groups[$key][$recipe]['matured_episodes']++; $groups[$key][$recipe]['net_cash_sum'] += $episode['net_cash'];
        }
        $this->require(count($controls) === 1, 'OWNED_METHOD_MIXED_PHYSICAL_CONTROLS');
        ksort($groups); $rules = []; $statistics = [];
        foreach ($groups as $recipes) {
            $items = array_values($recipes);
            foreach ($items as &$item) { $item['mean_net_cash'] = $item['net_cash_sum'] / $item['matured_episodes'];
                $item['eligible'] = $item['matured_episodes'] >= self::OWNED_OUTCOME_MINIMUM && $item['mean_net_cash'] > 0; }
            unset($item);
            usort($items, fn ($a, $b) => ($b['mean_net_cash'] <=> $a['mean_net_cash']) ?: strcmp($a['recipe_hash'], $b['recipe_hash']));
            $eligible = array_values(array_filter($items, fn ($item) => $item['eligible']));
            if ($eligible !== [] && count($rules) < 16) $rules[] = ['context_predicate' => $eligible[0]['context'],
                'preferred_recipes' => array_column(array_slice($eligible, 0, $maxRecipes), 'recipe_hash')];
            $statistics[] = $items;
        }
        return ['rules' => $rules, 'statistics' => $statistics, 'physical_controls_hash' => array_key_first($controls)];
    }

    private function ownedUtc(mixed $value): CarbonImmutable
    {
        $this->require(is_string($value) && preg_match('/(?:Z|[+-]\d{2}:\d{2})$/D', $value) === 1, 'OWNED_METHOD_EXPLICIT_UTC_REQUIRED');
        return CarbonImmutable::parse($value)->utc();
    }
    private function ownedNumber(mixed $value): bool { return (is_int($value) || is_float($value)) && is_finite((float) $value); }
    private function ownedOutcomeSeal(array $body): string
    {
        $key = config('app.key'); $this->require(is_string($key) && $key !== '', 'OWNED_METHOD_SERVER_KEY_REQUIRED');
        return hash_hmac('sha256', self::OWNED_OUTCOME_PROTOCOL.':'.$this->hash($body), $key);
    }
    private function ownedOutcomeReason(Throwable $error): string
    {
        return preg_match('/^[A-Z][A-Z0-9_]+$/D', $error->getMessage()) ? $error->getMessage() : 'OWNED_METHOD_ORIGINAL_VERIFICATION_FAILED';
    }
    private function ownedOutcomeCeiling(): array { return [...$this->ceiling(), 'independent_validation' => false,
        'individual_causal_credit' => false, 'paper_authority' => false, 'mastery_proven' => false, 'skill_credit' => false]; }
}
