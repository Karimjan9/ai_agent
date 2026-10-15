<?php

namespace App\Services;

use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/**
 * An explicitly requested finite classifier, not the old empirical-PF gate.
 * Past-seen original data may train this model. Its calibration is chronological
 * diagnostic calibration, never an untouched evaluation or a trading authority.
 */
class InstrumentMethodMetaModelService
{
    use InstrumentMethodNativeMetaModelTrait;
    public const PROTOCOL = 'instrument_method_meta_model_v1';
    public const REGISTRATION_PROTOCOL = 'instrument_method_meta_training_v1';
    public const BINDING_FIELDS = ['symbol', 'timeframe', 'strategy', 'parameters_hash', 'account_risk_hash', 'effective_policy_hash',
        'data_hash', 'execution_hash', 'source_hash', 'python_source_hash', 'clock_seconds'];
    public const CONTEXT_FIELDS = ['regime', 'volatility', 'session', 'direction'];
    public const PARAMETER_PROJECTION_PROTOCOL = 'instrument_method_schema_numeric_projection_v1';

    /** Exact simple-API spelling only: no integer/flag coercion, rounding or topology repair. */
    public function projectPhysicalParameters(string $strategy, array $parameters, ?string $baseStrategy = null): array
    {
        $schemas = app(StrategyParameterSchemaService::class);
        $family = $this->physicalParameterFamily($strategy, $baseStrategy);
        $schema = $schemas->schema($family);
        $this->need($schema !== [] && count($parameters) <= 256 && array_diff(array_keys($parameters), array_keys($schema)) === [],
            'META_MODEL_PARAMETER_PROJECTION_SCHEMA_REQUIRED');
        $projected = [];
        foreach ($parameters as $field => $value) {
            [$type, $lower, $upper] = array_pad($schema[$field], 3, null);
            $valid = match ($type) {
                'numeric' => $this->number($value), 'integer' => is_int($value),
                'boolean' => is_bool($value), 'string' => is_string($value) && in_array($value, (array) $lower, true),
                default => false,
            };
            $this->need($valid && (! in_array($type, ['numeric', 'integer'], true)
                || ($lower === null || ($value >= $lower && $value <= $upper))), 'META_MODEL_PARAMETER_PROJECTION_TYPE_REQUIRED');
            $projected[$field] = $type === 'numeric' ? (float) $value : $value;
        }
        return $projected;
    }

    public function physicalParameterFamily(string $strategy, ?string $baseStrategy = null): string
    {
        $schemas = app(StrategyParameterSchemaService::class);
        $this->need($baseStrategy === null || ($baseStrategy !== '' && strlen($baseStrategy) <= 128
            && $schemas->schema($baseStrategy) !== []), 'META_MODEL_PARAMETER_PROJECTION_SCHEMA_REQUIRED');
        $family = $schemas->family($strategy);
        if (! in_array($family, ['differential_router', 'regime_ensemble'], true) && $baseStrategy !== null) $family = $schemas->family($baseStrategy);
        $this->need($schemas->schema($family) !== [], 'META_MODEL_PARAMETER_PROJECTION_SCHEMA_REQUIRED');
        return $family;
    }

    /** Shared compiler identity; no method/library intervention is included. */
    public function physicalAccountRiskHash(array $request): string
    {
        // Pydantic turns these two typed request scalars into floats. Preserve
        // their numeric meaning across that wire boundary, without normalizing
        // arbitrary flags, hard limits or programme/risk port declarations.
        $account = [];
        foreach (['initial_balance', 'risk_per_trade'] as $field) {
            $this->need($this->number($request[$field] ?? null), 'META_MODEL_PHYSICAL_ACCOUNT_RISK_REQUIRED');
            $account[$field] = (float) $request[$field];
        }
        $ports = array_intersect_key($request, array_flip(['risk_governor', 'risk_policy', 'capital_policy', 'capital_allocation']));
        $policyPorts = array_intersect_key((array) ($request['policy_context'] ?? []),
            array_flip(['risk_policy', 'risk_governor', 'capital_policy', 'capital_allocation', 'ood_assessment']));
        return $this->hash(['initial_balance' => $account['initial_balance'],
            'risk_per_trade' => $account['risk_per_trade'],
            'hard_limits' => $request['hard_limits'] ?? data_get($request, 'policy_context.hard_limits'),
            'risk_capital_ports' => $ports, 'policy_risk_capital_ports' => $policyPorts]);
    }

    public function preregister(array $input): array
    {
        return $this->registerTraining($input);
    }

