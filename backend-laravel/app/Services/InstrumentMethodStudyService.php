<?php

namespace App\Services;

use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use App\Models\ModelVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/** One prospectively frozen 2x2 question; observed contrasts remain local diagnostics. */
class InstrumentMethodStudyService
{
    public const PROTOCOL = 'instrument_method_factorial_study_v1';
    private const ARMS = ['L1A1', 'L2A1', 'L1A2', 'L2A2'];

    /** Reopen the original design; execution owners cannot supply a replacement design. */
    public function originalDesign(string $studyHash): array
    {
        $row = DB::table('instrument_method_studies')->where('study_hash', $studyHash)->first();
        $this->need($row !== null, 'STUDY_NOT_FOUND');
        $design = json_decode($row->design, true, 64, JSON_THROW_ON_ERROR);
        $this->need($this->hash($design) === $studyHash && ($design['protocol'] ?? null) === self::PROTOCOL,
            'STUDY_DESIGN_CONTENT_MISMATCH');
        foreach ($this->ceiling() as $key => $value) $this->need(($design[$key] ?? null) === $value, 'STUDY_DESIGN_AUTHORITY_FORBIDDEN');
        return $design;
    }

    public function preregister(array $input): array
    {
        try {
            $allowed = ['study_key', 'arms', 'data_hash', 'execution_hash', 'scope', 'evaluated_rows',
                'initial_balance', 'risk_per_trade', 'execution_costs', 'hard_limits', 'resource_caps', 'minimum_trades'];
            $this->need(array_diff(array_keys($input), $allowed) === [], 'STUDY_UNSUPPORTED_FIELDS');
            $this->need(is_string($input['study_key'] ?? null) && preg_match('/^[A-Za-z0-9_.:\/-]{1,150}$/D', $input['study_key']) === 1, 'STUDY_KEY_REQUIRED');
            $this->need($this->sha($input['data_hash'] ?? null) && $this->sha($input['execution_hash'] ?? null), 'STUDY_DATA_EXECUTION_REQUIRED');
            $this->scope($input['scope'] ?? []);
            foreach (['evaluated_rows', 'minimum_trades'] as $key) $this->need(is_int($input[$key] ?? null) && $input[$key] >= 1, 'STUDY_ROWS_AND_POWER_REQUIRED');
            foreach (['initial_balance', 'risk_per_trade'] as $key) $this->need($this->number($input[$key] ?? null) && $input[$key] > 0, 'STUDY_ACCOUNT_RISK_REQUIRED');
            foreach (['execution_costs', 'hard_limits', 'resource_caps'] as $key) $this->need(is_array($input[$key] ?? null) && $input[$key] !== [], 'STUDY_COST_RISK_CAPS_REQUIRED');
            $caps = $input['resource_caps'];
            $this->need(array_diff(array_keys($caps), ['max_rows', 'max_decision_events', 'max_runtime_seconds']) === [], 'STUDY_RESOURCE_CAP_FIELDS_INVALID');
            foreach (['max_rows', 'max_decision_events', 'max_runtime_seconds'] as $key) $this->need(is_int($caps[$key] ?? null) && $caps[$key] >= 1, 'STUDY_RESOURCE_CAPS_INVALID');
            $this->need($caps['max_rows'] <= 200000 && $caps['max_decision_events'] <= 1000000
                && $caps['max_runtime_seconds'] <= 1680, 'STUDY_RESOURCE_CAP_CEILING_EXCEEDED');
            $this->need($input['evaluated_rows'] <= $caps['max_rows'], 'STUDY_ROWS_EXCEED_RESOURCE_CAP');
            $arms = $input['arms'] ?? []; $keys = array_keys($arms); sort($keys); $expected = self::ARMS; sort($expected);
            $this->need($keys === $expected, 'STUDY_EXACT_FOUR_ARMS_REQUIRED');
            $models = []; $policies = []; $baseline = null;
            foreach (self::ARMS as $key) {
                $arm = $arms[$key];
                $this->need(is_array($arm) && array_diff(array_keys($arm), ['model_id', 'policy_hash']) === []
                    && is_int($arm['model_id'] ?? null) && $arm['model_id'] > 0 && $this->sha($arm['policy_hash'] ?? null), 'STUDY_ARM_MODEL_POLICY_REQUIRED');
                $this->need(! isset($models[$arm['model_id']]) && ! isset($policies[$arm['policy_hash']]), 'STUDY_ARM_IDENTITIES_MUST_BE_UNIQUE');
                $model = ModelVersion::find($arm['model_id']);
                $this->need($model !== null && ! LabEvaluationRun::where('model_version_id', $model->id)->exists(), 'STUDY_MUST_PRECEDE_ANY_MODEL_RUN');
                $policy = app(AgentMethodLearningService::class)->policy($arm['policy_hash']);
                $this->need(($policy['status'] ?? null) !== 'blocked' && $policy['agent_key'] === 'model:'.$model->id, 'STUDY_ARM_POLICY_OWNER_INVALID');
                $modelBaseline = $this->modelBaseline($model, $policy);
                $baseline ??= $modelBaseline;
                $this->need($this->hash($modelBaseline) === $this->hash($baseline), 'STUDY_BASELINE_PARAMETERS_OR_METADATA_DIFFER');
                $arms[$key] = [...$arm, 'library_hash' => $policy['library_hash'], 'policy_class' => $this->policyClass($policy),
                    'baseline_hash' => $this->hash($modelBaseline), 'parameter_hash' => $this->hash((array) $model->parameters),
                    'model_runtime_basis_hash' => $this->hash(app(LabImmutableEvidenceService::class)->modelRuntimeBasis($model))];
                $models[$arm['model_id']] = true; $policies[$arm['policy_hash']] = true;
            }
            $this->need($arms['L1A1']['library_hash'] === $arms['L1A2']['library_hash']
                && $arms['L2A1']['library_hash'] === $arms['L2A2']['library_hash']
                && $arms['L1A1']['library_hash'] !== $arms['L2A1']['library_hash'], 'STUDY_LIBRARY_AXIS_NOT_SEPARATE');
            $this->need($this->hash($arms['L1A1']['policy_class']) === $this->hash($arms['L2A1']['policy_class'])
                && $this->hash($arms['L1A2']['policy_class']) === $this->hash($arms['L2A2']['policy_class'])
                && $this->hash($arms['L1A1']['policy_class']) !== $this->hash($arms['L1A2']['policy_class']), 'STUDY_AGENT_AXIS_NOT_SEPARATE');
            $body = ['protocol' => self::PROTOCOL, ...$input, 'arms' => $arms, 'baseline' => $baseline,
                'effect_metric' => 'matured_net_profit_cash', 'classification_epsilon' => 0.00000001,
                'comparison' => 'paired_same_original_scope', 'independent_windows' => false, ...$this->ceiling()];
            $hash = $this->hash($body);
            return DB::transaction(function () use ($body, $hash): array {
                $row = DB::table('instrument_method_studies')->where('study_key', $body['study_key'])->lockForUpdate()->first();
                $this->need(! $row || $row->study_hash === $hash, 'STUDY_PREREGISTRATION_IS_IMMUTABLE');
                if (! $row) DB::table('instrument_method_studies')->insert(['study_hash' => $hash, 'study_key' => $body['study_key'],
                    'design' => $this->json($body), 'assessment' => null, 'created_at' => now(), 'updated_at' => now()]);
                return ['status' => 'preregistered_diagnostic', 'study_hash' => $hash, 'design' => $body, ...$this->ceiling()];
            });
        } catch (Throwable $error) { return $this->technical($error); }
    }

