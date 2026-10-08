<?php

namespace Tests\Feature;

use App\Services\ProspectiveRepairProbeWindowService;
use App\Services\ResearchPaperEpochContractService;
use App\Services\SpecialistCouncilLifecycleService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Synthetic guard facts only; no market/qualification/credit evidence is created. */
class SpecialistCouncilExecutedClockTest extends TestCase
{
    private function seal(array $body): array
    {
        unset($body['receipt_hash'], $body['receipt_json']);
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        return [...$body, 'receipt_hash' => hash('sha256', $json), 'receipt_json' => $json];
    }

    private function fixture(string $owner = 'ordinary_single_position_v1', int $offset = 0): array
    {
        $start = CarbonImmutable::parse('2025-01-06T00:00:00Z');
        $rows = [];
        for ($index = 0; $index < 8; $index++) $rows[] = ['time' => $start->addMinutes(5 * $index)->toIso8601String()];
        $probe = app(ProspectiveRepairProbeWindowService::class)->seal($rows, str_repeat('d', 64),
            str_repeat('e', 64), 'executed-clock-fixture', 5, 3);
        $scope = ['start_inclusive' => $rows[3]['time'], 'end_exclusive' => $start->addMinutes(40)->toIso8601String(),
            'rows' => 5, 'decision_rows' => 4, 'warmup_rows' => 3,
            'policy_hash' => app(ResearchPaperEpochContractService::class)->parameterHash($probe)];
        $indices = hash_init('sha256'); hash_update($indices, "replay-executed-clock-v1:indices\n");
        $schedule = hash_init('sha256'); hash_update($schedule, "replay-executed-clock-v1:schedule\n");
        for ($index = 1; $index < 5; $index++) {
            hash_update($indices, $index."\n");
            hash_update($schedule, json_encode([$index, $rows[$index + 2]['time'], $rows[$index + 3]['time']], JSON_UNESCAPED_SLASHES)."\n");
        }
        $clock = $this->seal(['protocol' => 'replay_executed_clock_v1', 'owner' => $owner,
            'semantics' => 'previous_closed_candle_next_open_v1', 'index_basis' => 'evaluated_frame_zero_based_v1',
            'input_rows' => 5 + $offset, 'evaluation_offset_rows' => $offset, 'execution_timeframe' => 'M5',
            'duration_seconds' => 300, 'dataset_hash' => str_repeat('d', 64), 'execution_hash' => str_repeat('e', 64),
            'policy_hash' => $scope['policy_hash'], 'probe_contract_hash' => $probe['contract_hash'],
            'complete' => true, 'decision_rows' => 4, 'first_evaluation_index' => 1, 'last_evaluation_index' => 4,
            'signal_start' => $rows[3]['time'], 'signal_end' => $rows[6]['time'],
            'execution_start' => $rows[4]['time'], 'execution_end' => $rows[7]['time'],
            'index_set_hash' => hash_final($indices), 'schedule_hash' => hash_final($schedule), 'promotion_evidence' => false]);
        $response = ['data_quality' => ['replay_executed_clock' => $clock],
            'prospective_probe_window_receipt' => [...$probe, 'complete' => true]];
        if ($owner === 'native_specialist_council_v1') {
            $response['specialist_council_receipt'] = $this->seal(['status' => 'computed',
                'evaluated_scope' => $scope, 'replay_executed_clock' => $clock]);
        }
        return [['evaluation_scope' => $scope], ['replay_dataset_hash' => str_repeat('d', 64),
            'execution_hash' => str_repeat('e', 64), 'policy_context' => ['prospective_probe_window' => $probe]], $response];
    }

    private function guard(array $fixture): void
    {
        app(SpecialistCouncilLifecycleService::class)->assertOriginalArmScope(...[...$fixture, 'M5']);
    }

    public function test_python_php_clock_digest_domain_and_utc_scalar_encoding_are_pinned(): void
    {
        $indices = hash_init('sha256'); hash_update($indices, "replay-executed-clock-v1:indices\n");
        $schedule = hash_init('sha256'); hash_update($schedule, "replay-executed-clock-v1:schedule\n");
        $start = CarbonImmutable::parse('2025-01-06T00:00:00Z');
        for ($index = 1; $index < 5; $index++) {
            hash_update($indices, $index."\n");
            hash_update($schedule, json_encode([$index, $start->addMinutes(5 * ($index - 1))->toIso8601String(),
                $start->addMinutes(5 * $index)->toIso8601String()], JSON_UNESCAPED_SLASHES)."\n");
        }
        $this->assertSame('461aae6ea2c998e738ca7c0b0445ae0aa4baaeb3d98bd7291f1d3b9eb4ac3033', hash_final($indices));
        $this->assertSame('49538a0a7c7ff1c62378be0426bec91dbafbdf5921d9973f38ebb0993f7aec4f', hash_final($schedule));
    }