    private function registerTraining(array $input, ?string $nativeMember = null): array
    {
        try {
            $allowed = ['model_key', 'run_ids', 'training_range', 'calibration_range', 'confidence_edges',
                'probability_edges', 'minimum_training', 'minimum_calibration', 'minimum_cell_training',
                'minimum_cell_calibration', 'max_events', 'max_trades', 'smoothing'];
            $this->need(array_diff(array_keys($input), $allowed) === [], 'META_MODEL_UNKNOWN_DESIGN_FIELD');
            $this->need(is_string($input['model_key'] ?? null)
                && preg_match('/^[A-Za-z0-9_.:\/-]{1,150}$/D', $input['model_key']) === 1, 'META_MODEL_KEY_REQUIRED');
            $this->need(! str_starts_with(strtolower($input['model_key']), 'native-target:'), 'META_MODEL_RESERVED_TARGET_NAMESPACE');
            $ids = $input['run_ids'] ?? null;
            $this->need(is_array($ids) && array_is_list($ids) && count($ids) >= 1 && count($ids) <= 16
                && count(array_unique($ids)) === count($ids), 'META_MODEL_ORIGINAL_RUN_ROSTER_REQUIRED');
            foreach ($ids as $id) $this->need(is_string($id) && preg_match('/^[a-zA-Z0-9-]{1,64}$/D', $id) === 1, 'META_MODEL_RUN_ID_INVALID');
            sort($ids);
            $training = $this->range($input['training_range'] ?? []);
            $calibration = $this->range($input['calibration_range'] ?? []);
            $this->need($this->utc($training['end']) < $this->utc($calibration['start']), 'META_MODEL_CHRONOLOGICAL_SPLIT_REQUIRED');
            $settings = [
                'confidence_edges' => $this->edges($input['confidence_edges'] ?? [0.0, 0.5, 0.75, 1.0]),
                'probability_edges' => $this->edges($input['probability_edges'] ?? [0.0, 0.25, 0.5, 0.75, 1.0]),
                'minimum_training' => $input['minimum_training'] ?? 20,
                'minimum_calibration' => $input['minimum_calibration'] ?? 10,
                'minimum_cell_training' => $input['minimum_cell_training'] ?? 5,
                'minimum_cell_calibration' => $input['minimum_cell_calibration'] ?? 3,
                'max_events' => $input['max_events'] ?? 100000,
                'max_trades' => $input['max_trades'] ?? 50000,
                'smoothing' => $input['smoothing'] ?? 1.0,
            ];
            foreach (['minimum_training' => [20, 50000], 'minimum_calibration' => [10, 50000],
                'minimum_cell_training' => [2, 50000], 'minimum_cell_calibration' => [2, 50000],
                'max_events' => [1, 200000], 'max_trades' => [1, 50000]] as $key => [$lower, $upper]) {
                $this->need(is_int($settings[$key]) && $settings[$key] >= $lower && $settings[$key] <= $upper, 'META_MODEL_BOUNDED_SAMPLE_REQUIREMENTS_REQUIRED');
            }
            $this->need($this->number($settings['smoothing']) && $settings['smoothing'] >= 0.5
                && $settings['smoothing'] <= 10.0, 'META_MODEL_SMOOTHING_INVALID');
            $settings['smoothing'] = (float) $settings['smoothing'];
            $roster = []; $binding = null;
            // Registration fixes original identities, not a result ranking.
            foreach ($ids as $id) {
                $proof = $nativeMember === null ? $this->original($id, false) : $this->nativeOriginal($id, $nativeMember, false);
                $binding ??= $proof['binding'];
                $this->need($this->hash($binding) === $this->hash($proof['binding']), 'META_MODEL_EFFECTIVE_BASELINE_MISMATCH');
                $roster[] = $proof['identity'];
            }
            $design = ['protocol' => $nativeMember === null ? self::REGISTRATION_PROTOCOL : self::NATIVE_REGISTRATION_PROTOCOL, 'model_key' => $input['model_key'],
                'roster' => $roster, 'binding' => $binding, 'training_range' => $training,
                'calibration_range' => $calibration, 'context_fields' => self::CONTEXT_FIELDS, ...$settings,
                'prior_exposure' => 'seen_training_only', 'calibration_use' => 'chronological_seen_diagnostic',
                'label' => 'matured_net_cash_positive', 'deduplication' => 'physical_entry_with_exact_outcome_v1',
                'stopping_rule' => 'one_fixed_roster_fit_no_outcome_selection',
                'maximum_cells' => 1024, 'maximum_model_bytes' => 262144, ...$this->ceiling()];
            if ($nativeMember !== null) $design['native_member'] = $nativeMember;
            $hash = $this->hash($design);
            return DB::transaction(function () use ($hash, $design): array {
                $old = DB::table('instrument_method_meta_registrations')->where('model_key', $design['model_key'])->lockForUpdate()->first();
                $this->need(! $old || $old->registration_hash === $hash, 'META_MODEL_REGISTRATION_IMMUTABLE');
                if (! $old) DB::table('instrument_method_meta_registrations')->insert([
                    'registration_hash' => $hash, 'model_key' => $design['model_key'], 'design' => $this->json($design),
                    'assessment' => null, 'created_at' => now(), 'updated_at' => now()]);
                return ['status' => 'preregistered_training', 'registration_hash' => $hash, 'design' => $design, ...$this->ceiling()];
            });
        } catch (Throwable $error) { return $this->blocked($error); }
    }

    public function fit(string $registrationHash): array
    {
        try {
            return DB::transaction(function () use ($registrationHash): array {
                $row = DB::table('instrument_method_meta_registrations')->where('registration_hash', $registrationHash)->lockForUpdate()->first();
                $this->need($row !== null, 'META_MODEL_REGISTRATION_NOT_FOUND');
                $design = $this->design($row);
                if ($row->assessment !== null) return $this->inspect($registrationHash);
                $facts = $this->records($design);
                [$assessment, $artifact] = $this->train($design, $facts, $registrationHash);
                $assessment['assessment_hash'] = $this->hash($assessment);
                $protocol = isset($design['native_member']) ? self::NATIVE_PROTOCOL : self::PROTOCOL;
                $assessment['issuer_signature'] = $this->signature($assessment['assessment_hash'], $protocol);
                DB::table('instrument_method_meta_registrations')->where('id', $row->id)->update(['assessment' => $this->json($assessment)]);
                if ($artifact !== null) {
                    $json = $this->json($artifact); $modelHash = hash('sha256', $json);
                    DB::table('instrument_method_meta_models')->insert(['model_hash' => $modelHash,
                        'registration_hash' => $registrationHash, 'artifact_json' => $json,
                        'issuer_signature' => $this->signature($modelHash, $protocol), 'created_at' => now(), 'updated_at' => now()]);
                }
                return ['status' => $assessment['status'], 'registration_hash' => $registrationHash,
                    'assessment' => $assessment, 'model_hash' => $artifact !== null ? $modelHash : null, ...$this->ceiling()];
            });
        } catch (Throwable $error) { return $this->blocked($error); }
    }

