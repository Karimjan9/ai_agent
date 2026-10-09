<?php

namespace Tests\Unit;

use App\Services\NativeReachabilityDepthReceiptValidatorService;
use App\Services\ResearchPaperEpochContractService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Synthetic pure ABI fixtures only; these are not original scientific/native producer receipts. */
class NativeReachabilityDepthReceiptValidatorTest extends TestCase
{
    public function test_physical_inventory_and_actual_execution_view_are_distinct_and_matching_counts_are_derived(): void
    {
        [$receipt, $seal, $contract] = $this->fixture();
        // Raw opportunities outside the matching context have no emitted event.
        foreach ($receipt['pool'] as &$entry) $entry['counts']['raw_opportunities'] = 7;
        unset($entry);
        $this->validator()->validate($receipt, $seal, $contract);
        $this->assertSame(15512, $receipt['physical_source_rows']);
        $this->assertSame(520, $receipt['execution_input_rows']);
    }

    #[DataProvider('poisonedFields')]
    public function test_changed_clock_identity_event_semantics_quote_physics_or_recount_is_refused(string $poison): void
    {
        [$receipt, $seal, $contract] = $this->fixture();
        switch ($poison) {
            case 'physical_rows': $receipt['physical_source_rows'] = 520; break;
            case 'consumed_rows': $receipt['source_attestation']['consumed_rows'] = 520; break;
            case 'source_sha': $receipt['source_attestation']['actual_source_sha256'] = str_repeat('b', 64); break;
            case 'execution_rows': $receipt['execution_input_rows'] = 15512; break;
            case 'clock_rows': $receipt['replay_executed_clock']['input_rows'] = 15512; break;
            case 'clock_offset': $receipt['replay_executed_clock']['evaluation_offset_rows'] = 0; break;
            case 'clock_indices': $receipt['replay_executed_clock']['index_set_hash'] = str_repeat('b', 64); break;
            case 'clock_policy': $receipt['replay_executed_clock']['policy_hash'] = $this->hash($seal['base_request']['policy_context']['prospective_probe_window']); break;
            case 'duplicate_event': array_splice($receipt['events'], 1, 0, [$receipt['events'][0]]); break;
            case 'event_order': $receipt['events'] = array_reverse($receipt['events']); break;
            case 'wrong_prior_index': $receipt['events'][0]['execution_index'] = 512; break;
            case 'outside_view': $receipt['events'][0]['evaluation_index'] = 8; break;
            case 'foreign_member': $receipt['events'][0]['specialist_id'] = 'unknown-member'; break;
            case 'wrong_role': $receipt['events'][0]['role'] = 'swing'; break;
            case 'wrong_member_hash': $receipt['events'][0]['member_version_hash'] = str_repeat('b', 64); break;
            case 'wrong_context': $receipt['events'][0]['source_context']['session'] = 'asia'; break;
            case 'wrong_raw_port': $receipt['events'][0]['raw_signal_source'] = 'caller_vector'; break;
            case 'wrong_raw_hash': $receipt['events'][0]['raw_signal_hash'] = str_repeat('b', 64); break;
            case 'event_id': $receipt['events'][0]['event_id'] = str_repeat('b', 64); break;
            case 'quote_source': $receipt['events'][0]['source_quote']['source_sha256'] = str_repeat('b', 64); break;
            case 'quote_provenance': $receipt['events'][0]['source_quote']['provenance_hash'] = str_repeat('b', 64); break;
            case 'crossed_bid_ask': $receipt['events'][0]['source_quote']['ask'] = 99.0; break;
            case 'wrong_spread': $receipt['events'][0]['source_quote']['spread'] = .2; break;
            case 'future_quote': $receipt['events'][0]['source_quote']['quote_time'] = '2025-01-06T08:05:01+00:00'; break;
            case 'wrong_close_availability': $receipt['events'][0]['source_quote']['available_at'] = '2025-01-06T08:06:00+00:00'; break;
            case 'stale_quote': $receipt['events'][0]['source_quote']['age_ms'] = 60001.0; break;
            case 'wrong_age': $receipt['events'][0]['source_quote']['age_ms'] = 30001.0; break;
            case 'matching_count': $receipt['pool'][0]['counts']['matching_context_opportunities'] = 1; break;
            case 'known_quote_count': $receipt['pool'][0]['counts']['observed_quote_opportunities'] = 1; break;
            case 'gate_count': $receipt['pool'][0]['counts']['gate_reached_opportunities'] = 2; break;
            case 'raw_under_matching': $receipt['pool'][0]['counts']['raw_opportunities'] = 1; break;
        }
        // Rehash mutable copies to prove structural hashes alone do not admit
        // an impossible actual source/clock/event ledger.
        foreach ($receipt['events'] as &$event) {
            $event['source_context_hash'] = $this->hash($event['source_context']);
            $event['source_quote_hash'] = $this->hash($event['source_quote']);
            if (! in_array($poison, ['event_id'], true)) $event['event_id'] = $this->hash(array_intersect_key($event,
                array_flip(['member_version_hash', 'evaluation_index', 'execution_index', 'signal_time', 'execution_time', 'direction'])));
        }
        unset($event);
        $receipt['events_hash'] = $this->hash($receipt['events']);
        $receipt['replay_executed_clock'] = $this->sealClock($receipt['replay_executed_clock']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('NATIVE_DEPTH_AUDIT_');
        $this->validator()->validate($receipt, $seal, $contract);
    }

    public static function poisonedFields(): array
    {
        return array_map(fn ($value) => [$value], ['physical_rows', 'consumed_rows', 'source_sha', 'execution_rows',
            'clock_rows', 'clock_offset', 'clock_indices', 'clock_policy', 'duplicate_event', 'event_order', 'wrong_prior_index',
            'outside_view', 'foreign_member', 'wrong_role', 'wrong_member_hash', 'wrong_context', 'wrong_raw_port', 'wrong_raw_hash',
            'event_id', 'quote_source', 'quote_provenance', 'crossed_bid_ask', 'wrong_spread', 'future_quote',
            'wrong_close_availability', 'stale_quote', 'wrong_age', 'matching_count', 'known_quote_count', 'gate_count', 'raw_under_matching']);
    }

    public function test_unavailable_quotes_are_preserved_as_dependencies_even_if_actual_gate_was_reached(): void
    {
        [$receipt, $seal, $contract] = $this->fixture();
        foreach ($receipt['events'] as &$event) {
            $event['source_quote']['available'] = false;
            $event['source_quote']['spread'] = null;
            $event['source_quote_hash'] = $this->hash($event['source_quote']);
        }
        unset($event);
        foreach ($receipt['pool'] as &$entry) {
            $entry['status'] = 'dependency';
            $entry['counts']['observed_quote_opportunities'] = 0;
            $entry['counts']['missing_quote_opportunities'] = 2;
        }
        unset($entry);
        $receipt['events_hash'] = $this->hash($receipt['events']);
        $this->validator()->validate($receipt, $seal, $contract);
        $receipt['pool'][0]['status'] = 'reached';
        $this->expectExceptionMessage('NATIVE_DEPTH_AUDIT_RECEIPT_UNKNOWN_QUOTE_CANNOT_BE_RESULT');
        $this->validator()->validate($receipt, $seal, $contract);
    }

    public function test_original_csv_prefix_binds_clock_schedule_and_event_to_execution_index_minus_one(): void
    {
        [$receipt, $seal, $contract] = $this->fixture();
        $path = tempnam(sys_get_temp_dir(), 'native-depth-pure-csv-');
        $handle = fopen($path, 'wb');
        try {
            fputcsv($handle, ['time', 'close', 'bid_close', 'ask_close', 'spread', 'spread_available', 'quote_age_ms', 'quote_time_utc', 'quote_available_after_utc']);
            $start = CarbonImmutable::parse('2025-01-06T08:00:00Z')->subMinutes(512 * 5);
            for ($index = 0; $index < 15512; $index++) {
                $time = $start->addMinutes($index * 5);
                fputcsv($handle, [$time->format('Y-m-d H:i:sP'), 100.0, 100.0, 100.1, .1, 'True', 30000.0,
                    $time->addSeconds(270)->format('Y-m-d H:i:sP'), $time->addSeconds(300)->format('Y-m-d H:i:sP')]);
            }
            fclose($handle); $handle = null;
            $sha = hash_file('sha256', $path);
            $seal['base_request']['dataset_path'] = $path;
            $seal['base_request']['mtf_snapshot_manifest']['streams']['M5']['sha256'] = $sha;
            $receipt['source_attestation']['actual_source_sha256'] = $sha;
            foreach ($receipt['events'] as &$event) {
                $event['source_quote']['source_sha256'] = $sha;
                $event['source_quote_hash'] = $this->hash($event['source_quote']);
            }
            unset($event);
            $receipt['events_hash'] = $this->hash($receipt['events']);
            $this->validator()->validate($receipt, $seal, $contract);
            // Consistent-looking quote geometry must still match the actual
            // original CSV price at execution_index-1, not a later row/value.
            $receipt['events'][0]['source_quote']['bid'] = 101.0;
            $receipt['events'][0]['source_quote']['ask'] = 101.1;
            $receipt['events'][0]['source_quote_hash'] = $this->hash($receipt['events'][0]['source_quote']);
            $receipt['events_hash'] = $this->hash($receipt['events']);
            $this->expectExceptionMessage('NATIVE_DEPTH_AUDIT_RECEIPT_KNOWN_QUOTE_SOURCE_ROW_INVALID');
            $this->validator()->validate($receipt, $seal, $contract);
        } finally {
            if (is_resource($handle)) fclose($handle);
            unlink($path);
        }
    }

    private function fixture(): array
    {
        $roles = ['scalp', 'hour', 'day', 'swing']; $members = []; $contexts = [];
        $context = ['regime' => 'trend_up', 'volatility' => 'normal', 'session' => 'london', 'venue_phase' => 'london_pre_am_fix', 'direction' => 'BUY'];
        foreach ($roles as $role) { $members[] = ['specialist_id' => $role, 'role' => $role, 'fixture_only' => true]; $contexts[$role] = $context; }
        $probe = ['contract_hash' => str_repeat('f', 64), 'loaded_rows' => 15512, 'warmup_rows' => 512, 'evaluated_rows' => 15000,
            'evaluated_start' => '2025-01-06T08:00:00Z', 'evaluated_end' => '2025-03-01T00:00:00Z'];
        $provenance = ['protocol' => 'historical_quote_spread_snapshot_v1', 'provider' => 'dukascopy_historical_synchronized_tick_v1',
            'maximum_quote_age_ms' => 60000, 'paper_2026_included' => false, 'promotion_evidence' => false,
            'sources' => [['fixture_only_not_provider_evidence' => true]]];
        $view = ['protocol' => 'native_reachability_execution_view_v1', 'selection' => 'prefix', 'evaluated_rows' => 8,
            'decision_rows' => 7, 'warmup_rows' => 512, 'source_evaluated_rows' => 15000, 'source_loaded_rows' => 15512];
        $contract = ['execution_view' => $view, 'identity' => ['dataset_hash' => str_repeat('d', 64), 'execution_hash' => str_repeat('e', 64),
            'quote_provenance_hash' => $this->hash($provenance)]];
        $seal = ['native_members' => $members, 'declaration' => ['contexts' => $contexts], 'base_request' => [
            'specialist_council_contract' => ['council_version' => 'pure-abi-fixture'], 'policy_context' => ['prospective_probe_window' => $probe],
            'mtf_snapshot_manifest' => ['streams' => ['M5' => ['sha256' => str_repeat('a', 64)]], 'quote_spread_provenance' => $provenance]]];
        $start = CarbonImmutable::parse($probe['evaluated_start']);
        $indices = "replay-executed-clock-v1:indices\n"; $schedule = "replay-executed-clock-v1:schedule\n";
        for ($index = 1; $index < 8; $index++) {
            $indices .= $index."\n";
            $schedule .= json_encode([$index, $start->addMinutes(($index - 1) * 5)->toIso8601String(), $start->addMinutes($index * 5)->toIso8601String()], JSON_UNESCAPED_SLASHES)."\n";
        }
        $clock = $this->sealClock(['protocol' => 'replay_executed_clock_v1', 'owner' => 'native_specialist_council_v1',
            'semantics' => 'previous_closed_candle_next_open_v1', 'index_basis' => 'evaluated_frame_zero_based_v1', 'input_rows' => 520,
            'evaluation_offset_rows' => 512, 'execution_timeframe' => 'M5', 'duration_seconds' => 300,
            'dataset_hash' => $contract['identity']['dataset_hash'], 'execution_hash' => $contract['identity']['execution_hash'],
            'policy_hash' => $this->hash($view), 'probe_contract_hash' => $probe['contract_hash'], 'complete' => true, 'decision_rows' => 7,
            'first_evaluation_index' => 1, 'last_evaluation_index' => 7, 'signal_start' => $start->toIso8601String(),
            'signal_end' => $start->addMinutes(30)->toIso8601String(), 'execution_start' => $start->addMinutes(5)->toIso8601String(),
            'execution_end' => $start->addMinutes(35)->toIso8601String(), 'index_set_hash' => hash('sha256', $indices),
            'schedule_hash' => hash('sha256', $schedule), 'promotion_evidence' => false]);
        $events = []; $pool = [];
        foreach ([1, 3] as $index) foreach ($members as $member) {
            $signal = $start->addMinutes(($index - 1) * 5);
            $identity = ['member_version_hash' => $this->hash(['council_version' => 'pure-abi-fixture', 'member' => $member]),
                'evaluation_index' => $index, 'execution_index' => 512 + $index, 'signal_time' => $signal->toIso8601String(),
                'execution_time' => $signal->addMinutes(5)->toIso8601String(), 'direction' => 'BUY'];
            $quote = ['available' => true, 'bid' => 100.0, 'ask' => 100.1, 'spread' => .1, 'age_ms' => 30000.0,
                'quote_time' => $signal->addSeconds(270)->toIso8601String(), 'available_at' => $signal->addSeconds(300)->toIso8601String(),
                'provenance_hash' => $this->hash($provenance), 'source_sha256' => str_repeat('a', 64)];
            $events[] = [...$identity, 'event_id' => $this->hash($identity), 'specialist_id' => $member['specialist_id'], 'role' => $member['role'],
                'source_context' => $context, 'source_context_hash' => $this->hash($context), 'raw_signal_source' => 'signal',
                'raw_signal_hash' => $this->hash(['source_key' => 'signal', 'value' => 'BUY']), 'source_quote' => $quote,
                'source_quote_hash' => $this->hash($quote), 'closed_input_hash' => str_repeat('c', 64), 'gate_reached' => $index === 1];
        }
        foreach ($members as $member) $pool[] = ['specialist_id' => $member['specialist_id'], 'role' => $member['role'], 'observed' => true,
            'member_version_hash' => $this->hash(['council_version' => 'pure-abi-fixture', 'member' => $member]), 'status' => 'reached',
            'counts' => ['raw_opportunities' => 3, 'matching_context_opportunities' => 2, 'observed_quote_opportunities' => 2,
                'missing_quote_opportunities' => 0, 'gate_reached_opportunities' => 1]];
        $receipt = ['execution_view' => $view, 'physical_source_rows' => 15512, 'execution_input_rows' => 520,
            'source_attestation' => ['protocol' => 'consumed_dataset_attestation_v1', 'status' => 'verified', 'source_rows' => 15512,
                'consumed_rows' => 15512, 'actual_source_sha256' => str_repeat('a', 64), 'stream' => 'M5', 'execution_timeframe' => 'M5',
                'dataset_identity' => $contract['identity']['dataset_hash']], 'replay_executed_clock' => $clock,
            'evaluated_scope' => ['rows' => 8, 'decision_rows' => 7, 'warmup_rows' => 512, 'policy_hash' => $this->hash($view),
                'start_inclusive' => $start->toIso8601String(), 'end_exclusive' => $start->addMinutes(40)->toIso8601String()],
            'pool' => $pool, 'events' => $events, 'events_hash' => $this->hash($events)];
        return [$receipt, $seal, $contract];
    }

    private function validator(): NativeReachabilityDepthReceiptValidatorService { return new NativeReachabilityDepthReceiptValidatorService(new ResearchPaperEpochContractService); }
    #[DataProvider('observerTracePoisons')]
    public function test_observer_closed_input_is_bound_to_exact_native_trace_member_and_index(?string $poison): void
    {
        [$receipt] = $this->fixture(); $indexed = [];
        foreach ($receipt['events'] as $event) {
            $index = $event['execution_index'];
            $indexed[$index] ??= ['candle_index' => $index, 'signal_time' => $event['signal_time'], 'execution_time' => $event['execution_time'],
                'source_clock' => ['signal_time' => $event['signal_time'], 'execution_time' => $event['execution_time']], 'member_decisions' => []];
            $indexed[$index]['member_decisions'][] = ['specialist_id' => $event['specialist_id'],
                'member_version_hash' => $event['member_version_hash'], 'closed_inputs_hash' => $event['closed_input_hash']];
        }
        $trace = array_values($indexed);
        switch ($poison) {
            case 'member_hash': $trace[0]['member_decisions'][0]['member_version_hash'] = str_repeat('b', 64); break;
            case 'closed_inputs': $trace[0]['member_decisions'][0]['closed_inputs_hash'] = str_repeat('b', 64); break;
            case 'index': $trace[0]['candle_index']--; break;
            case 'time': $trace[0]['source_clock']['execution_time'] = '2025-01-06T00:00:00Z'; break;
            case 'duplicate_member': $trace[0]['member_decisions'][] = $trace[0]['member_decisions'][0]; break;
            case 'duplicate_index': $trace[] = $trace[0]; break;
        }
        $class = new \ReflectionClass(\App\Services\NativeReachabilityDepthAuditService::class);
        $owner = $class->newInstanceWithoutConstructor(); $method = $class->getMethod('assertObserverTraceJoin');
        if ($poison !== null) $this->expectException(\LogicException::class);
        $method->invoke($owner, $receipt, ['decision_trace' => $trace]);
        if ($poison === null) $this->assertTrue(true);
    }

    public static function observerTracePoisons(): array
    {
        return [[null], ['member_hash'], ['closed_inputs'], ['index'], ['time'], ['duplicate_member'], ['duplicate_index']];
    }

    private function hash(array $value): string { return (new ResearchPaperEpochContractService)->parameterHash($value); }
    private function sealClock(array $clock): array
    {
        $body = array_diff_key($clock, ['receipt_hash' => true, 'receipt_json' => true]);
        return [...$body, 'receipt_hash' => $this->hash($body), 'receipt_json' => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)];
    }
}