    public function test_equal_actual_physical_clock_passes_for_ordinary_solo_without_native_account_receipt(): void
    {
        $ordinary = $this->fixture();
        $native = $this->fixture('native_specialist_council_v1', 3);
        $this->guard($ordinary);
        $this->guard($native);
        $this->assertArrayNotHasKey('specialist_council_receipt', $ordinary[2]);
        $this->paired([$ordinary[2]['data_quality']['replay_executed_clock'], $native[2]['data_quality']['replay_executed_clock']]);
        $this->addToAssertionCount(3);
    }

    public function test_input_selection_receipt_alone_cannot_manufacture_executed_rows_or_scope(): void
    {
        $fixture = $this->fixture();
        unset($fixture[2]['data_quality']['replay_executed_clock']);
        $fixture[2]['data_quality']['replay_evaluation_scope'] = $fixture[0]['evaluation_scope'];
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('ORIGINAL_COMPARATOR_EXECUTED_CLOCK_RECEIPT_MISSING');
        $this->guard($fixture);
    }

    public static function corruptions(): array
    {
        return [
            'short actual loop' => ['decision_rows', 3, 'ORIGINAL_PAIRED_ARM_EXECUTED_CLOCK_MISMATCH'],
            'legacy start200' => ['first_evaluation_index', 200, 'ORIGINAL_PAIRED_ARM_EXECUTED_CLOCK_MISMATCH'],
            'wrong index digest' => ['index_set_hash', str_repeat('a', 64), 'ORIGINAL_PAIRED_ARM_EXECUTED_CLOCK_MISMATCH'],
            'wrong input rows' => ['input_rows', 200, 'ORIGINAL_PAIRED_ARM_EXECUTED_CLOCK_MISMATCH'],
            'string count' => ['decision_rows', '4', 'ORIGINAL_COMPARATOR_EXECUTED_CLOCK_ROWS_INVALID'],
            'stale dataset' => ['dataset_hash', str_repeat('a', 64), 'ORIGINAL_COMPARATOR_EXECUTED_CLOCK_IDENTITY_INVALID'],
            'stale policy' => ['policy_hash', str_repeat('a', 64), 'ORIGINAL_COMPARATOR_EXECUTED_CLOCK_IDENTITY_INVALID'],
            'stale probe' => ['probe_contract_hash', str_repeat('a', 64), 'ORIGINAL_COMPARATOR_EXECUTED_CLOCK_IDENTITY_INVALID'],
            'wrong duration' => ['duration_seconds', 60, 'ORIGINAL_COMPARATOR_EXECUTED_CLOCK_IDENTITY_INVALID'],
            'incomplete' => ['complete', false, 'ORIGINAL_COMPARATOR_EXECUTED_CLOCK_IDENTITY_INVALID'],
            'wrong ordinary owner' => ['owner', 'native_specialist_council_v1', 'ORIGINAL_COMPARATOR_EXECUTED_CLOCK_OWNER_INVALID'],
            'unknown claim field' => ['caller_authority', true, 'ORIGINAL_COMPARATOR_EXECUTED_CLOCK_SHAPE_INVALID'],
            'missing observed UTC' => ['signal_start', null, 'Explicit UTC-offset timestamp required.'],
            'timezone-free observed UTC' => ['execution_end', '2025-01-06T00:35:00', 'Explicit UTC-offset timestamp required.'],
            'unbounded rows' => ['input_rows', 2000001, 'ORIGINAL_COMPARATOR_EXECUTED_CLOCK_ROWS_INVALID'],
        ];
    }

    #[DataProvider('corruptions')]
    public function test_rehashed_wrong_or_stale_clock_cannot_be_accepted(string $field, mixed $value, string $reason): void
    {
        $fixture = $this->fixture();
        $fixture[2]['data_quality']['replay_executed_clock'] = $this->seal([
            ...$fixture[2]['data_quality']['replay_executed_clock'], $field => $value]);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($reason);
        $this->guard($fixture);
    }

    public function test_stale_scope_calendar_does_not_override_real_execution_observations(): void
    {
        $fixture = $this->fixture();
        $fixture[2]['data_quality']['replay_executed_clock'] = $this->seal([
            ...$fixture[2]['data_quality']['replay_executed_clock'], 'signal_start' => '2025-01-06T00:20:00+00:00']);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('ORIGINAL_PAIRED_ARM_CALENDAR_OR_ROW_BUDGET_MISMATCH');
        $this->guard($fixture);
    }