    /** Missing arms await originals; a complete assessment seals this exact four-run set once. */
    public function settle(string $studyHash, array $runIds): array
    {
        try {
            $row = DB::table('instrument_method_studies')->where('study_hash', $studyHash)->first();
            $this->need($row !== null, 'STUDY_NOT_FOUND');
            $design = json_decode($row->design, true, 64, JSON_THROW_ON_ERROR);
            $this->need($this->hash($design) === $studyHash, 'STUDY_DESIGN_CONTENT_MISMATCH');
            if ($runIds === [] || count($runIds) < 4) return ['status' => 'awaiting_originals', 'study_hash' => $studyHash,
                'required_original_runs' => 4, 'supplied_original_runs' => count($runIds), ...$this->ceiling()];
            $this->need(count($runIds) === 4 && count(array_unique($runIds, SORT_REGULAR)) === 4
                && array_filter($runIds, fn ($id) => ! is_string($id) || $id === '') === [], 'STUDY_FOUR_UNIQUE_ORIGINAL_RUNS_REQUIRED');
            $values = array_values($runIds); sort($values);
            if ($row->assessment !== null) {
                $prior = json_decode($row->assessment, true, 64, JSON_THROW_ON_ERROR);
                $this->assessmentValid($prior, $studyHash);
                $this->need(($prior['original_run_ids'] ?? null) === $values, 'STUDY_ASSESSMENT_ORIGINALS_ARE_IMMUTABLE');
                return $prior;
            }
            $arms = []; $owner = app(LabImmutableEvidenceService::class);
            foreach ($values as $id) {
                $run = $owner->findRun($id);
                $this->need($run !== null, 'STUDY_ORIGINAL_RUN_NOT_FOUND');
                $matches = array_keys(array_filter($design['arms'], fn ($arm) => $arm['model_id'] === (int) $run->model_version_id));
                $this->need(count($matches) === 1 && ! isset($arms[$matches[0]]), 'STUDY_ORIGINAL_ARM_OWNER_MISMATCH');
                if ($run->status === 'started') return ['status' => 'awaiting_originals', 'study_hash' => $studyHash,
                    'reason' => 'STUDY_ORIGINAL_RUN_NOT_TERMINAL', ...$this->ceiling()];
                $this->need($run->status === 'completed' && $run->started_at && $run->finished_at
                    && $run->started_at->greaterThanOrEqualTo(CarbonImmutable::parse($row->created_at)), 'STUDY_COMPLETED_PROSPECTIVE_ORIGINAL_REQUIRED');
                $this->need(LabEvaluationRun::where('model_version_id', $run->model_version_id)->count() === 1,
                    'STUDY_ONE_ORIGINAL_ATTEMPT_PER_ARM_REQUIRED');
                $this->need(($owner->learningEligibility($run)['complete'] ?? false) === true, 'STUDY_COMPLETE_IMMUTABLE_REPLAY_REQUIRED');
                $identity = $owner->verifiedModelRuntimeIdentity($run);
                $arm = $design['arms'][$matches[0]];
                $model = ModelVersion::find($arm['model_id']);
                $policy = app(AgentMethodLearningService::class)->policy($arm['policy_hash']);
                $this->need($model && ($policy['status'] ?? null) !== 'blocked'
                    && $this->hash($this->modelBaseline($model, $policy)) === $arm['baseline_hash'], 'STUDY_REGISTERED_MODEL_BASELINE_DRIFT');
                $this->need(is_array($identity) && ($identity['model_version_id'] ?? null) === $arm['model_id']
                    && ($identity['parameter_hash'] ?? null) === $arm['parameter_hash']
                    && $this->hash($identity['runtime_basis'] ?? []) === $arm['model_runtime_basis_hash'], 'STUDY_ORIGINAL_MODEL_BASELINE_DRIFT');
                [$request, $requestArtifact] = $this->artifact($run, 'evaluation_request', $identity['request_artifact_hash']);
                [$response, $responseArtifact] = $this->artifact($run, 'evaluation_response', $run->response_hash);
                $this->need(data_get($requestArtifact->metadata, 'request_hash') === $run->request_hash, 'STUDY_ORIGINAL_REQUEST_IDENTITY_MISMATCH');
                $settled = app(AgentMethodLearningService::class)->settleOriginalRun($run, $request, $response);
                $this->need(($settled['status'] ?? null) === 'verified_diagnostic' && $settled['policy_hashes'] === [$arm['policy_hash']],
                    'STUDY_ORIGINAL_METHOD_PRODUCT_INVALID');
                foreach ($settled['experiences'] as $experience) {
                    $facts = $experience['facts'];
                    $this->need($facts['data_hash'] === $design['data_hash'] && $facts['execution_hash'] === $design['execution_hash']
                        && $facts['library_hash'] === $arm['library_hash'] && $this->sameScope($facts['scope'], $design['scope']), 'STUDY_ORIGINAL_SCOPE_OR_IDENTITIES_DIFFER');
                }
                $this->parity($run, $request, $response, $design, $studyHash, $matches[0]);
                $trade = $this->tradeFacts($response, $design);
                $arms[$matches[0]] = ['run_id' => $run->run_id, 'model_id' => $arm['model_id'], 'policy_hash' => $arm['policy_hash'],
                    'library_hash' => $arm['library_hash'], 'request_hash' => $run->request_hash, 'response_hash' => $run->response_hash,
                    'request_artifact_hash' => $requestArtifact->sha256, 'response_artifact_hash' => $responseArtifact->sha256,
                    ...$trade, 'behavior' => $settled['observations']];
            }
            $this->need(count($arms) === 4, 'STUDY_EXACT_FOUR_COMPLETED_ARMS_REQUIRED');
            $effects = ['library_given_A1' => $arms['L2A1']['matured_net_profit'] - $arms['L1A1']['matured_net_profit'],
                'library_given_A2' => $arms['L2A2']['matured_net_profit'] - $arms['L1A2']['matured_net_profit'],
                'agent_given_L1' => $arms['L1A2']['matured_net_profit'] - $arms['L1A1']['matured_net_profit'],
                'agent_given_L2' => $arms['L2A2']['matured_net_profit'] - $arms['L2A1']['matured_net_profit']];
            $effects['interaction'] = $effects['library_given_A2'] - $effects['library_given_A1'];
            $powered = min(array_column($arms, 'matured_trades')) >= $design['minimum_trades'];
            $classified = array_map(fn ($effect) => ['value' => $effect, 'classification' => ! $powered ? 'underpowered'
                : (abs($effect) <= $design['classification_epsilon'] ? 'null' : ($effect > 0 ? 'positive_local_diagnostic' : 'negative_local_diagnostic'))], $effects);
            $assessment = ['protocol' => self::PROTOCOL, 'status' => $powered ? 'assessed_local_diagnostic' : 'underpowered_local_diagnostic',
                'study_hash' => $studyHash, 'original_run_ids' => $values, 'arms' => $arms, 'effects' => $classified,
                'minimum_matured_trades_per_arm' => $design['minimum_trades'], 'paired_scope' => $design['scope'],
                'independent_windows' => false, 'compute_equivalence_proven' => false, ...$this->ceiling()];
            $assessment['assessment_hash'] = $this->hash($assessment);
            $assessment = json_decode($this->json($assessment), true, 64, JSON_THROW_ON_ERROR);
            return DB::transaction(function () use ($studyHash, $values, $assessment): array {
                $locked = DB::table('instrument_method_studies')->where('study_hash', $studyHash)->lockForUpdate()->first();
                if ($locked->assessment !== null) {
                    $prior = json_decode($locked->assessment, true, 64, JSON_THROW_ON_ERROR);
                    $this->assessmentValid($prior, $studyHash);
                    $this->need($prior['original_run_ids'] === $values, 'STUDY_ASSESSMENT_ORIGINALS_ARE_IMMUTABLE');
                    return $prior;
                }
                DB::table('instrument_method_studies')->where('study_hash', $studyHash)->update(['assessment' => $this->json($assessment), 'updated_at' => now()]);
                return $assessment;
            });
        } catch (Throwable $error) { return $this->technical($error); }
    }