    public function inspect(string $registrationHash): array
    {
        try {
            $row = DB::table('instrument_method_meta_registrations')->where('registration_hash', $registrationHash)->first();
            $this->need($row !== null, 'META_MODEL_REGISTRATION_NOT_FOUND');
            $design = $this->design($row);
            if ($row->assessment === null) return ['status' => 'preregistered_training', 'registration_hash' => $registrationHash, ...$this->ceiling()];
            $assessment = json_decode($row->assessment, true, 64, JSON_THROW_ON_ERROR);
            $body = array_diff_key($assessment, array_flip(['assessment_hash', 'issuer_signature']));
            $this->need(($assessment['assessment_hash'] ?? null) === $this->hash($body)
                && hash_equals($this->signature($this->hash($body), isset($design['native_member']) ? self::NATIVE_PROTOCOL : self::PROTOCOL), (string) ($assessment['issuer_signature'] ?? '')), 'META_MODEL_ASSESSMENT_SEAL_INVALID');
            $facts = $this->records($design);
            $this->need(($body['records_hash'] ?? null) === $facts['records_hash'], 'META_MODEL_ORIGINAL_TRAINING_RECORDS_CHANGED');
            $model = DB::table('instrument_method_meta_models')->where('registration_hash', $registrationHash)->first();
            if ($model) $this->verifiedArtifact($model);
            $this->need(($body['status'] === 'fitted_diagnostic') === ($model !== null), 'META_MODEL_ASSESSMENT_ARTIFACT_MISMATCH');
            return ['status' => $body['status'], 'registration_hash' => $registrationHash,
                'assessment' => $assessment, 'model_hash' => $model?->model_hash, ...$this->ceiling()];
        } catch (Throwable $error) { return $this->blocked($error); }
    }

    /** Only this server owner exports a finite artifact to a future compiled request. */
    public function exportModel(string $modelHash, array $expectedBinding): array
    {
        try {
            $row = DB::table('instrument_method_meta_models')->where('model_hash', $modelHash)->first();
            $this->need($row !== null, 'META_MODEL_FITTED_ARTIFACT_NOT_FOUND');
            $inspection = $this->inspect($row->registration_hash);
            $this->need(($inspection['status'] ?? null) === 'fitted_diagnostic', 'META_MODEL_ORIGINAL_MODEL_PROOF_REQUIRED');
            $body = $this->verifiedArtifact($row);
            $fields = $body['protocol'] === self::NATIVE_PROTOCOL ? self::NATIVE_BINDING_FIELDS : self::BINDING_FIELDS;
            $this->need(array_keys($expectedBinding) !== [] && array_diff(array_keys($expectedBinding), $fields) === []
                && array_diff($fields, array_keys($expectedBinding)) === [], 'META_MODEL_EXACT_RUNTIME_BINDING_REQUIRED');
            $this->need($this->hash($body['binding']) === $this->hash($expectedBinding), 'META_MODEL_RUNTIME_BINDING_MISMATCH');
            return ['status' => 'exported_diagnostic', 'artifact' => [...$body, 'artifact_json' => $row->artifact_json,
                'artifact_hash' => $row->model_hash, 'issuer_signature' => $row->issuer_signature],
                'expected_binding' => [...$body['binding'], 'artifact_hash' => $row->model_hash,
                    'issuer_signature' => $row->issuer_signature], ...$this->ceiling()];
        } catch (Throwable $error) { return $this->blocked($error); }
    }

    /** Reopen the issuer's original training binding; clients cannot guess or replace it. */
    public function exportOriginalModel(string $modelHash, bool $withParameterProjection = false): array
    {
        try {
            $row = DB::table('instrument_method_meta_models')->where('model_hash', $modelHash)->first();
            $this->need($row !== null, 'META_MODEL_FITTED_ARTIFACT_NOT_FOUND');
            $body = $this->verifiedArtifact($row);
            $export = $this->exportModel($modelHash, $body['binding']);
            $this->need(! $withParameterProjection || $body['protocol'] === self::PROTOCOL, 'META_MODEL_ORDINARY_PARAMETER_PROJECTION_REQUIRED');
            if (! $withParameterProjection || ($export['status'] ?? null) !== 'exported_diagnostic') return $export;
            $registration = DB::table('instrument_method_meta_registrations')->where('registration_hash', $row->registration_hash)->first();
            $design = $this->design($registration);
            $original = $this->original($design['roster'][0]['run_id'], false);
            $request = $original['request']; $parameters = (array) ($request['parameters'] ?? []);
            $this->need($this->hash($parameters) === $body['binding']['parameters_hash']
                && ($request['strategy'] ?? null) === $body['binding']['strategy'], 'META_MODEL_ORIGINAL_PARAMETER_PROJECTION_REQUIRED');
            $base = $request['base_strategy'] ?? null;
            $this->need($base === null || (is_string($base) && strlen($base) <= 128), 'META_MODEL_PARAMETER_PROJECTION_SCHEMA_REQUIRED');
            $projected = $this->projectPhysicalParameters($request['strategy'], $parameters, $base);
            return [...$export, 'parameter_projection' => ['protocol' => self::PARAMETER_PROJECTION_PROTOCOL,
                'strategy' => $request['strategy'], 'base_strategy' => $base, 'original_parameters' => $parameters,
                'projected_parameters_hash' => $this->hash($projected)]];
        } catch (Throwable $error) { return $this->blocked($error); }
    }

