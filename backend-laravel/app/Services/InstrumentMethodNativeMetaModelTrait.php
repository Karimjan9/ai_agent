<?php

namespace App\Services;

use App\Models\LabEvidenceArtifact;
use Throwable;

/** Native finite assets use own mature position cash, never shared balance deltas. */
trait InstrumentMethodNativeMetaModelTrait
{
    public const NATIVE_PROTOCOL = 'instrument_method_native_meta_model_v1';
    public const NATIVE_REGISTRATION_PROTOCOL = 'instrument_method_native_meta_training_v1';
    public const NATIVE_BINDING_FIELDS = ['symbol', 'timeframe', 'member_physical_hash', 'council_physical_hash',
        'effective_policy_hash', 'data_hash', 'execution_hash', 'source_hash', 'python_source_hash', 'clock_seconds'];
    public const NATIVE_PHYSICAL_FIELDS = ['symbol', 'timeframe', 'member_physical_hash', 'council_physical_hash', 'effective_policy_hash', 'execution_hash', 'clock_seconds'];
    public const NATIVE_PARENT_PORT_DEFAULTS = ['signal_delay_candles' => 0, 'random_seed' => 42, 'from_date' => null, 'to_date' => null,
        'dataset_tail_rows' => null, 'regime_dataset_tail_rows' => null, 'mtf_dataset_tail_rows' => [], 'related_mtf_dataset_tail_rows' => [],
        'mtf_pilot' => [], 'mtf_snapshot_manifest' => [], 'volume_context' => [], 'runtime_ensemble_policy' => [],
        'native_spread_context_study_contract' => [], 'native_reachability_depth_audit_contract' => [], 'native_standalone_qualification' => []];

    public function preregisterNative(array $input): array
    {
        try {
            $member = $input['native_member'] ?? null;
            $this->need(is_string($member) && preg_match('/^[A-Za-z0-9_.:\/-]{1,128}$/D', $member) === 1,
                'META_MODEL_NATIVE_MEMBER_REQUIRED');
            unset($input['native_member']);
            return $this->registerTraining($input, $member);
        } catch (Throwable $error) { return $this->blocked($error); }
    }