    /** Resolve a prospective registration for transport without writing model metadata. */
    public function bindingForModel(int $modelId): ?array
    {
        $rows = DB::table('instrument_method_studies')->whereNull('assessment')->where(function ($query) use ($modelId): void {
            foreach (self::ARMS as $arm) $query->orWhere('design->arms->'.$arm.'->model_id', $modelId);
        })->limit(2)->get();
        $this->need($rows->count() <= 1, 'STUDY_MODEL_HAS_AMBIGUOUS_PREREGISTRATIONS');
        if ($rows->isEmpty()) return null;
        $row = $rows->first(); $design = json_decode($row->design, true, 64, JSON_THROW_ON_ERROR);
        $this->need($this->hash($design) === $row->study_hash, 'STUDY_DESIGN_CONTENT_MISMATCH');
        $arms = array_keys(array_filter($design['arms'], fn ($arm) => $arm['model_id'] === $modelId));
        $this->need(count($arms) === 1, 'STUDY_EXACT_MODEL_BINDING_REQUIRED');
        $this->need(! LabEvaluationRun::where('model_version_id', $modelId)->where('started_at', '<', $row->created_at)->exists(),
            'STUDY_MODEL_OBSERVED_BEFORE_PREREGISTRATION');
        return ['study_hash' => $row->study_hash, 'arm' => $arms[0], 'resource_caps' => $design['resource_caps']];
    }