    private function original(string $id, bool $products): array
    {
        $owner = app(LabImmutableEvidenceService::class); $run = $owner->findRun($id);
        $this->need($run && $run->status === 'completed' && $run->phase !== 'paper'
            && ($owner->learningEligibility($run)['complete'] ?? false) === true, 'META_MODEL_COMPLETE_ORIGINAL_REQUIRED');
        $runtime = $owner->verifiedModelRuntimeIdentity($run);
        $this->need(is_array($runtime), 'META_MODEL_ORIGINAL_RUNTIME_IDENTITY_REQUIRED');
        [$request, $requestArtifact] = $this->artifact($id, 'evaluation_request', $runtime['request_artifact_hash'] ?? '');
        $this->need(data_get($requestArtifact->metadata, 'request_hash') === $run->request_hash, 'META_MODEL_ORIGINAL_REQUEST_IDENTITY_MISMATCH');
        $this->need(empty($request['specialist_council_contract']) && empty($request['portfolio_members']), 'META_MODEL_ORDINARY_SOURCE_REQUIRED');
        foreach (['strategies', 'composition_runtime_contract', 'instrument_research_assignment', 'runtime_ensemble_policy',
            'specialist_context_contract', 'mtf_pilot', 'native_spread_context_study_contract', 'native_reachability_depth_audit_contract',
            'native_standalone_qualification'] as $port) $this->need(empty($request[$port]), 'META_MODEL_PLAIN_ORIGINAL_PROGRAMME_REQUIRED');
        $this->need(! app(ResearchPaperEpochContractService::class)->researchInputHasPaperData($request), 'META_MODEL_PRE2026_SOURCE_REQUIRED');
        $clockArtifact = LabEvidenceArtifact::where('run_id', $id)->where('artifact_type', 'evaluation_response')
            ->where('sha256', $run->response_hash)->oldest('id')->first();
        $traceArtifact = LabEvidenceArtifact::where('run_id', $id)->where('artifact_type', 'decision_trace')->oldest('id')->first();
        $ledgerArtifact = LabEvidenceArtifact::where('run_id', $id)->where('artifact_type', 'trade_ledger')->oldest('id')->first();
        $this->need($clockArtifact && $traceArtifact && $ledgerArtifact, 'META_MODEL_FULL_ORIGINAL_ARTIFACTS_REQUIRED');
        [$response] = $this->artifact($id, 'evaluation_response', (string) $run->response_hash);
        $execution = $request['execution_contract'] ?? null;
        $this->need(is_array($execution) && ($execution['protocol'] ?? null) === ExecutionContractService::PROTOCOL
            && ($execution['version'] ?? null) === ExecutionContractService::VERSION && is_array($execution['parameters'] ?? null)
            && app(ExecutionContractService::class)->hashParameters($execution['parameters']) === ($execution['execution_hash'] ?? null)
            && ($execution['symbol'] ?? null) === ($request['symbol'] ?? null)
            && ($execution['timeframe'] ?? null) === ($request['timeframe'] ?? null), 'META_MODEL_ORIGINAL_EXECUTION_PARAMETERS_REQUIRED');
        $actualExecution = $response['execution_contract'] ?? null;
        $this->need(is_array($actualExecution) && ($actualExecution['execution_hash'] ?? null) === $execution['execution_hash']
            && app(ExecutionContractService::class)->hashParameters((array) ($actualExecution['parameters'] ?? [])) === $execution['execution_hash'],
            'META_MODEL_ACTUAL_EXECUTION_PARAMETERS_MISMATCH');
        if (isset($request['execution'])) $this->need(app(ExecutionContractService::class)->hashParameters((array) $request['execution']) === $execution['execution_hash'],
            'META_MODEL_REQUEST_EXECUTION_PARAMETERS_MISMATCH');
        $clock = data_get($response, 'data_quality.replay_executed_clock');
        $this->need(is_array($clock) && ($clock['protocol'] ?? null) === 'replay_executed_clock_v1'
            && ($clock['complete'] ?? null) === true && ($clock['semantics'] ?? null) === 'previous_closed_candle_next_open_v1'
            && ($clock['receipt_hash'] ?? null) === $this->hash(array_diff_key($clock, array_flip(['receipt_hash', 'receipt_json']))), 'META_MODEL_ACTUAL_CLOCK_REQUIRED');
        $this->need(($clock['owner'] ?? null) === 'ordinary_single_position_v1'
            && ($clock['index_basis'] ?? null) === 'evaluated_frame_zero_based_v1'
            && ($clock['execution_timeframe'] ?? null) === ($request['timeframe'] ?? null)
            && ($clock['promotion_evidence'] ?? null) === false && is_string($clock['receipt_json'] ?? null)
            && strlen($clock['receipt_json']) <= 8192 && hash('sha256', $clock['receipt_json']) === $clock['receipt_hash'],
            'META_MODEL_ORIGINAL_CLOCK_SHAPE_INVALID');
        foreach (['input_rows', 'evaluation_offset_rows', 'decision_rows', 'first_evaluation_index', 'last_evaluation_index'] as $field)
            $this->need(is_int($clock[$field] ?? null) && $clock[$field] >= 0 && $clock[$field] <= 200000, 'META_MODEL_ORIGINAL_CLOCK_ROWS_INVALID');
        $this->need($clock['first_evaluation_index'] >= 1 && $clock['last_evaluation_index'] >= $clock['first_evaluation_index']
            && $clock['last_evaluation_index'] === $clock['input_rows'] - $clock['evaluation_offset_rows'] - 1
            && $clock['decision_rows'] === $clock['last_evaluation_index'] - $clock['first_evaluation_index'] + 1
            && $this->sha($clock['index_set_hash'] ?? null) && $this->sha($clock['schedule_hash'] ?? null), 'META_MODEL_ORIGINAL_CLOCK_COVERAGE_INVALID');
        $indices = hash_init('sha256'); hash_update($indices, "replay-executed-clock-v1:indices\n");
        for ($index = $clock['first_evaluation_index']; $index <= $clock['last_evaluation_index']; $index++) hash_update($indices, $index."\n");
        $this->need(hash_final($indices) === $clock['index_set_hash'], 'META_MODEL_ORIGINAL_CLOCK_INDEX_HASH_INVALID');
        $this->need(is_int($clock['duration_seconds'] ?? null) && $clock['duration_seconds'] >= 1 && $clock['duration_seconds'] <= 86400
            && ($clock['dataset_hash'] ?? null) === $run->data_hash && ($request['replay_dataset_hash'] ?? null) === $run->data_hash
            && ($clock['execution_hash'] ?? null) === data_get($request, 'execution_contract.execution_hash'), 'META_MODEL_CLOCK_DATA_EXECUTION_MISMATCH');
        $seconds = ['M1' => 60, 'M5' => 300, 'M15' => 900, 'H1' => 3600, 'H4' => 14400, 'D1' => 86400][$request['timeframe'] ?? ''] ?? null;
        $this->need($seconds !== null && $clock['duration_seconds'] === $seconds, 'META_MODEL_CLOCK_TIMEFRAME_MISMATCH');
        $this->need($this->number($request['initial_balance'] ?? null) && $request['initial_balance'] > 0
            && $this->number($request['risk_per_trade'] ?? null) && $request['risk_per_trade'] > 0,
            'META_MODEL_PHYSICAL_ACCOUNT_RISK_REQUIRED');
        $this->need($this->utc($clock['execution_start'] ?? null) <= $this->utc($clock['execution_end'] ?? null)
            && $this->utc($clock['execution_end']) < $this->cutoff(), 'META_MODEL_PRE2026_CLOCK_REQUIRED');
        $this->need($this->utc($clock['signal_start'] ?? null)->addSeconds($clock['duration_seconds']) <= $this->utc($clock['execution_start'])
            && $this->utc($clock['signal_end'] ?? null)->addSeconds($clock['duration_seconds']) <= $this->utc($clock['execution_end']),
            'META_MODEL_ORIGINAL_CLOSED_SIGNAL_CLOCK_REQUIRED');
        $binding = ['symbol' => $request['symbol'] ?? null, 'timeframe' => $request['timeframe'] ?? null,
            'strategy' => $request['strategy'] ?? null, 'parameters_hash' => $this->hash((array) ($request['parameters'] ?? [])),
            'account_risk_hash' => $this->physicalAccountRiskHash($request),
            'effective_policy_hash' => $this->effectivePolicy($request), 'data_hash' => $run->data_hash,
            'execution_hash' => $clock['execution_hash'], 'source_hash' => $run->code_hash,
            'python_source_hash' => data_get($request, 'research_release.python_source_hash'),
            'clock_seconds' => $clock['duration_seconds']];
        foreach (['parameters_hash', 'account_risk_hash', 'effective_policy_hash', 'data_hash', 'execution_hash', 'source_hash', 'python_source_hash'] as $key)
            $this->need($this->sha($binding[$key]), 'META_MODEL_EXACT_SOURCE_HASH_REQUIRED');
        foreach (['symbol', 'timeframe', 'strategy'] as $key) $this->need(is_string($binding[$key]) && $binding[$key] !== '', 'META_MODEL_SOURCE_STRATEGY_REQUIRED');
        $identity = ['run_id' => $id, 'request_hash' => $run->request_hash, 'response_hash' => $run->response_hash,
            'request_artifact_hash' => $requestArtifact->sha256, 'trace_artifact_hash' => $traceArtifact->sha256,
            'ledger_artifact_hash' => $ledgerArtifact->sha256, 'clock_hash' => $clock['receipt_hash'], 'binding_hash' => $this->hash($binding)];
        if (! $products) return compact('identity', 'binding', 'request');
        [$trace] = $this->artifact($id, 'decision_trace', $traceArtifact->sha256);
        [$ledger] = $this->artifact($id, 'trade_ledger', $ledgerArtifact->sha256);
        $this->need($this->hash($trace) === $this->hash((array) ($response['decision_trace'] ?? []))
            && $this->hash($ledger) === $this->hash((array) ($response['trade_ledger'] ?? [])), 'META_MODEL_FULL_PRODUCT_CONTENT_MISMATCH');
        return compact('identity', 'binding', 'request', 'response', 'trace', 'ledger', 'clock');
    }

