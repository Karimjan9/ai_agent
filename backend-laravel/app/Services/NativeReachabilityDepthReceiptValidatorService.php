<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use RuntimeException;

/** Pure diagnostic ledger validation; original admission, seals and selection belong to the owner. */
class NativeReachabilityDepthReceiptValidatorService
{
    private const CONTEXT_KEYS = ['regime', 'volatility', 'session', 'venue_phase', 'direction'];
    private const COUNTS = ['raw_opportunities', 'matching_context_opportunities', 'observed_quote_opportunities',
        'missing_quote_opportunities', 'gate_reached_opportunities'];
    private const RAW_PORTS = ['composition_strategy_signal', 'pre_specialist_signal', 'pre_volume_signal', 'signal'];
    private const EVENT_KEYS = ['member_version_hash', 'evaluation_index', 'execution_index', 'signal_time', 'execution_time',
        'direction', 'event_id', 'specialist_id', 'role', 'source_context', 'source_context_hash', 'raw_signal_source',
        'raw_signal_hash', 'source_quote', 'source_quote_hash', 'closed_input_hash', 'gate_reached'];
    private const QUOTE_KEYS = ['available', 'bid', 'ask', 'spread', 'age_ms', 'quote_time', 'available_at', 'provenance_hash', 'source_sha256'];

    public function __construct(private ResearchPaperEpochContractService $epochs) {}