    public function selectorClass(array $policy): array
    {
        return $this->policyClass($policy);
    }

    private function modelBaseline(ModelVersion $model, array $policy): array
    {
        foreach ([AgentMethodGenerationService::METADATA, 'instrument_method_candidate', AgentMethodStudyPreparationService::METADATA] as $field) {
            if (array_key_exists($field, (array) $model->metadata)) {
                return app(AgentMethodStudyPreparationService::class)->baseline($model, $policy);
            }
        }
        $metadata = (array) $model->metadata;
        // A separately verified target attachment is not an experimental
        // treatment. Its intrinsic model reference stays in the baseline.
        if (app(InstrumentMethodMetaBindingService::class)->assetForModel($model) !== null) {
            unset($metadata[InstrumentMethodMetaBindingService::PROOF_METADATA]);
        }
        unset($metadata[InstrumentMethodResearchBindingService::METADATA], $metadata['lifecycle_sync']);
        return ['strategy' => $model->strategy, 'parameters' => (array) $model->parameters, 'metadata' => $metadata];
    }

    private function policyClass(array $policy): array
    {
        $rules = [];
        foreach ($policy['rules'] as $rule) {
            $recipes = [];
            foreach ($rule['preferred_recipes'] as $hash) {
                $recipe = app(InstrumentMethodLibraryService::class)->inspectRecipe($hash);
                $this->need(($recipe['status'] ?? null) === 'registered_research_only', 'STUDY_ORIGINAL_RECIPE_REQUIRED');
                $recipes[] = $recipe['contract']['recipe_key'];
            }
            $context = [];
            foreach ($rule['context_predicate'] as $axis => $values) {
                $axis = ['market_regime' => 'regime', 'volatility_regime' => 'volatility', 'market_session' => 'session',
                    'market_venue_phase' => 'venue_phase', 'spread' => 'spread_liquidity_state',
                    'spread_context' => 'spread_liquidity_state'][$axis] ?? $axis;
                $values = is_array($values) ? $values : [$values]; $normalized = [];
                foreach ($values as $value) {
                    if (in_array($axis, ['symbol', 'timeframe'], true)) $normalized[] = strtoupper(trim($value));
                    else {
                        $axes = app(ContextContractV2Service::class)->canonicalDeclaredAxes([$axis => $value]);
                        $normalized[] = $axes[$axis] ?? strtolower(trim($value));
                    }
                }
                $normalized = array_values(array_unique($normalized)); sort($normalized);
                $context[$axis] = isset($context[$axis]) ? array_values(array_intersect($context[$axis], $normalized)) : $normalized;
            }
            ksort($context); $rules[] = ['context_predicate' => $context, 'preferred_recipe_keys' => $recipes];
        }
        return ['rules' => $rules, 'default_action' => $policy['default_action']];
    }