    private function effectivePolicy(array $request): string
    {
        $controls = ['random_seed' => $request['random_seed'] ?? 42, 'signal_delay_candles' => $request['signal_delay_candles'] ?? 0,
            'evaluation_mode' => $request['evaluation_mode'] ?? 'full', 'volume_context' => $request['volume_context'] ?? [],
            'mtf_snapshot_manifest' => $request['mtf_snapshot_manifest'] ?? []];
        $method = $request['instrument_method_learning'] ?? data_get($request, 'policy_context.instrument_method_learning');
        if ($method === null) return $this->hash(['kind' => 'plain_exact_strategy', 'base_strategy' => $request['base_strategy'] ?? null,
            'initial_balance' => $request['initial_balance'] ?? null, 'risk_per_trade' => $request['risk_per_trade'] ?? null,
            'hard_limits' => $request['hard_limits'] ?? data_get($request, 'policy_context.hard_limits'), 'runtime_controls' => $controls]);
        $this->need(is_array($method) && ($method['contract_hash'] ?? null) === $this->hash(array_diff_key($method, array_flip(['contract_hash', 'contract_json']))), 'META_MODEL_EFFECTIVE_POLICY_SEAL_INVALID');
        $policy = $method['policy'] ?? null;
        $this->need(is_array($policy) && is_array($policy['rules'] ?? null) && $this->sha($method['library_hash'] ?? null), 'META_MODEL_EFFECTIVE_POLICY_REQUIRED');
        return $this->hash(['kind' => 'instrument_method_policy', 'base_strategy' => $request['base_strategy'] ?? null,
            'library_hash' => $method['library_hash'], 'rules' => $policy['rules'], 'default_action' => $policy['default_action'] ?? null,
            'initial_balance' => $request['initial_balance'] ?? null, 'risk_per_trade' => $request['risk_per_trade'] ?? null,
            'hard_limits' => $request['hard_limits'] ?? data_get($request, 'policy_context.hard_limits'), 'runtime_controls' => $controls]);
    }