    /** Two-stage identity: physical programme first, final contract/asset references second. */
    public function nativeEvaluationBinding(array $request, array $council, array $member): array
    {
        $this->need(is_array($council['upgrades'] ?? null) && $council['upgrades'] === [], 'META_MODEL_NATIVE_UPGRADES_UNSUPPORTED');
        $this->need(($council['protocol'] ?? null) === SpecialistCouncilContractService::RUNTIME_PROTOCOL
            && ($council['symbol'] ?? null) === ($request['symbol'] ?? null)
            && ($council['execution_timeframe'] ?? null) === ($request['timeframe'] ?? null)
            && ($council['execution_hash'] ?? null) === data_get($request, 'execution_contract.execution_hash')
            && ($council['replay_dataset_hash'] ?? null) === ($request['replay_dataset_hash'] ?? null), 'META_MODEL_NATIVE_ACTUAL_COUNCIL_REQUIRED');
        $members = $council['members'] ?? null;
        $this->need(is_array($members) && array_is_list($members) && count($members) >= 1 && count($members) <= 32,
            'META_MODEL_NATIVE_MEMBER_ROSTER_REQUIRED');
        $this->need(count(array_unique(array_column($members, 'specialist_id'))) === count($members), 'META_MODEL_NATIVE_MEMBER_ROSTER_REQUIRED');
        $matches = array_values(array_filter($members, fn ($item) => ($item['specialist_id'] ?? null) === ($member['specialist_id'] ?? null)));
        $this->need(count($matches) === 1 && $this->hash($matches[0]) === $this->hash($member), 'META_MODEL_NATIVE_EXACT_MEMBER_REQUIRED');
        foreach ($members as $peer) $this->need(($peer['specialist_id'] ?? null) === $member['specialist_id']
            || ! array_key_exists('meta_model', (array) ($peer['method_learning'] ?? [])), 'META_MODEL_NATIVE_PEER_ASSET_UNSUPPORTED');
        $physicalMember = fn ($item) => array_diff_key($item, array_flip(['model_version_id', 'passport_hash', 'version', 'method_learning']));
        $programme = array_diff_key($council, array_flip(['contract_hash', 'contract_json', 'council_id', 'council_version',
            'manifest_hash', 'version_id', 'replay_dataset_hash', 'members']));
        $programme['members'] = array_map($physicalMember, $members);
        $seconds = ['M1' => 60, 'M5' => 300, 'M15' => 900, 'M30' => 1800, 'H1' => 3600, 'H4' => 14400, 'D1' => 86400][$request['timeframe'] ?? ''] ?? null;
        $this->need($seconds !== null && $this->number($request['initial_balance'] ?? null) && $request['initial_balance'] > 0
            && $this->number($request['risk_per_trade'] ?? null) && $request['risk_per_trade'] > 0, 'META_MODEL_NATIVE_ACCOUNT_CLOCK_REQUIRED');
        $this->need(! array_key_exists('policy_context', $request) || is_array($request['policy_context'])
            && ($request['policy_context'] === [] || ! array_is_list($request['policy_context'])), 'META_MODEL_NATIVE_POLICY_CONTEXT_REQUIRED');
        $hardLimits = $request['hard_limits'] ?? data_get($request, 'policy_context.hard_limits');
        $this->need($hardLimits === null || is_array($hardLimits) && ($hardLimits === [] || ! array_is_list($hardLimits)),
            'META_MODEL_NATIVE_HARD_LIMIT_OBJECT_REQUIRED');
        // Only statistical trial counts are omitted. Repair contracts and
        // transport, boundary, window/warmup, unknown and behavioral ports stay
        // exact in conservative v1; a changed clock scope is NOT transferable.
        $context = array_diff_key((array) ($request['policy_context'] ?? []), array_flip(['instrument_method_learning', 'trial_ledger']));
        $mode = array_key_exists('evaluation_mode', $request) ? $request['evaluation_mode'] : 'full';
        $this->need(in_array($mode, ['incremental', 'full', 'replay', 'temporal_ablation'], true), 'META_MODEL_NATIVE_EVALUATION_MODE_REQUIRED');
        $parentPorts = [];
        foreach (self::NATIVE_PARENT_PORT_DEFAULTS as $key => $default) {
            $value = array_key_exists($key, $request) ? $request[$key] : $default;
            $valid = match ($key) {
                'signal_delay_candles' => is_int($value) && $value >= 0 && $value <= 3,
                'random_seed' => is_int($value),
                'from_date', 'to_date' => $value === null || is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1
                    && ($date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value)) && $date->format('Y-m-d') === $value,
                'dataset_tail_rows', 'regime_dataset_tail_rows' => $value === null || is_int($value) && $value >= 2 && $value <= 200000,
                default => is_array($value) && ($value === [] || ! array_is_list($value)),
            };
            $this->need((bool) $valid, 'META_MODEL_NATIVE_PARENT_RUNTIME_PORT_INVALID');
            $parentPorts[$key] = $value;
        }
        $policies = array_map(function ($item) {
            $method = $item['method_learning'] ?? null;
            $this->need($method === null || is_array($method) && ! array_is_list($method), 'META_MODEL_NATIVE_METHOD_OBJECT_REQUIRED');
            $policy = $method['policy'] ?? null;
            $this->need($method === null || is_array($policy) && ! array_is_list($policy)
                && is_array($policy['rules'] ?? null) && array_is_list($policy['rules'])
                && in_array($policy['default_action'] ?? null, ['BASELINE', 'WAIT'], true), 'META_MODEL_NATIVE_MEMBER_POLICY_OBJECT_REQUIRED');
            return ['specialist_id' => $item['specialist_id'], 'method' => $method === null ? null : [
                'library_hash' => $method['library_hash'] ?? null, 'compiled_pool_hash' => $method['compiled_pool_hash'] ?? null,
                'scope' => $method['scope'] ?? null, 'strategy_family' => $method['strategy_family'] ?? null,
                'rules' => $method['policy']['rules'] ?? null, 'default_action' => $method['policy']['default_action'] ?? null]];
        }, $members);
        return ['symbol' => $request['symbol'], 'timeframe' => $request['timeframe'],
            'member_physical_hash' => $this->hash($physicalMember($member)),
            'council_physical_hash' => $this->hash(['protocol' => 'instrument_method_native_physical_scope_v1',
                'symbol' => $request['symbol'], 'timeframe' => $request['timeframe'], 'clock_seconds' => $seconds,
                'evaluation_mode' => $mode,
                'parent_runtime_ports' => $parentPorts,
                'initial_balance' => (float) $request['initial_balance'], 'risk_per_trade' => (float) $request['risk_per_trade'],
                'hard_limits' => $request['hard_limits'] ?? data_get($request, 'policy_context.hard_limits'),
                'policy_context' => $context, 'council' => $programme]),
            'effective_policy_hash' => $this->hash($policies), 'execution_hash' => $council['execution_hash'], 'clock_seconds' => $seconds];
    }

    private function nativeOriginal(string $id, string $memberId, bool $products): array
    {
        $owner = app(LabImmutableEvidenceService::class); $run = $owner->findRun($id);
        $this->need($run && $run->status === 'completed' && $run->phase !== 'paper'
            && ($owner->learningEligibility($run)['complete'] ?? false) === true, 'META_MODEL_NATIVE_COMPLETE_ORIGINAL_REQUIRED');
        $runtime = $owner->verifiedModelRuntimeIdentity($run);
        $this->need(is_array($runtime), 'META_MODEL_NATIVE_ORIGINAL_RUNTIME_REQUIRED');
        [$request, $requestArtifact] = $this->artifact($id, 'evaluation_request', $runtime['request_artifact_hash'] ?? '');
        foreach (['native_spread_context_study_contract', 'native_reachability_depth_audit_contract', 'native_standalone_qualification'] as $port)
            $this->need(empty($request[$port]), 'META_MODEL_NATIVE_DIAGNOSTIC_STUDY_SOURCE_FORBIDDEN');
        $this->need(data_get($requestArtifact->metadata, 'request_hash') === $run->request_hash && empty($request['strategies'])
            && empty($request['portfolio_members']) && ! app(ResearchPaperEpochContractService::class)->researchInputHasPaperData($request),
            'META_MODEL_NATIVE_PLAIN_PRE2026_SOURCE_REQUIRED');
        [$response] = $this->artifact($id, 'evaluation_response', (string) $run->response_hash);
        $council = $request['specialist_council_contract'] ?? null;
        $native = $response['specialist_council_receipt'] ?? data_get($response, 'data_quality.specialist_council_receipt');
        $this->need(is_array($council) && is_array($native) && ($native['status'] ?? null) === 'computed'
            && ($native['contract_hash'] ?? null) === ($council['contract_hash'] ?? null)
            && $council['contract_hash'] === $this->hash(array_diff_key($council, array_flip(['contract_hash', 'contract_json'])))
            && ($native['dataset_hash'] ?? null) === $run->data_hash && ($request['replay_dataset_hash'] ?? null) === $run->data_hash,
            'META_MODEL_NATIVE_ORIGINAL_COUNCIL_SEAL_REQUIRED');
        app(SpecialistCouncilLifecycleService::class)->assertReceiptSeal($native);
        $this->need($owner->nativeDecisionTraceHashValid($response, $native), 'META_MODEL_NATIVE_ACTUAL_TRACE_HASH_REQUIRED');
        $execution = $request['execution_contract'] ?? [];
        $this->need(($execution['protocol'] ?? null) === ExecutionContractService::PROTOCOL
            && app(ExecutionContractService::class)->hashParameters((array) ($execution['parameters'] ?? [])) === ($execution['execution_hash'] ?? null)
            && ($native['execution_hash'] ?? null) === $execution['execution_hash']
            && data_get($response, 'execution_contract.execution_hash') === $execution['execution_hash']
            && app(ExecutionContractService::class)->hashParameters((array) data_get($response, 'execution_contract.parameters')) === $execution['execution_hash'],
            'META_MODEL_NATIVE_ACTUAL_EXECUTION_REQUIRED');
        $members = array_values(array_filter((array) ($council['members'] ?? []), fn ($item) => ($item['specialist_id'] ?? null) === $memberId));
        $this->need(count($members) === 1, 'META_MODEL_NATIVE_EXACT_MEMBER_REQUIRED'); $member = $members[0];
        $evaluation = $this->nativeEvaluationBinding($request, $council, $member);
        $binding = [...$evaluation, 'data_hash' => $run->data_hash, 'source_hash' => $run->code_hash,
            'python_source_hash' => data_get($request, 'research_release.python_source_hash')];
        foreach (array_diff(self::NATIVE_BINDING_FIELDS, ['symbol', 'timeframe', 'clock_seconds']) as $key)
            $this->need($this->sha($binding[$key] ?? null), 'META_MODEL_NATIVE_ORIGINAL_SOURCE_HASH_REQUIRED');
        $clock = $native['replay_executed_clock'] ?? null;
        $this->need(is_array($clock) && ($clock['protocol'] ?? null) === 'replay_executed_clock_v1'
            && ($clock['owner'] ?? null) === 'native_specialist_council_v1' && ($clock['complete'] ?? null) === true
            && ($clock['semantics'] ?? null) === 'previous_closed_candle_next_open_v1'
            && ($clock['duration_seconds'] ?? null) === $evaluation['clock_seconds']
            && ($clock['dataset_hash'] ?? null) === $run->data_hash && ($clock['execution_hash'] ?? null) === $evaluation['execution_hash']
            && ($clock['receipt_hash'] ?? null) === $this->hash(array_diff_key($clock, array_flip(['receipt_hash', 'receipt_json'])))
            && is_string($clock['receipt_json'] ?? null) && hash('sha256', $clock['receipt_json']) === $clock['receipt_hash'],
            'META_MODEL_NATIVE_ORIGINAL_CLOCK_REQUIRED');
        $this->need($this->utc($clock['execution_start']) <= $this->utc($clock['execution_end'])
            && $this->utc($clock['execution_end']) < $this->cutoff(), 'META_MODEL_NATIVE_PRE2026_CLOCK_REQUIRED');
        foreach (['input_rows', 'evaluation_offset_rows', 'decision_rows', 'first_evaluation_index', 'last_evaluation_index'] as $field)
            $this->need(is_int($clock[$field] ?? null) && $clock[$field] >= 0 && $clock[$field] <= 200000, 'META_MODEL_NATIVE_CLOCK_ROWS_INVALID');
        $this->need(($clock['index_basis'] ?? null) === 'evaluated_frame_zero_based_v1'
            && ($clock['execution_timeframe'] ?? null) === $request['timeframe'] && ($clock['promotion_evidence'] ?? null) === false
            && $clock['first_evaluation_index'] === 1 && $clock['last_evaluation_index'] === $clock['input_rows'] - $clock['evaluation_offset_rows'] - 1
            && $clock['decision_rows'] === $clock['last_evaluation_index'] && $this->sha($clock['schedule_hash'] ?? null), 'META_MODEL_NATIVE_CLOCK_COVERAGE_INVALID');
        $indices = hash_init('sha256'); hash_update($indices, "replay-executed-clock-v1:indices\n");
        for ($index = 1; $index <= $clock['last_evaluation_index']; $index++) hash_update($indices, $index."\n");
        $this->need(hash_final($indices) === ($clock['index_set_hash'] ?? null)
            && $this->utc($clock['signal_start'])->addSeconds($evaluation['clock_seconds']) <= $this->utc($clock['execution_start'])
            && $this->utc($clock['signal_end'])->addSeconds($evaluation['clock_seconds']) <= $this->utc($clock['execution_end']),
            'META_MODEL_NATIVE_CLOSED_SIGNAL_CLOCK_REQUIRED');
        $traceArtifact = LabEvidenceArtifact::where('run_id', $id)->where('artifact_type', 'decision_trace')->oldest('id')->first();
        $ledgerArtifact = LabEvidenceArtifact::where('run_id', $id)->where('artifact_type', 'trade_ledger')->oldest('id')->first();
        $this->need($traceArtifact && $ledgerArtifact, 'META_MODEL_NATIVE_FULL_PRODUCTS_REQUIRED');
        $identity = ['run_id' => $id, 'request_hash' => $run->request_hash, 'response_hash' => $run->response_hash,
            'request_artifact_hash' => $requestArtifact->sha256, 'trace_artifact_hash' => $traceArtifact->sha256,
            'ledger_artifact_hash' => $ledgerArtifact->sha256, 'native_receipt_hash' => $native['receipt_hash'],
            'clock_hash' => $clock['receipt_hash'], 'binding_hash' => $this->hash($binding)];
        if (! $products) return compact('identity', 'binding', 'request');
        [$trace] = $this->artifact($id, 'decision_trace', $traceArtifact->sha256);
        [$ledger] = $this->artifact($id, 'trade_ledger', $ledgerArtifact->sha256);
        $this->need($this->hash($trace) === $this->hash((array) ($response['decision_trace'] ?? []))
            && $this->hash($ledger) === $this->hash((array) ($response['trade_ledger'] ?? [])), 'META_MODEL_NATIVE_FULL_PRODUCT_MISMATCH');
        return compact('identity', 'binding', 'request', 'response', 'trace', 'ledger', 'native', 'clock', 'member');
    }

    private function nativeRecords(array $design): array
    {
        $records = []; $seen = []; $originals = []; $events = 0; $trades = 0; $duplicates = 0; $censored = 0; $paper = 0;
        foreach ($design['roster'] as $identity) {
            $proof = $this->nativeOriginal($identity['run_id'], $design['native_member'], true);
            $this->need($this->hash($proof['identity']) === $this->hash($identity)
                && $this->hash($proof['binding']) === $this->hash($design['binding']), 'META_MODEL_NATIVE_FROZEN_ROSTER_CHANGED');
            $events += count($proof['trace']); $positions = $proof['native']['position_ledger'] ?? null;
            $this->need(is_array($positions) && array_is_list($positions), 'META_MODEL_NATIVE_POSITION_LEDGER_REQUIRED');
            $trades += count($positions);
            $this->need($events <= $design['max_events'] && $trades <= $design['max_trades'], 'META_MODEL_NATIVE_RESOURCE_CAP_EXCEEDED');
            $this->need(count($positions) === count($proof['ledger']) && ($proof['response']['total_trades'] ?? null) === count($positions),
                'META_MODEL_NATIVE_LEDGER_COUNT_MISMATCH');
            $accepted = [];
            foreach ($proof['trace'] as $row) foreach ((array) ($row['member_decisions'] ?? []) as $decision) {
                if (($decision['accepted'] ?? null) !== true) continue;
                $position = $decision['position_id'] ?? null;
                $this->need(is_string($position) && ! isset($accepted[$position]), 'META_MODEL_NATIVE_AMBIGUOUS_ENTRY');
                $accepted[$position] = ['row' => $row, 'member' => $decision];
            }
            $accountNet = 0.0; $accountFees = 0.0; $accountCarry = 0.0; $accountEmbedded = 0.0; $seenPositions = [];
            $declaredMembers = array_column($proof['request']['specialist_council_contract']['members'], null, 'specialist_id');
            foreach ($positions as $position) {
                $positionId = $position['position_id'] ?? null;
                $this->need(is_string($positionId) && ! isset($seenPositions[$positionId]), 'META_MODEL_NATIVE_DUPLICATE_POSITION');
                $seenPositions[$positionId] = true;
                foreach (['net_pnl', 'gross_pnl_after_embedded_cost', 'fees', 'carry', 'spread_slippage'] as $field)
                    $this->need($this->number($position[$field] ?? null), 'META_MODEL_NATIVE_COST_INCLUSIVE_CLOSE_REQUIRED');
                $this->need($position['fees'] >= 0 && $position['spread_slippage'] >= 0
                    && abs($position['net_pnl'] - ($position['gross_pnl_after_embedded_cost'] - $position['fees'] - $position['carry'])) <= 0.000001,
                    'META_MODEL_NATIVE_POSITION_ACCOUNTING_MISMATCH');
                $accountNet += $position['net_pnl'];
                $accountFees += $position['fees']; $accountCarry += $position['carry']; $accountEmbedded += $position['spread_slippage'];
                $declared = $declaredMembers[$position['specialist_id'] ?? ''] ?? null;
                $this->need(is_array($declared) && ($position['model_version_id'] ?? null) === $declared['model_version_id']
                    && ($position['member_version_hash'] ?? null) === $this->hash(['council_version' => $proof['request']['specialist_council_contract']['council_version'], 'member' => $declared])
                    && ($position['management_owner'] ?? null) === $declared['specialist_id']
                    && ($position['management_version'] ?? null) === $declared['management_version'], 'META_MODEL_NATIVE_FOREIGN_POSITION_OWNER');
                $entry = $accepted[$positionId] ?? null;
                $this->need(is_array($entry) && ($entry['member']['specialist_id'] ?? null) === ($position['specialist_id'] ?? null)
                    && ($entry['member']['member_version_hash'] ?? null) === ($position['member_version_hash'] ?? null)
                    && ($entry['member']['action'] ?? null) === ($position['direction'] ?? null), 'META_MODEL_NATIVE_ACCEPTED_POSITION_LINK_REQUIRED');
                if ($position['specialist_id'] !== $design['native_member']) continue;
                $expectedMember = $this->hash(['council_version' => $proof['request']['specialist_council_contract']['council_version'], 'member' => $proof['member']]);
                $this->need($position['member_version_hash'] === $expectedMember
                    && ($position['model_version_id'] ?? null) === ($proof['member']['model_version_id'] ?? null)
                    && ($position['management_owner'] ?? null) === $design['native_member']
                    && ($position['management_version'] ?? null) === $proof['member']['management_version'], 'META_MODEL_NATIVE_FOREIGN_POSITION_OWNER');
                $at = $this->utc($position['entry_time'] ?? null); $exit = $this->utc($position['exit_time'] ?? null);
                $signal = $this->utc($entry['row']['signal_time'] ?? null); $available = $exit->addSeconds($design['binding']['clock_seconds']);
                $this->need($at->equalTo($this->utc($entry['row']['candle_time'] ?? null)) && $signal->addSeconds($design['binding']['clock_seconds']) <= $at
                    && $at <= $exit && $at >= $this->utc($proof['clock']['execution_start']) && $exit <= $this->utc($proof['clock']['execution_end']),
                    'META_MODEL_NATIVE_LABEL_CLOCK_INVALID');
                $this->need(is_bool($position['outcome_matured'] ?? null) && is_string($position['exit_reason'] ?? null), 'META_MODEL_NATIVE_MATURITY_REQUIRED');
                if (! $position['outcome_matured'] || str_contains(strtolower($position['exit_reason']), 'end_of_data')) { $censored++; continue; }
                if ($available >= $this->cutoff()) { $paper++; continue; }
                $input = $entry['member']['closed_inputs'] ?? [];
                $confidence = $input['signal_confidence'] ?? null;
                // Native bounded inspection preserves floats as canonical
                // strings. Parse this one original, hash-bound field only;
                // no trace rewriting, default score or generic codec change.
                if (is_string($confidence) && strlen($confidence) <= 64
                    && preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?$/D', $confidence) === 1) $confidence = (float) $confidence;
                $this->need($this->number($confidence) && $confidence >= 0 && $confidence <= 1
                    && $this->utc($input['time'] ?? null)->equalTo($signal), 'META_MODEL_NATIVE_CLOSED_FEATURES_REQUIRED');
                $context = ['regime' => $this->token($input['market_regime'] ?? null),
                    'volatility' => $this->volatility($input['volatility_regime'] ?? null),
                    'session' => $this->session($input['market_session'] ?? null), 'direction' => strtolower($position['direction'])];
                $physical = ['symbol' => $design['binding']['symbol'], 'timeframe' => $design['binding']['timeframe'],
                    'member_physical_hash' => $design['binding']['member_physical_hash'], 'council_physical_hash' => $design['binding']['council_physical_hash'],
                    'entry_time' => $at->toIso8601String(), 'direction' => $position['direction']];
                $label = ['exit_time' => $exit->toIso8601String(), 'label_available_at' => $available->toIso8601String(),
                    'net_cash' => (float) $position['net_pnl'], 'success' => $position['net_pnl'] > 0, 'exit_reason' => $position['exit_reason']];
                $record = ['physical_entry_hash' => $this->hash($physical), 'entry_hash' => $this->hash($entry), 'label_hash' => $this->hash($label),
                    ...$physical, ...$label, 'observed_at' => $signal->addSeconds($design['binding']['clock_seconds'])->toIso8601String(),
                    'context' => $context, 'confidence' => (float) $confidence];
                $contentHash = $this->hash(array_diff_key($record, ['entry_hash' => true]));
                if (isset($seen[$record['physical_entry_hash']])) {
                    $this->need($seen[$record['physical_entry_hash']] === $contentHash, 'META_MODEL_NATIVE_PHYSICAL_POSITION_CONFLICT'); $duplicates++; continue;
                }
                $seen[$record['physical_entry_hash']] = $contentHash; $records[] = $record;
            }
            $account = $proof['native']['account'] ?? [];
            foreach (['initial_balance', 'final_balance', 'net_pnl', 'fees', 'carry', 'spread_slippage', 'reserved_capital', 'reconciliation_error', 'open_positions'] as $field)
                $this->need($this->number($account[$field] ?? null), 'META_MODEL_NATIVE_ACCOUNT_REQUIRED');
            $this->need(abs($account['initial_balance'] - $proof['request']['initial_balance']) <= 0.000001
                && abs($account['net_pnl'] - $accountNet) <= 0.000001 && abs($account['final_balance'] - $account['initial_balance'] - $accountNet) <= 0.000001
                && abs($account['reserved_capital']) <= 0.000001 && $account['open_positions'] === 0 && abs($account['reconciliation_error']) <= 0.000001
                && count($accepted) === count($positions) && abs($account['fees'] - $accountFees) <= 0.000001
                && abs($account['carry'] - $accountCarry) <= 0.000001 && abs($account['spread_slippage'] - $accountEmbedded) <= 0.000001
                && $this->number($proof['response']['final_balance'] ?? null) && abs($proof['response']['final_balance'] - $account['final_balance']) <= 0.000001,
                'META_MODEL_NATIVE_ACCOUNT_RECONCILIATION_FAILED');
            $originals[] = $proof['identity'];
        }
        usort($records, fn ($a, $b) => strcmp($a['entry_time'], $b['entry_time']) ?: strcmp($a['physical_entry_hash'], $b['physical_entry_hash']));
        return ['records' => $records, 'records_hash' => $this->hash($records), 'originals_hash' => $this->hash($originals),
            'duplicates_excluded' => $duplicates, 'censored_excluded' => $censored, 'post_cutoff_labels_excluded' => $paper,
            'events_scanned' => $events, 'trades_scanned' => $trades];
    }
}