    private function parity(LabEvaluationRun $run, array $request, array $response, array $design, string $studyHash, string $armKey): void
    {
        $binding = data_get($request, 'policy_context.instrument_method_study');
        if (isset($request['strategies'])) {
            $selected = array_values(array_filter($request['strategies'], fn ($strategy) => is_array($strategy)
                && ($strategy['lab_agent_id'] ?? null) === $run->lab_agent_id));
            $this->need(count($selected) === 1, 'STUDY_ORIGINAL_STRATEGY_OWNER_REQUIRED');
            $binding = $selected[0]['instrument_method_study'] ?? null;
        }
        $this->need(is_array($binding) && ($binding['study_hash'] ?? null) === $studyHash && ($binding['arm'] ?? null) === $armKey,
            'STUDY_ORIGINAL_PROSPECTIVE_BINDING_REQUIRED');
        $this->need($run->data_hash === $design['data_hash'] && ($request['replay_dataset_hash'] ?? null) === $design['data_hash'], 'STUDY_ACTUAL_DATA_HASH_MISMATCH');
        $execution = $request['execution_contract'] ?? [];
        $this->need(($execution['execution_hash'] ?? null) === $design['execution_hash']
            && $this->hash($execution['parameters'] ?? []) === $this->hash($design['execution_costs']), 'STUDY_EXECUTION_COSTS_DIFFER');
        $this->need($this->number($request['initial_balance'] ?? null) && $request['initial_balance'] == $design['initial_balance']
            && $this->number($request['risk_per_trade'] ?? null) && $request['risk_per_trade'] == $design['risk_per_trade'], 'STUDY_ACCOUNT_OR_RISK_DIFFER');
        $limits = $request['hard_limits'] ?? data_get($request, 'policy_context.hard_limits',
            data_get($request, 'specialist_council_contract.account_policy'));
        $this->need(is_array($limits) && $this->hash($limits) === $this->hash($design['hard_limits']), 'STUDY_HARD_LIMITS_DIFFER');
        $caps = $binding['resource_caps'] ?? null;
        $this->need(is_array($caps) && $this->hash($caps) === $this->hash($design['resource_caps']), 'STUDY_REQUEST_RESOURCE_CAPS_DIFFER');
        $native = $this->nativeReceipt($response);
        $this->need(empty($request['specialist_council_contract']) || $native !== null, 'STUDY_NATIVE_ORIGINAL_ACCOUNT_RECEIPT_REQUIRED');
        $rows = is_array($native) ? data_get($native, 'decision_trace_identity.decision_rows')
            : data_get($response, 'data_quality.decision_trace.evaluated_candle_count');
        $this->need(is_int($rows) && $rows === $design['evaluated_rows'] && $rows <= $caps['max_rows'], 'STUDY_OBSERVED_ROWS_DIFFER');
        $this->need(is_array($response['decision_trace'] ?? null) && count($response['decision_trace']) <= $caps['max_decision_events']
            && is_numeric($run->duration_ms) && $run->duration_ms >= 0 && $run->duration_ms <= $caps['max_runtime_seconds'] * 1000, 'STUDY_OBSERVED_RESOURCE_CAP_EXCEEDED');
    }