    private function records(array $design): array
    {
        if (isset($design['native_member'])) return $this->nativeRecords($design);
        $seen = []; $records = []; $sourceFacts = []; $eventCount = 0; $tradeCount = 0; $duplicates = 0; $excluded = 0; $postCutoff = 0;
        foreach ($design['roster'] as $identity) {
            $proof = $this->original($identity['run_id'], true);
            $this->need($this->hash($proof['identity']) === $this->hash($identity)
                && $this->hash($proof['binding']) === $this->hash($design['binding']), 'META_MODEL_FROZEN_ROSTER_CHANGED');
            $eventCount += count($proof['trace']); $tradeCount += count($proof['ledger']);
            $this->need($eventCount <= $design['max_events'] && $tradeCount <= $design['max_trades'], 'META_MODEL_ORIGINAL_RESOURCE_CAP_EXCEEDED');
            $entries = []; $methodContexts = [];
            foreach ($proof['trace'] as $event) {
                $method = $event['method_learning'] ?? null;
                if (is_array($method) && ($method['phase'] ?? null) === 'entry') {
                    $this->need(($method['receipt_hash'] ?? null) === $this->hash(array_diff_key($method, ['receipt_hash' => true]))
                        && ($method['context_hash'] ?? null) === $this->hash((array) ($method['context'] ?? []))
                        && $this->utc($method['observed_at'] ?? null) <= $this->utc($method['decision_at'] ?? null),
                        'META_MODEL_ORIGINAL_CONTEXT_RECEIPT_INVALID');
                    $contextKey = $this->utc($method['decision_at'] ?? null)->toIso8601String().'|'.strtoupper($method['context']['direction'] ?? '');
                    $this->need(! isset($methodContexts[$contextKey]), 'META_MODEL_DUPLICATE_CONTEXT_RECEIPT');
                    $methodContexts[$contextKey] = $method['context'] ?? [];
                }
                if (($event['event_type'] ?? null) !== 'signal_evaluation' || ($event['accepted'] ?? null) !== true
                    || ! in_array($event['action'] ?? null, ['BUY', 'SELL'], true)) continue;
                $key = $this->utc($event['candle_time'] ?? null)->toIso8601String().'|'.$event['action'];
                $this->need(! isset($entries[$key]), 'META_MODEL_DUPLICATE_ENTRY_TRACE');
                $entries[$key] = $event;
            }
            $balance = $proof['request']['initial_balance'] ?? null;
            $this->need($this->number($balance) && $balance > 0 && is_array($proof['ledger']) && array_is_list($proof['ledger'])
                && ($proof['response']['total_trades'] ?? null) === count($proof['ledger']), 'META_MODEL_ORIGINAL_ACCOUNT_LEDGER_REQUIRED');
            $balance = (float) $balance;
            foreach ($proof['ledger'] as $trade) {
                $entry = $this->utc($trade['entry_time'] ?? null); $exit = $this->utc($trade['exit_time'] ?? null);
                $signal = $this->utc($trade['signal_time'] ?? null); $available = $exit->addSeconds($design['binding']['clock_seconds']);
                $this->need($signal->addSeconds($design['binding']['clock_seconds']) <= $entry && $entry <= $exit
                    && $entry >= $this->utc($proof['clock']['execution_start']) && $exit <= $this->utc($proof['clock']['execution_end']),
                    'META_MODEL_LABEL_CLOCK_INVALID');
                $this->need($this->number($trade['balance'] ?? null) && $this->number($trade['execution_cost_percent'] ?? null)
                    && is_string($trade['exit_reason'] ?? null), 'META_MODEL_NET_OWNED_CLOSE_REQUIRED');
                $net = round((float) $trade['balance'] - $balance, 8); $balance = (float) $trade['balance'];
                if (str_contains(strtolower($trade['exit_reason']), 'end_of_data') || ($trade['outcome_matured'] ?? true) !== true
                    || ($trade['censored'] ?? false) !== false) { $excluded++; continue; }
                // The bar can start in 2025 yet its close becomes observable in
                // the paper epoch. Account reconciliation retains that trade;
                // it must never contribute a research training label.
                if ($available >= $this->cutoff()) { $postCutoff++; continue; }
                $event = $entries[$entry->toIso8601String().'|'.($trade['direction'] ?? '')] ?? null;
                $this->need(is_array($event) && $this->number($event['confidence'] ?? null)
                    && $event['confidence'] >= 0 && $event['confidence'] <= 1, 'META_MODEL_ACTUAL_ENTRY_FEATURES_REQUIRED');
                $this->need($this->number($trade['signal_confidence'] ?? null)
                    && abs($event['confidence'] - $trade['signal_confidence']) <= 0.000051, 'META_MODEL_ENTRY_CONFIDENCE_MISMATCH');
                $originalContext = $methodContexts[$entry->toIso8601String().'|'.$event['action']] ?? [];
                $session = data_get($event, 'features.market_session', $originalContext['session'] ?? null);
                $context = ['regime' => $this->token($event['market_regime'] ?? null),
                    'volatility' => $this->volatility($event['volatility_regime'] ?? null),
                    'session' => $this->session($session), 'direction' => strtolower($event['action'])];
                // No candle_high/low/close is read: those trace fields include
                // the execution candle and are NOT known at its entry open.
                $entryFacts = ['symbol' => $design['binding']['symbol'], 'timeframe' => $design['binding']['timeframe'],
                    'direction' => $event['action'], 'entry_time' => $entry->toIso8601String()];
                $labelFacts = ['exit_time' => $exit->toIso8601String(), 'label_available_at' => $available->toIso8601String(),
                    'net_cash' => $net, 'success' => $net > 0, 'exit_reason' => $trade['exit_reason']];
                $record = ['physical_entry_hash' => $this->hash($entryFacts), 'entry_hash' => $this->hash($event),
                    'label_hash' => $this->hash($labelFacts), ...$entryFacts, ...$labelFacts,
                    'observed_at' => $signal->addSeconds($design['binding']['clock_seconds'])->toIso8601String(),
                    'context' => $context, 'confidence' => (float) $event['confidence']];
                $duplicateFacts = array_diff_key($record, array_flip(['entry_hash']));
                if (isset($seen[$record['physical_entry_hash']])) {
                    $this->need($seen[$record['physical_entry_hash']] === $this->hash($duplicateFacts), 'META_MODEL_SAME_PHYSICAL_ENTRY_CONFLICT');
                    $duplicates++; continue;
                }
                $seen[$record['physical_entry_hash']] = $this->hash($duplicateFacts); $records[] = $record;
            }
            $this->need($this->number($proof['response']['final_balance'] ?? null)
                && abs($balance - $proof['response']['final_balance']) <= 0.011, 'META_MODEL_ACCOUNT_RECONCILIATION_FAILED');
            $sourceFacts[] = $proof['identity'];
        }
        usort($records, fn ($a, $b) => strcmp($a['entry_time'], $b['entry_time']) ?: strcmp($a['physical_entry_hash'], $b['physical_entry_hash']));
        return ['records' => $records, 'records_hash' => $this->hash($records), 'originals_hash' => $this->hash($sourceFacts),
            'duplicates_excluded' => $duplicates, 'censored_excluded' => $excluded, 'post_cutoff_labels_excluded' => $postCutoff,
            'events_scanned' => $eventCount, 'trades_scanned' => $tradeCount];
    }