    public function validate(array $receipt, array $seal, array $contract): void
    {
        $view = $contract['execution_view'] ?? [];
        if (! is_array($view) || ! is_array($receipt['execution_view'] ?? null)) $this->refuse('RECEIPT_EXECUTION_VIEW_INVALID');
        $rows = $view['evaluated_rows'] ?? null;
        if (! is_int($rows) || $rows < 2 || $rows > 15000 || ($view['decision_rows'] ?? null) !== $rows - 1
            || ($view['protocol'] ?? null) !== 'native_reachability_execution_view_v1' || ($view['selection'] ?? null) !== 'prefix'
            || ($view['warmup_rows'] ?? null) !== 512 || ($view['source_evaluated_rows'] ?? null) !== 15000
            || ($view['source_loaded_rows'] ?? null) !== 15512 || $this->hash($receipt['execution_view'] ?? []) !== $this->hash($view)) {
            $this->refuse('RECEIPT_EXECUTION_VIEW_INVALID');
        }
        $physical = $receipt['source_attestation'] ?? [];
        $sha = data_get($seal, 'base_request.mtf_snapshot_manifest.streams.M5.sha256');
        if (! $this->sha($sha) || ($receipt['physical_source_rows'] ?? null) !== 15512
            || ($receipt['execution_input_rows'] ?? null) !== 512 + $rows
            || ($physical['protocol'] ?? null) !== 'consumed_dataset_attestation_v1' || ($physical['status'] ?? null) !== 'verified'
            || ($physical['source_rows'] ?? null) !== 15512 || ($physical['consumed_rows'] ?? null) !== 15512
            || ($physical['actual_source_sha256'] ?? null) !== $sha || ($physical['stream'] ?? null) !== 'M5'
            || ($physical['execution_timeframe'] ?? null) !== 'M5'
            || ($physical['dataset_identity'] ?? null) !== data_get($contract, 'identity.dataset_hash')) {
            $this->refuse('RECEIPT_FULL_PHYSICAL_SOURCE_REQUIRED');
        }
        $clock = $receipt['replay_executed_clock'] ?? [];
        $scope = $receipt['evaluated_scope'] ?? [];
        $probe = data_get($seal, 'base_request.policy_context.prospective_probe_window', []);
        foreach (['protocol' => 'replay_executed_clock_v1', 'owner' => 'native_specialist_council_v1',
            'semantics' => 'previous_closed_candle_next_open_v1', 'index_basis' => 'evaluated_frame_zero_based_v1',
            'input_rows' => 512 + $rows, 'evaluation_offset_rows' => 512, 'execution_timeframe' => 'M5', 'duration_seconds' => 300,
            'dataset_hash' => data_get($contract, 'identity.dataset_hash'), 'execution_hash' => data_get($contract, 'identity.execution_hash'),
            'policy_hash' => $this->hash($view), 'probe_contract_hash' => $probe['contract_hash'] ?? null, 'complete' => true,
            'decision_rows' => $rows - 1, 'first_evaluation_index' => 1, 'last_evaluation_index' => $rows - 1,
            'promotion_evidence' => false] as $field => $expected) {
            if (($clock[$field] ?? null) !== $expected) $this->refuse('RECEIPT_CLOCK_IDENTITY_INVALID');
        }
        if (($probe['loaded_rows'] ?? null) !== 15512 || ($probe['warmup_rows'] ?? null) !== 512 || ($probe['evaluated_rows'] ?? null) !== 15000
            || ($scope['rows'] ?? null) !== $rows || ($scope['decision_rows'] ?? null) !== $rows - 1
            || ($scope['warmup_rows'] ?? null) !== 512 || ($scope['policy_hash'] ?? null) !== $this->hash($view)) {
            $this->refuse('RECEIPT_PHYSICAL_PROBE_OR_VIEW_SCOPE_INVALID');
        }
        $clockBody = array_diff_key($clock, ['receipt_hash' => true, 'receipt_json' => true]);
        try { $clockCopy = json_decode($clock['receipt_json'] ?? '', true, 512, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { $this->refuse('RECEIPT_CLOCK_SEAL_INVALID'); }
        if (! is_array($clockCopy) || ($clock['receipt_hash'] ?? null) !== $this->hash($clockBody)
            || $this->hash($clockCopy) !== $this->hash($clockBody) || ! $this->sha($clock['schedule_hash'] ?? null)) {
            $this->refuse('RECEIPT_CLOCK_SEAL_INVALID');
        }
        $indices = hash_init('sha256'); hash_update($indices, "replay-executed-clock-v1:indices\n");
        for ($index = 1; $index < $rows; $index++) hash_update($indices, $index."\n");
        if (($clock['index_set_hash'] ?? null) !== hash_final($indices)) $this->refuse('RECEIPT_CLOCK_INDEX_DIGEST_INVALID');
        $signalStart = $this->time($clock['signal_start'] ?? null);
        $signalEnd = $this->time($clock['signal_end'] ?? null);
        $executionStart = $this->time($clock['execution_start'] ?? null);
        $executionEnd = $this->time($clock['execution_end'] ?? null);
        if ($signalEnd->lessThan($signalStart) || $executionEnd->lessThan($executionStart)
            || $executionStart->lessThan($signalStart->addSeconds(300)) || $executionEnd->lessThan($signalEnd->addSeconds(300))
            || ! $this->time($scope['start_inclusive'] ?? null)->equalTo($signalStart)
            || ! $this->time($scope['end_exclusive'] ?? null, true)->equalTo($executionEnd->addSeconds(300))
            || ! $this->time($probe['evaluated_start'] ?? null)->equalTo($signalStart)
            || $executionEnd->greaterThan($this->time($probe['evaluated_end'] ?? null))) {
            $this->refuse('RECEIPT_CLOCK_CALENDAR_INVALID');
        }
        $sourceRows = $this->physicalPrefix($seal, $sha, 512 + $rows);
        if ($sourceRows !== null) $this->assertPhysicalClock($clock, $sourceRows, $rows);

        $nativeMembers = $seal['native_members'] ?? [];
        if (! is_array($nativeMembers) || ! array_is_list($nativeMembers) || count($nativeMembers) !== 4) $this->refuse('RECEIPT_NATIVE_MEMBER_SET_INVALID');
        $members = []; $roles = [];
        foreach ($nativeMembers as $ordinal => $member) {
            $id = $member['specialist_id'] ?? null; $role = $member['role'] ?? null;
            if (! is_string($id) || $id === '' || isset($members[$id]) || ! in_array($role, ['scalp', 'hour', 'day', 'swing'], true) || isset($roles[$role])) {
                $this->refuse('RECEIPT_NATIVE_MEMBER_SET_INVALID');
            }
            $roles[$role] = true;
            $members[$id] = ['role' => $role, 'ordinal' => $ordinal, 'hash' => $this->hash([
                'council_version' => data_get($seal, 'base_request.specialist_council_contract.council_version'), 'member' => $member])];
        }
        $pool = $receipt['pool'] ?? []; $events = $receipt['events'] ?? [];
        if (! is_array($pool) || ! array_is_list($pool) || count($pool) !== 4 || ! is_array($events) || ! array_is_list($events)
            || count($events) > 4 * ($rows - 1) || ($receipt['events_hash'] ?? null) !== $this->hash($events)) {
            $this->refuse('RECEIPT_EVENT_LEDGER_INVALID');
        }
        $poolById = []; $derived = [];
        foreach ($pool as $entry) {
            $id = $entry['specialist_id'] ?? null;
            if (! is_string($id) || ! isset($members[$id]) || isset($poolById[$id]) || ($entry['role'] ?? null) !== $members[$id]['role']
                || ($entry['member_version_hash'] ?? null) !== $members[$id]['hash'] || ! is_bool($entry['observed'] ?? null)) {
                $this->refuse('RECEIPT_POOL_MEMBER_INVALID');
            }
            $poolById[$id] = $entry;
            $derived[$id] = array_fill_keys(array_slice(self::COUNTS, 1), 0);
        }
        $seen = []; $memberIndices = []; $lastOrder = [0, -1];
        $provenance = data_get($seal, 'base_request.mtf_snapshot_manifest.quote_spread_provenance', []);
        $provenanceHash = $this->hash($provenance);
        if ($provenanceHash !== data_get($contract, 'identity.quote_provenance_hash')) $this->refuse('RECEIPT_ORIGINAL_QUOTE_PROVENANCE_INVALID');
        foreach ($events as $event) {
            if (! is_array($event) || ! $this->keys($event, self::EVENT_KEYS)) $this->refuse('RECEIPT_EVENT_SHAPE_INVALID');
            $id = $event['specialist_id']; $index = $event['evaluation_index']; $physicalIndex = $event['execution_index'];
            if (! is_string($id) || ! isset($members[$id]) || ! $poolById[$id]['observed'] || $event['role'] !== $members[$id]['role']
                || $event['member_version_hash'] !== $members[$id]['hash'] || ! is_int($index) || $index < 1 || $index >= $rows
                || ! is_int($physicalIndex) || $physicalIndex !== 512 + $index || ! in_array($event['direction'], ['BUY', 'SELL'], true)
                || ! is_bool($event['gate_reached']) || ! $this->sha($event['closed_input_hash'])
                || ! $this->sha($event['event_id'])
                || ! in_array($event['raw_signal_source'], self::RAW_PORTS, true)) {
                $this->refuse('RECEIPT_EVENT_MEMBER_OR_INDEX_INVALID');
            }
            $order = [$index, $members[$id]['ordinal']];
            if ($order <= $lastOrder || isset($memberIndices[$id][$index]) || isset($seen[$event['event_id']])) $this->refuse('RECEIPT_DUPLICATE_OR_UNORDERED_EVENT');
            $lastOrder = $order; $memberIndices[$id][$index] = true; $seen[$event['event_id']] = true;
            $context = $event['source_context'];
            if (! is_array($context) || ! $this->keys($context, self::CONTEXT_KEYS) || $context['direction'] !== $event['direction']
                || $this->hash($context) !== $this->hash($seal['declaration']['contexts'][$members[$id]['role']] ?? [])
                || $event['source_context_hash'] !== $this->hash($context)
                || $event['raw_signal_hash'] !== $this->hash(['source_key' => $event['raw_signal_source'], 'value' => $event['direction']])) {
                $this->refuse('RECEIPT_EVENT_CONTEXT_OR_RAW_PORT_INVALID');
            }
            $signal = $this->time($event['signal_time']); $execution = $this->time($event['execution_time']);
            if ($signal->lessThan($signalStart) || $signal->greaterThan($signalEnd) || $execution->lessThan($executionStart)
                || $execution->greaterThan($executionEnd) || $execution->lessThan($signal->addSeconds(300))
                || ($index === 1 && (! $signal->equalTo($signalStart) || ! $execution->equalTo($executionStart)))
                || ($index === $rows - 1 && (! $signal->equalTo($signalEnd) || ! $execution->equalTo($executionEnd)))) {
                $this->refuse('RECEIPT_EVENT_CALENDAR_INVALID');
            }
            $eventIdentity = array_intersect_key($event, array_flip(['member_version_hash', 'evaluation_index', 'execution_index', 'signal_time', 'execution_time', 'direction']));
            if (! $this->sha($event['event_id']) || $event['event_id'] !== $this->hash($eventIdentity)) $this->refuse('RECEIPT_EVENT_IDENTITY_INVALID');
            if ($sourceRows !== null && (! $signal->equalTo($this->sourceTime($sourceRows[$physicalIndex - 1]['time']))
                || ! $execution->equalTo($this->sourceTime($sourceRows[$physicalIndex]['time'])))) $this->refuse('RECEIPT_EVENT_PHYSICAL_PRIOR_ROW_INVALID');
            $quote = $event['source_quote'];
            if (! is_array($quote) || ! $this->keys($quote, self::QUOTE_KEYS) || ! is_bool($quote['available'])
                || $event['source_quote_hash'] !== $this->hash($quote) || $quote['provenance_hash'] !== $provenanceHash
                || $quote['source_sha256'] !== $sha) $this->refuse('RECEIPT_EVENT_QUOTE_IDENTITY_INVALID');
            foreach (['bid', 'ask', 'spread', 'age_ms'] as $field) if ($quote[$field] !== null && ! $this->number($quote[$field])) $this->refuse('RECEIPT_QUOTE_VALUE_INVALID');
            if ($quote['available']) $this->assertKnownQuote($quote, $signal, $execution, $provenance,
                $sourceRows[$physicalIndex - 1] ?? null);
            $derived[$id]['matching_context_opportunities']++;
            $derived[$id][$quote['available'] ? 'observed_quote_opportunities' : 'missing_quote_opportunities']++;
            $derived[$id]['gate_reached_opportunities'] += (int) $event['gate_reached'];
        }
        foreach ($poolById as $id => $entry) {
            $counts = $entry['counts'] ?? [];
            if (! is_array($counts) || ! $this->keys($counts, self::COUNTS)) $this->refuse('RECEIPT_POOL_COUNTS_INVALID');
            foreach (self::COUNTS as $key) if (! is_int($counts[$key]) || $counts[$key] < 0 || $counts[$key] >= $rows) $this->refuse('RECEIPT_POOL_COUNTS_INVALID');
            foreach ($derived[$id] as $key => $value) if ($counts[$key] !== $value) $this->refuse('RECEIPT_EVENT_DERIVED_COUNTS_MISMATCH');
            if ($counts['raw_opportunities'] < $counts['matching_context_opportunities'] || (! $entry['observed'] && $counts['raw_opportunities'] !== 0)) {
                $this->refuse('RECEIPT_RAW_OPPORTUNITY_BOUND_INVALID');
            }
            if ($counts['missing_quote_opportunities'] > 0 && ($entry['status'] ?? null) !== 'dependency') $this->refuse('RECEIPT_UNKNOWN_QUOTE_CANNOT_BE_RESULT');
        }
    }

    private function assertKnownQuote(array $quote, CarbonImmutable $signal, CarbonImmutable $execution, array $provenance, ?array $row): void
    {
        foreach (['bid', 'ask', 'spread', 'age_ms'] as $field) if (! $this->number($quote[$field])) $this->refuse('RECEIPT_KNOWN_QUOTE_FINITE_VALUES_REQUIRED');
        $time = $this->time($quote['quote_time']); $available = $this->time($quote['available_at']); $close = $signal->addSeconds(300);
        $age = ((float) $close->format('U.u') - (float) $time->format('U.u')) * 1000;
        if (($provenance['protocol'] ?? null) !== 'historical_quote_spread_snapshot_v1'
            || ($provenance['provider'] ?? null) !== 'dukascopy_historical_synchronized_tick_v1'
            || ($provenance['maximum_quote_age_ms'] ?? null) !== 60000 || ($provenance['paper_2026_included'] ?? null) !== false
            || ($provenance['promotion_evidence'] ?? null) !== false || empty($provenance['sources'])
            || $time->lessThan($signal) || ! $time->lessThan($close) || ! $available->equalTo($close) || $available->greaterThan($execution)
            || $quote['age_ms'] <= 0 || $quote['age_ms'] > 60000 || abs($age - $quote['age_ms']) > .001
            || $quote['bid'] <= 0 || $quote['ask'] < $quote['bid'] || $quote['spread'] < 0
            || abs($quote['spread'] - ($quote['ask'] - $quote['bid'])) > .000001) $this->refuse('RECEIPT_KNOWN_QUOTE_PHYSICS_OR_ASOF_INVALID');
        if ($row === null) return;
        if (! in_array($row['spread_available'] ?? null, ['True', 'true', '1', '1.0'], true)
            || ! is_numeric($row['close'] ?? null) || abs((float) $row['close'] - $quote['bid']) > .000001) $this->refuse('RECEIPT_KNOWN_QUOTE_SOURCE_ROW_INVALID');
        foreach (['bid' => 'bid_close', 'ask' => 'ask_close', 'spread' => 'spread', 'age_ms' => 'quote_age_ms'] as $field => $column) {
            if (! is_numeric($row[$column] ?? null) || abs((float) $row[$column] - $quote[$field]) > .000001) $this->refuse('RECEIPT_KNOWN_QUOTE_SOURCE_ROW_INVALID');
        }
        if (! $time->equalTo($this->sourceTime($row['quote_time_utc'] ?? null))
            || ! $available->equalTo($this->sourceTime($row['quote_available_after_utc'] ?? null))) $this->refuse('RECEIPT_KNOWN_QUOTE_SOURCE_ROW_INVALID');
    }

    /** Original physical bytes are rechecked; only the declared execution prefix is parsed. */
    private function physicalPrefix(array $seal, string $sha, int $limit): ?array
    {
        $path = data_get($seal, 'base_request.dataset_path', data_get($seal, 'base_request.mtf_snapshot_manifest.streams.M5.path'));
        if ($path === null) return null; // Pure routing fixtures do not claim physical CSV verification.
        if (! is_string($path) || ! is_file($path) || hash_file('sha256', $path) !== $sha) $this->refuse('RECEIPT_ORIGINAL_PHYSICAL_SOURCE_BYTES_INVALID');
        $handle = fopen($path, 'rb');
        if ($handle === false) $this->refuse('RECEIPT_ORIGINAL_PHYSICAL_SOURCE_BYTES_INVALID');
        try {
            $columns = fgetcsv($handle);
            if (! is_array($columns) || ! in_array('time', $columns, true) || count(array_unique($columns)) !== count($columns)) $this->refuse('RECEIPT_PHYSICAL_SOURCE_HEADER_INVALID');
            $rows = [];
            while (count($rows) < $limit && ($values = fgetcsv($handle)) !== false) {
                if (count($values) !== count($columns)) $this->refuse('RECEIPT_PHYSICAL_SOURCE_ROW_INVALID');
                $row = array_combine($columns, $values); $time = $this->sourceTime($row['time']);
                if ($rows !== [] && ! $time->greaterThan($this->sourceTime($rows[count($rows) - 1]['time']))) $this->refuse('RECEIPT_PHYSICAL_SOURCE_CALENDAR_INVALID');
                $rows[] = $row;
            }
            if (count($rows) !== $limit) $this->refuse('RECEIPT_PHYSICAL_SOURCE_PREFIX_INCOMPLETE');
            return $rows;
        } finally { fclose($handle); }
    }

    private function assertPhysicalClock(array $clock, array $source, int $rows): void
    {
        $schedule = hash_init('sha256'); hash_update($schedule, "replay-executed-clock-v1:schedule\n");
        for ($relative = 1; $relative < $rows; $relative++) {
            $index = 512 + $relative;
            $signal = $this->sourceTime($source[$index - 1]['time']); $execution = $this->sourceTime($source[$index]['time']);
            if ($execution->lessThan($signal->addSeconds(300))) $this->refuse('RECEIPT_PHYSICAL_SOURCE_CLOCK_INVALID');
            hash_update($schedule, json_encode([$relative, $this->iso($signal), $this->iso($execution)], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        }
        if (($clock['schedule_hash'] ?? null) !== hash_final($schedule)
            || ! $this->time($clock['signal_start'])->equalTo($this->sourceTime($source[512]['time']))
            || ! $this->time($clock['signal_end'])->equalTo($this->sourceTime($source[512 + $rows - 2]['time']))
            || ! $this->time($clock['execution_start'])->equalTo($this->sourceTime($source[513]['time']))
            || ! $this->time($clock['execution_end'])->equalTo($this->sourceTime($source[512 + $rows - 1]['time']))) $this->refuse('RECEIPT_PHYSICAL_CLOCK_SCHEDULE_MISMATCH');
    }

    private function time(mixed $value, bool $allowCutoff = false): CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|\+00:00)$/D', $value)) $this->refuse('RECEIPT_UTC_TIME_REQUIRED');
        try { $time = CarbonImmutable::parse($value)->utc(); }
        catch (\Throwable) { $this->refuse('RECEIPT_UTC_TIME_REQUIRED'); }
        if ($time->format('Y-m-d\TH:i:s') !== substr($value, 0, 19)) $this->refuse('RECEIPT_UTC_TIME_REQUIRED');
        $cutoff = CarbonImmutable::parse('2026-01-01T00:00:00Z');
        if ($allowCutoff ? $time->greaterThan($cutoff) : ! $time->lessThan($cutoff)) $this->refuse('RECEIPT_PAPER_DATA_FORBIDDEN');
        return $time;
    }
    private function sourceTime(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|\+00:00)?$/D', $value)) $this->refuse('RECEIPT_PHYSICAL_SOURCE_TIME_INVALID');
        $normalized = str_replace(' ', 'T', $value);
        if (! preg_match('/(?:Z|\+00:00)$/D', $normalized)) $normalized .= '+00:00';
        return $this->time($normalized);
    }
    private function iso(CarbonImmutable $time): string { return $time->format($time->micro === 0 ? 'Y-m-d\TH:i:sP' : 'Y-m-d\TH:i:s.uP'); }
    private function number(mixed $value): bool { return (is_int($value) || is_float($value)) && is_finite((float) $value); }
    private function sha(mixed $value): bool { return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1; }
    private function keys(array $value, array $keys): bool { $actual = array_keys($value); sort($actual); sort($keys); return $actual === $keys; }
    private function hash(array $value): string { return $this->epochs->parameterHash($value); }
    private function refuse(string $code): never { throw new RuntimeException('NATIVE_DEPTH_AUDIT_'.$code); }
}