    private function tradeFacts(array $response, array $design): array
    {
        $native = $this->nativeReceipt($response);
        $matured = 0; $censored = 0; $maturedNet = 0.0; $net = 0.0; $costs = 0.0;
        if (is_array($native)) {
            $body = array_diff_key($native, array_flip(['receipt_hash', 'receipt_json']));
            $this->need(($native['receipt_hash'] ?? null) === $this->hash($body), 'STUDY_NATIVE_ACCOUNT_RECEIPT_HASH_INVALID');
            $ledger = $native['position_ledger'] ?? null; $account = $native['account'] ?? [];
            $this->need(is_array($ledger) && array_is_list($ledger) && ($account['initial_balance'] ?? null) == $design['initial_balance'], 'STUDY_NATIVE_ACCOUNT_LEDGER_REQUIRED');
            foreach ($ledger as $trade) {
                $this->tradeTime($trade, $design['scope']);
                $this->need($this->number($trade['net_pnl'] ?? null) && is_bool($trade['outcome_matured'] ?? null), 'STUDY_NATIVE_MATURED_PNL_REQUIRED');
                $value = (float) $trade['net_pnl']; $net += $value;
                if ($trade['outcome_matured']) { $matured++; $maturedNet += $value; } else $censored++;
            }
            $this->need($this->number($account['net_pnl'] ?? null) && $this->number($account['final_balance'] ?? null)
                && abs($net - $account['net_pnl']) <= 0.000001
                && abs($account['final_balance'] - $design['initial_balance'] - $net) <= 0.000001, 'STUDY_NATIVE_ACCOUNT_RECONCILIATION_FAILED');
            foreach (['fees', 'carry', 'spread_slippage'] as $key) { $this->need($this->number($account[$key] ?? null), 'STUDY_NATIVE_ACTUAL_COSTS_REQUIRED'); $costs += $account[$key]; }
        } else {
            $ledger = $response['trade_ledger'] ?? null;
            $this->need(is_array($ledger) && array_is_list($ledger) && ($response['initial_balance'] ?? null) == $design['initial_balance']
                && ($response['total_trades'] ?? null) === count($ledger), 'STUDY_ORDINARY_FULL_TRADE_LEDGER_REQUIRED');
            $balance = (float) $design['initial_balance'];
            foreach ($ledger as $trade) {
                $this->tradeTime($trade, $design['scope']);
                $this->need($this->number($trade['balance'] ?? null) && is_string($trade['exit_reason'] ?? null)
                    && $this->number($trade['execution_cost_percent'] ?? null), 'STUDY_ORDINARY_MATURED_ACCOUNT_PROOF_REQUIRED');
                $value = $trade['balance'] - $balance; $balance = (float) $trade['balance']; $net += $value;
                $costs += $trade['execution_cost_percent'];
                if (str_contains(strtolower($trade['exit_reason']), 'end_of_data')) $censored++;
                else { $matured++; $maturedNet += $value; }
            }
            $this->need($this->number($response['final_balance'] ?? null) && abs($balance - $response['final_balance']) <= 0.011, 'STUDY_ORDINARY_ACCOUNT_RECONCILIATION_FAILED');
        }
        return ['matured_trades' => $matured, 'censored_trades' => $censored, 'matured_net_profit' => $maturedNet,
            'total_net_profit' => $net, 'observed_costs' => $costs, 'cost_unit' => is_array($native) ? 'cash' : 'sum_execution_cost_percent'];
    }