    private function train(array $design, array $facts, string $registrationHash): array
    {
        $train = []; $calibration = []; $outside = 0;
        foreach ($facts['records'] as $record) {
            $entry = $this->utc($record['entry_time']); $available = $this->utc($record['label_available_at']);
            if ($entry >= $this->utc($design['training_range']['start']) && $available <= $this->utc($design['training_range']['end'])) $train[] = $record;
            elseif ($entry >= $this->utc($design['calibration_range']['start']) && $available <= $this->utc($design['calibration_range']['end'])) $calibration[] = $record;
            else $outside++;
        }
        $base = ['protocol' => $design['protocol'], 'registration_hash' => $registrationHash,
            'records_hash' => $facts['records_hash'], 'originals_hash' => $facts['originals_hash'],
            'training_samples' => count($train), 'calibration_samples' => count($calibration),
            'duplicates_excluded' => $facts['duplicates_excluded'], 'censored_excluded' => $facts['censored_excluded'],
            'post_cutoff_labels_excluded' => $facts['post_cutoff_labels_excluded'],
            'outside_or_boundary_labels_excluded' => $outside, ...$this->ceiling()];
        if (count($train) < $design['minimum_training'] || count($calibration) < $design['minimum_calibration'])
            return [[...$base, 'status' => 'underpowered', 'reason' => 'META_MODEL_UNIQUE_MATURED_TRAINING_CALIBRATION_REQUIRED'], null];
        if (! $this->bothClasses($train) || ! $this->bothClasses($calibration))
            return [[...$base, 'status' => 'underpowered', 'reason' => 'META_MODEL_BOTH_CLASSES_IN_EACH_SPLIT_REQUIRED'], null];
        $bins = [];
        foreach ($train as $record) {
            $index = $this->bin($record['confidence'], $design['confidence_edges']);
            $key = $this->cellKey($record['context'], $index);
            $bins[$key] ??= ['key' => $key, 'context' => $record['context'], 'confidence_bin' => $index, 'count' => 0, 'wins' => 0];
            $bins[$key]['count']++; $bins[$key]['wins'] += (int) $record['success'];
        }
        $this->need(count($bins) <= $design['maximum_cells'], 'META_MODEL_FINITE_CELL_CAP_EXCEEDED');
        ksort($bins);
        foreach ($bins as &$cell) $cell['score'] = ($cell['wins'] + $design['smoothing']) / ($cell['count'] + 2 * $design['smoothing']);
        unset($cell);
        $calibrationBins = []; $reachable = 0; $reachableWins = 0; $brier = 0.0;
        foreach ($calibration as $record) {
            $key = $this->cellKey($record['context'], $this->bin($record['confidence'], $design['confidence_edges']));
            if (! isset($bins[$key]) || $bins[$key]['count'] < $design['minimum_cell_training']) continue;
            $score = $bins[$key]['score']; $index = $this->bin($score, $design['probability_edges']);
            $calibrationBins[$index] ??= ['probability_bin' => $index, 'count' => 0, 'wins' => 0];
            $calibrationBins[$index]['count']++; $calibrationBins[$index]['wins'] += (int) $record['success'];
            $reachable++; $reachableWins += (int) $record['success']; $brier += ($score - (int) $record['success']) ** 2;
        }
        if ($reachable < $design['minimum_calibration'])
            return [[...$base, 'status' => 'underpowered', 'reason' => 'META_MODEL_CALIBRATION_CONTEXT_NOT_REACHED', 'reachable_calibration_samples' => $reachable], null];
        if ($reachableWins === 0 || $reachableWins === $reachable)
            return [[...$base, 'status' => 'underpowered', 'reason' => 'META_MODEL_REACHED_CALIBRATION_BOTH_CLASSES_REQUIRED'], null];
        ksort($calibrationBins);
        foreach ($calibrationBins as &$cell) $cell['score'] = ($cell['wins'] + $design['smoothing']) / ($cell['count'] + 2 * $design['smoothing']);
        unset($cell);
        $last = max(array_column([...$train, ...$calibration], 'label_available_at'));
        $artifact = ['protocol' => isset($design['native_member']) ? self::NATIVE_PROTOCOL : self::PROTOCOL, 'registration_hash' => $registrationHash, 'binding' => $design['binding'],
            'context_fields' => self::CONTEXT_FIELDS, 'confidence_edges' => $design['confidence_edges'], 'probability_edges' => $design['probability_edges'],
            'smoothing' => $design['smoothing'], 'minimum_cell_training' => $design['minimum_cell_training'],
            'minimum_cell_calibration' => $design['minimum_cell_calibration'], 'training_samples' => count($train),
            'calibration_samples' => count($calibration), 'training_cutoff' => $design['training_range']['end'],
            'calibration_cutoff' => $design['calibration_range']['end'], 'last_label_available_at' => $last,
            'training_range' => $design['training_range'], 'calibration_range' => $design['calibration_range'],
            'records_hash' => $facts['records_hash'], 'originals_hash' => $facts['originals_hash'],
            'training_records_hash' => $this->hash($train), 'calibration_records_hash' => $this->hash($calibration),
            'bins' => array_values($bins), 'calibration_bins' => array_values($calibrationBins), ...$this->ceiling()];
        $this->need(strlen($this->json($artifact)) <= $design['maximum_model_bytes'], 'META_MODEL_FINITE_ARTIFACT_CAP_EXCEEDED');
        return [[...$base, 'status' => 'fitted_diagnostic', 'reason' => 'META_MODEL_FINITE_CHRONOLOGICAL_TRAINING_COMPLETED',
            'reachable_calibration_samples' => $reachable, 'training_model_calibration_brier' => $brier / $reachable], $artifact];
    }