    public function test_native_clock_copy_cannot_drift_from_original_account_seal(): void
    {
        $fixture = $this->fixture('native_specialist_council_v1', 3);
        $native = $fixture[2]['specialist_council_receipt'];
        $native['replay_executed_clock']['schedule_hash'] = str_repeat('a', 64);
        $fixture[2]['specialist_council_receipt'] = $this->seal($native);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('ORIGINAL_COMPARATOR_EXECUTED_CLOCK_OWNER_INVALID');
        $this->guard($fixture);
    }

    public function test_caller_authorized_marker_without_original_issuer_is_not_a_runtime_clock_owner(): void
    {
        $fixture = $this->fixture();
        $fixture[1]['policy_context']['authorized_research_transport']['original_council_arm'] = ['protocol' => 'authorized_original_council_arm_v1'];
        $fixture[2]['data_quality']['replay_executed_clock'] = $this->seal([
            ...$fixture[2]['data_quality']['replay_executed_clock'], 'owner' => 'authorized_original_council_arm_v1']);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('ORIGINAL_COMPARATOR_EXECUTED_CLOCK_OWNER_INVALID');
        $this->guard($fixture);
    }

    public function test_real_python_signed_transport_and_original_full_solo_clock_survive_php_serialization(): void
    {
        $script = <<<'PYTHON'
import json, sys, tempfile
from pathlib import Path
sys.path.insert(0, 'tests')
import pytest
from test_authorized_research_transport import authorized
from test_authorized_council_arm import arm
from app import main
with tempfile.TemporaryDirectory(prefix='executed-clock-transport-') as directory:
    patch = pytest.MonkeyPatch()
    try:
        root = Path(directory)
        request = arm.__wrapped__(authorized.__wrapped__(root, patch), root, patch)
        result = main._run_all_backtests_sync(request)['leaderboard'][0]['result']
        print(json.dumps({'request': request.model_dump(mode='json'), 'response': result}, allow_nan=False))
    finally:
        patch.undo()
PYTHON;
        $process = new Process(['python', '-c', $script], base_path('../ai-service-python'), timeout: 45);
        $process->mustRun();
        $actual = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $request = $actual['request'];
        // The Python request schema projects this original executable field
        // into execution_contract; Laravel persists the same value at top level.
        $request['execution_hash'] = $request['execution_contract']['execution_hash'];
        $scope = json_decode(data_get($request, 'policy_context.authorized_research_transport.original_council_arm.evaluation_scope_json'),
            true, flags: JSON_THROW_ON_ERROR);
        app(SpecialistCouncilLifecycleService::class)->assertOriginalArmScope(['evaluation_scope' => $scope],
            $request, $actual['response'], $request['timeframe']);
        $clock = data_get($actual['response'], 'data_quality.replay_executed_clock');
        $this->assertSame('authorized_original_council_arm_v1', $clock['owner']);
        $this->assertSame(1, $clock['decision_rows']);
        $this->assertSame(1, $clock['first_evaluation_index']);
        $this->assertArrayNotHasKey('specialist_council_receipt', array_filter($actual['response']));
        $this->assertFalse(data_get($actual['response'], 'data_quality.authorized_original_council_arm.promotion_evidence'));
    }

    private function paired(array $clocks): void
    {
        (new ReflectionMethod(SpecialistCouncilLifecycleService::class, 'assertPairedExecutedClocks'))
            ->invoke(app(SpecialistCouncilLifecycleService::class), array_map(fn ($clock) => ['executed_clock' => $clock], $clocks));
    }

    public function test_equal_endpoints_and_counts_do_not_hide_different_interior_execution_times(): void
    {
        $first = $this->fixture()[2]['data_quality']['replay_executed_clock'];
        $second = $this->seal([...$first, 'owner' => 'native_specialist_council_v1',
            'evaluation_offset_rows' => 3, 'input_rows' => 8, 'schedule_hash' => str_repeat('a', 64)]);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('ORIGINAL_PAIRED_ARM_PHYSICAL_EXECUTION_CLOCK_MISMATCH');
        $this->paired([$first, $second]);
    }

    public function test_legacy_original_clock_is_diagnostic_not_equal_by_missing_fields(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('ORIGINAL_PAIRED_ARM_EXECUTED_CLOCK_RECEIPT_MISSING');
        $this->paired([null, null]);
    }
}