    /** SimpleBacktestResponse emits an empty native dict even for ordinary execution. */
    private function nativeReceipt(array $response): ?array
    {
        $original = null;
        foreach ([$response['specialist_council_receipt'] ?? null, data_get($response, 'data_quality.specialist_council_receipt')] as $candidate) {
            if ($candidate === null || $candidate === []) continue;
            $this->need(is_array($candidate) && ! array_is_list($candidate), 'STUDY_NATIVE_ORIGINAL_ACCOUNT_RECEIPT_INVALID');
            $this->need($original === null || $this->hash($original) === $this->hash($candidate), 'STUDY_NATIVE_ACCOUNT_RECEIPTS_DIFFER');
            $original = $candidate;
        }
        return $original;
    }

    private function tradeTime(array $trade, array $scope): void
    {
        $this->need(is_string($trade['entry_time'] ?? null) && is_string($trade['exit_time'] ?? null), 'STUDY_TRADE_CLOCK_REQUIRED');
        $entry = CarbonImmutable::parse($trade['entry_time']); $exit = CarbonImmutable::parse($trade['exit_time']);
        $this->need($entry <= $exit && $entry >= CarbonImmutable::parse($scope['evaluated_start'])
            && $exit <= CarbonImmutable::parse($scope['evaluated_end']) && $exit < app(ResearchPaperEpochContractService::class)->cutoff(), 'STUDY_TRADE_CLOCK_OUTSIDE_SCOPE');
    }