    private function design(object $row): array
    {
        $design = json_decode($row->design, true, 64, JSON_THROW_ON_ERROR);
        $this->need($this->hash($design) === $row->registration_hash
            && (($design['protocol'] ?? null) === self::REGISTRATION_PROTOCOL && ! isset($design['native_member'])
                || ($design['protocol'] ?? null) === self::NATIVE_REGISTRATION_PROTOCOL && is_string($design['native_member'] ?? null)), 'META_MODEL_DESIGN_HASH_INVALID');
        return $design;
    }
    private function verifiedArtifact(object $row): array
    {
        $body = json_decode($row->artifact_json, true, 64, JSON_THROW_ON_ERROR);
        $this->need(in_array($body['protocol'] ?? null, [self::PROTOCOL, self::NATIVE_PROTOCOL], true)
            && hash('sha256', $row->artifact_json) === $row->model_hash
            && hash_equals($this->signature($row->model_hash, $body['protocol']), $row->issuer_signature), 'META_MODEL_SERVER_ARTIFACT_SEAL_INVALID');
        $this->need(($body['registration_hash'] ?? null) === $row->registration_hash, 'META_MODEL_ARTIFACT_OWNER_MISMATCH');
        foreach ($this->ceiling() as $key => $value) $this->need(($body[$key] ?? null) === $value, 'META_MODEL_AUTHORITY_FORBIDDEN');
        return $body;
    }
    private function artifact(string $runId, string $type, string $sha): array
    {
        $row = LabEvidenceArtifact::where('run_id', $runId)->where('artifact_type', $type)->where('sha256', $sha)->oldest('id')->first();
        $this->need($row !== null, 'META_MODEL_ORIGINAL_ARTIFACT_MISSING');
        $owner = app(LabImmutableEvidenceService::class); $body = $owner->readArtifactPayload($row);
        $this->need(is_array($body) && ($row->storage_path || $owner->hash($body) === $row->sha256), 'META_MODEL_ORIGINAL_ARTIFACT_HASH_INVALID');
        return [$body, $row];
    }
    private function range(array $value): array
    {
        $this->need(array_keys($value) !== [] && array_diff(array_keys($value), ['start', 'end']) === [], 'META_MODEL_RANGE_FIELDS_INVALID');
        $start = $this->utc($value['start'] ?? null); $end = $this->utc($value['end'] ?? null);
        $this->need($start <= $end && $end < $this->cutoff(), 'META_MODEL_PRE2026_RANGE_REQUIRED');
        return ['start' => $start->toIso8601String(), 'end' => $end->toIso8601String()];
    }
    private function edges(array $edges): array
    {
        $this->need(array_is_list($edges) && count($edges) >= 2 && count($edges) <= 9
            && $edges[0] == 0 && $edges[array_key_last($edges)] == 1, 'META_MODEL_FINITE_BIN_EDGES_REQUIRED');
        $previous = -1;
        foreach ($edges as $value) { $this->need($this->number($value) && $value >= 0 && $value <= 1 && $value > $previous, 'META_MODEL_BIN_EDGES_INVALID'); $previous = $value; }
        return array_map(fn ($value) => (float) $value, $edges);
    }
    private function bin(float $value, array $edges): int
    {
        for ($index = 0; $index < count($edges) - 1; $index++) if ($value < $edges[$index + 1] || $index === count($edges) - 2) return $index;
        throw new LogicException('META_MODEL_BIN_OUTSIDE_RANGE');
    }
    private function bothClasses(array $records): bool { return count(array_unique(array_map(fn ($record) => (int) $record['success'], $records))) === 2; }
    private function cellKey(array $context, int $bin): string { return $this->hash(['context' => $context, 'confidence_bin' => $bin]); }
    private function session(mixed $value): string { $token = $this->token($value); return ['london_new_york_overlap' => 'overlap', 'london_comex_overlap' => 'overlap', 'asian' => 'asia'][$token] ?? $token; }
    private function volatility(mixed $value): string { $token = $this->token($value); return ['normal_volatility' => 'normal', 'low_volatility' => 'low', 'high_volatility' => 'high'][$token] ?? $token; }
    private function token(mixed $value): string { $this->need(is_string($value) && preg_match('/^[A-Za-z0-9_:-]{1,64}$/D', $value) === 1, 'META_MODEL_ORIGINAL_CONTEXT_REQUIRED'); return strtolower($value); }
    private function utc(mixed $value): CarbonImmutable { $this->need(is_string($value) && preg_match('/(?:Z|[+-]\d{2}:\d{2})$/D', $value) === 1, 'META_MODEL_EXPLICIT_UTC_REQUIRED'); return CarbonImmutable::parse($value)->utc(); }
    private function cutoff(): CarbonImmutable { return app(ResearchPaperEpochContractService::class)->cutoff(); }
    private function number(mixed $value): bool { return (is_int($value) || is_float($value)) && is_finite((float) $value); }
    private function sha(mixed $value): bool { return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1; }
    private function signature(string $hash, string $protocol = self::PROTOCOL): string { $key = config('app.key'); $this->need(is_string($key) && $key !== '', 'META_MODEL_SERVER_KEY_REQUIRED'); return hash_hmac('sha256', $protocol.':'.$hash, $key); }
    private function hash(array $value): string { return app(ResearchPaperEpochContractService::class)->parameterHash($value); }
    private function json(array $value): string { $sort = function ($item) use (&$sort) { if (! is_array($item)) return $item; if (! array_is_list($item)) ksort($item); return array_map($sort, $item); }; return json_encode($sort($value), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR); }
    private function need(bool $condition, string $reason): void { if (! $condition) throw new LogicException($reason); }
    private function ceiling(): array { return ['research_only' => true, 'out_of_sample' => false, 'independent_validation' => false,
        'confirmation_proven' => false, 'economic_authority' => false, 'promotion_evidence' => false, 'live_authority' => false,
        'paper_authority' => false, 'skill_credit' => false, 'mastery_proven' => false]; }
    private function blocked(Throwable $error): array { return ['status' => 'blocked', 'reason' => preg_match('/^META_MODEL_[A-Z0-9_]+$/D', $error->getMessage()) ? $error->getMessage() : 'META_MODEL_ORIGINAL_VERIFICATION_FAILED', ...$this->ceiling()]; }
}