    private function artifact(LabEvaluationRun $run, string $type, string $hash): array
    {
        $artifact = LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', $type)->where('sha256', $hash)->oldest('id')->first();
        $this->need($artifact !== null, 'STUDY_ORIGINAL_ARTIFACT_REQUIRED');
        $owner = app(LabImmutableEvidenceService::class); $body = $owner->readArtifactPayload($artifact);
        $this->need(is_array($body) && ($artifact->storage_path || $owner->hash($body) === $hash), 'STUDY_ORIGINAL_ARTIFACT_HASH_MISMATCH');
        return [$body, $artifact];
    }
    private function scope(array $scope): void
    {
        $this->need(is_string($scope['symbol'] ?? null) && is_string($scope['timeframe'] ?? null)
            && is_string($scope['evaluated_start'] ?? null) && is_string($scope['evaluated_end'] ?? null) && is_array($scope['context'] ?? null), 'STUDY_EXACT_SCOPE_REQUIRED');
        $start = CarbonImmutable::parse($scope['evaluated_start']); $end = CarbonImmutable::parse($scope['evaluated_end']);
        $this->need($start <= $end && $end < app(ResearchPaperEpochContractService::class)->cutoff(), 'STUDY_PRE2026_SCOPE_REQUIRED');
    }
    private function sameScope(array $left, array $right): bool
    {
        return ($left['symbol'] ?? null) === $right['symbol'] && ($left['timeframe'] ?? null) === $right['timeframe']
            && CarbonImmutable::parse($left['evaluated_start'])->equalTo(CarbonImmutable::parse($right['evaluated_start']))
            && CarbonImmutable::parse($left['evaluated_end'])->equalTo(CarbonImmutable::parse($right['evaluated_end']))
            && $this->hash($left['context'] ?? []) === $this->hash($right['context']);
    }
    private function ceiling(): array { return ['research_only' => true, 'out_of_sample' => false, 'confirmation_proven' => false,
        'mastery_proven' => false, 'skill_credit' => false, 'mentor_authority' => false, 'paper_authority' => false,
        'economic_authority' => false, 'promotion_evidence' => false]; }
    private function assessmentValid(array $assessment, string $studyHash): void
    {
        $this->need(($assessment['study_hash'] ?? null) === $studyHash && ($assessment['protocol'] ?? null) === self::PROTOCOL
            && ($assessment['assessment_hash'] ?? null) === $this->hash(array_diff_key($assessment, ['assessment_hash' => true])),
            'STUDY_ASSESSMENT_CONTENT_MISMATCH');
        foreach ($this->ceiling() as $key => $value) $this->need(($assessment[$key] ?? null) === $value, 'STUDY_ASSESSMENT_AUTHORITY_FORBIDDEN');
    }
    private function technical(Throwable $error): array
    {
        $reason = preg_match('/^STUDY_[A-Z0-9_]+$/D', $error->getMessage()) === 1 ? $error->getMessage() : 'STUDY_ORIGINAL_VERIFICATION_FAILED';
        return ['status' => 'technical_unassessable', 'reason' => $reason, ...$this->ceiling()];
    }
    private function need(bool $condition, string $reason): void { if (! $condition) throw new \LogicException($reason); }
    private function sha(mixed $value): bool { return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1; }
    private function number(mixed $value): bool { return (is_int($value) || is_float($value)) && is_finite((float) $value); }
    private function hash(array $body): string { return app(ResearchPaperEpochContractService::class)->parameterHash($body); }
    private function json(array $body): string
    {
        $sort = function ($items) use (&$sort) { if (! is_array($items)) return $items;
            if (! array_is_list($items)) ksort($items); return array_map($sort, $items); };
        return json_encode($sort($body), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
