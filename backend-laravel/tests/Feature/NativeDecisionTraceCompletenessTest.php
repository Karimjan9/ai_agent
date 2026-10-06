<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Services\LabImmutableEvidenceService;
use App\Services\ResearchPaperEpochContractService;
use App\Services\SpecialistCouncilLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Trace-contract fixtures prove clock/ownership checks, never market or trading authority. */
class NativeDecisionTraceCompletenessTest extends TestCase
{
    use RefreshDatabase;

    private string $traceStorage;
    private string $originalStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalStorage = storage_path();
        $this->traceStorage = sys_get_temp_dir().'/native-trace-contract-test-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->traceStorage);
        $this->app->useStoragePath($this->traceStorage);
    }

    protected function tearDown(): void
    {
        $this->app->useStoragePath($this->originalStorage);
        $resolved = realpath($this->traceStorage);
        $prefix = str_replace('\\', '/', (string) realpath(sys_get_temp_dir())).'/native-trace-contract-test-';
        if ($resolved && str_starts_with(str_replace('\\', '/', $resolved), $prefix)) File::deleteDirectory($resolved);
        parent::tearDown();
    }

    public function test_actual_python_native_trace_keeps_its_float_types_across_the_transport_boundary(): void
    {
        // A tiny ordinary pre-2026 native replay exercises the actual producer
        // and consumer. It is not an authorized panel or market-evidence seal.
        $script = <<<'PYTHON'
import json
import sys
sys.path.insert(0, 'tests')
from test_specialist_council import fixtures
from app.services.backtester import run_simple_ema_rsi_backtest_on_dataframe
request, frame = fixtures()
request.emit_decision_trace = True
request.evaluation_mode = 'full'
request.policy_context = {'full_replay_runtime_policy': {
    'protocol': 'specialist_council_original_full_source_v1',
    'evaluation_mode': 'full', 'selection': 'entire_source', 'warmup_rows': 0}}
response = run_simple_ema_rsi_backtest_on_dataframe(request, frame.iloc[:2].copy())
print(json.dumps({'request': request.model_dump(mode='json'),
                 'response': response.model_dump(mode='json')}, allow_nan=False))
PYTHON;
        $process = new Process(['python', '-c', $script], base_path('../ai-service-python'), timeout: 45);
        $process->mustRun();
        $actual = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $run = $this->persistOriginalRequest($actual['request']);
        $evidence = app(LabImmutableEvidenceService::class);
        $proof = $evidence->decisionTraceCompleteness($actual['response'], $run);
        $this->assertTrue($proof['complete'], json_encode($proof));
        $this->assertSame(1, $proof['evaluated_candle_count']);
        $this->assertSame(1, $actual['response']['decision_trace'][0]['candle_index']);
        $this->assertCount(4, $actual['response']['decision_trace'][0]['member_decisions']);
        $lossy = json_decode(json_encode($actual['response'], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $lossyProof = $evidence->decisionTraceCompleteness($lossy, $run);
        $this->assertFalse($lossyProof['complete'], json_encode($lossyProof));
        $this->assertContains('DECISION_TRACE_HASH_MISMATCH', $lossyProof['reason_codes']);
    }

    public function test_original_full_zero_warmup_uses_its_actual_next_open_clock(): void
    {
        [$run, $response] = $this->scopedFixture(0, 2, true);
        $proof = app(LabImmutableEvidenceService::class)->decisionTraceCompleteness($response, $run);
        $this->assertTrue($proof['complete'], json_encode($proof));
        $this->assertSame(1, $proof['evaluated_candle_count']);
        $this->assertSame(1, $response['decision_trace'][0]['candle_index']);
        $this->assertFalse(app(LabImmutableEvidenceService::class)->decisionTraceCompleteness($response)['complete']);
    }

    public function test_empty_response_defaults_are_not_native_claims_for_an_unbound_original_solo(): void
    {
        [$run, $response] = $this->scopedFixture(0, 2, true, native: false);
        $evidence = app(LabImmutableEvidenceService::class);
        $request = $evidence->readArtifactPayload(LabEvidenceArtifact::where('run_id', $run->run_id)
            ->where('artifact_type', 'evaluation_request')->sole());
        $model = ModelVersion::findOrFail($run->model_version_id);
        $lifecycle = app(SpecialistCouncilLifecycleService::class);
        $this->assertNull($lifecycle->attestReplayResult($model, $request, $response));
        $proof = $evidence->decisionTraceCompleteness($response, $run);
        $this->assertTrue($proof['complete'], json_encode($proof));
        $this->assertSame('authorized_original_council_arm_v1', $proof['scope_owner']);
        foreach (['native_top_null', 'native_top_empty', 'native_top_scalar', 'native_strategy_empty',
            'unsolicited_receipt', 'unsolicited_quality_receipt'] as $case) {
            $candidateRequest = $request;
            $candidateResponse = $response;
            if ($case === 'native_top_null') $candidateRequest['specialist_council_contract'] = null;
            if ($case === 'native_top_empty') $candidateRequest['specialist_council_contract'] = [];
            if ($case === 'native_top_scalar') $candidateRequest['specialist_council_contract'] = 'not-a-contract';
            if ($case === 'native_strategy_empty') $candidateRequest['strategies'][0]['specialist_council_contract'] = [];
            if ($case === 'unsolicited_receipt') $candidateResponse['specialist_council_receipt'] = ['protocol' => 'unsolicited'];
            if ($case === 'unsolicited_quality_receipt') $candidateResponse['data_quality']['specialist_council_receipt'] = ['protocol' => 'unsolicited'];
            try {
                $lifecycle->attestReplayResult($model, $candidateRequest, $candidateResponse);
                $this->fail('A native declaration or unsolicited nonempty receipt was ignored: '.$case);
            } catch (\LogicException $error) {
                $this->assertSame('SPECIALIST_COUNCIL_RECEIPT_OR_BINDING_MISSING', $error->getMessage(), $case);
            }
        }
    }

    public function test_a_requested_native_council_cannot_use_an_empty_response_default_as_solo_evidence(): void
    {
        [$run, $response] = $this->scopedFixture(0, 2, true);
        $response['specialist_council_receipt'] = [];
        $proof = app(LabImmutableEvidenceService::class)->decisionTraceCompleteness($response, $run);
        $this->assertFalse($proof['complete'], json_encode($proof));
        $model = ModelVersion::findOrFail($run->model_version_id);
        $model->update(['metadata' => ['specialist_council' => []]]);
        $request = app(LabImmutableEvidenceService::class)->readArtifactPayload(LabEvidenceArtifact::where('run_id', $run->run_id)
            ->where('artifact_type', 'evaluation_request')->sole());
        $this->expectException(\LogicException::class);
        app(SpecialistCouncilLifecycleService::class)->attestReplayResult($model->fresh(), $request, $response);
    }

    public function test_erasing_all_response_hints_cannot_downgrade_the_immutable_original_scope_to_legacy(): void
    {
        foreach ([true, false] as $native) {
            [$run] = $this->scopedFixture(0, 2, true, native: $native);
            $run->update(['request_meta' => []]);
            $counterfeit = ['decision_trace' => [], 'data_quality' => ['decision_trace' => [
                'protocol' => 'candle_decision_trace_v1', 'requested' => true, 'complete' => true,
                'input_candle_count' => 2, 'event_count' => 0, 'evaluated_candle_count' => 0]]];
            $proof = app(LabImmutableEvidenceService::class)->decisionTraceCompleteness($counterfeit, $run->fresh());
            $this->assertFalse($proof['complete'], json_encode(['native_request' => $native, 'proof' => $proof]));
        }
    }

    public function test_genuine_unbound_original_request_keeps_the_ordinary_legacy_clock(): void
    {
        $run = $this->persistOriginalRequest(['strategy' => 'ema_rsi_v1', 'parameters' => [],
            'timeframe' => 'M5', 'evaluation_mode' => 'full']);
        $response = ['specialist_council_receipt' => [], 'decision_trace' => [['event_type' => 'signal_evaluation',
            'candle_index' => 200, 'candle_time' => '2025-01-01T00:00:00Z']], 'data_quality' => ['decision_trace' => [
                'protocol' => 'candle_decision_trace_v1', 'requested' => true, 'complete' => true,
                'input_candle_count' => 201, 'event_count' => 1, 'evaluated_candle_count' => 1]]];
        $proof = app(LabImmutableEvidenceService::class)->decisionTraceCompleteness($response, $run);
        $this->assertTrue($proof['complete'], json_encode($proof));
        $this->assertSame('legacy_200_candle_clock', $proof['scope_owner']);
        $this->assertSame(200, $proof['first_candle_index']);
    }

    public function test_sealed_native_probe_uses_512_warmup_and_14999_actual_clock_rows(): void
    {
        [$run, $response] = $this->scopedFixture(512, 15000);
        $proof = app(LabImmutableEvidenceService::class)->decisionTraceCompleteness($response, $run);
        $this->assertTrue($proof['complete'], json_encode($proof));
        $this->assertSame(14999, $proof['evaluated_candle_count']);
        $this->assertSame(513, $response['decision_trace'][0]['candle_index']);
        $this->assertSame(15511, $response['decision_trace'][14998]['candle_index']);
    }

    public function test_original_artifact_not_mutable_request_projection_owns_the_scope(): void
    {
        [$run, $response] = $this->scopedFixture(0, 2, true);
        $run->update(['request_meta' => ['payload' => ['policy_context' => ['full_replay_runtime_policy' => ['warmup_rows' => 200]]]]]);
        $this->assertTrue(app(LabImmutableEvidenceService::class)->decisionTraceCompleteness($response, $run->fresh())['complete']);
        $artifact = LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', 'evaluation_request')->sole();
        $artifact->update(['metadata' => [...$artifact->metadata, 'request_hash' => str_repeat('f', 64)]]);
        $this->assertFalse(app(LabImmutableEvidenceService::class)->decisionTraceCompleteness($response, $run->fresh())['complete']);
    }

    public function test_corrupt_scope_hash_rows_or_clock_cannot_become_complete(): void
    {
        [$run, $baseline] = $this->scopedFixture(0, 4, true);
        foreach (['scope_rows', 'warmup', 'trace_hash', 'receipt_hash', 'clock_time', 'duplicate_index',
            'receipt_first_index', 'receipt_last_index', 'member_version', 'closed_input_time',
            'source_input_hash', 'source_input_columns', 'member_input_hash', 'member_input_columns'] as $case) {
            $response = $baseline;
            if ($case === 'scope_rows') $response['data_quality']['decision_trace']['evaluated_scope']['rows'] = 5;
            if ($case === 'warmup') $response['data_quality']['decision_trace']['warmup_rows'] = 200;
            if ($case === 'trace_hash') $response['data_quality']['decision_trace']['trace_hash'] = str_repeat('f', 64);
            if ($case === 'receipt_hash') $response['specialist_council_receipt']['receipt_hash'] = str_repeat('f', 64);
            if ($case === 'clock_time') $response['decision_trace'][0]['candle_time'] = '2025-01-01T00:00:00Z';
            if ($case === 'duplicate_index') $response['decision_trace'][1]['candle_index'] = $response['decision_trace'][0]['candle_index'];
            if ($case === 'receipt_first_index') $response['specialist_council_receipt']['decision_trace_identity']['first_candle_index'] = 200;
            if ($case === 'receipt_last_index') $response['specialist_council_receipt']['decision_trace_identity']['last_candle_index'] = 200;
            if ($case === 'member_version') $response['decision_trace'][0]['member_decisions'][0]['member_version_hash'] = str_repeat('f', 64);
            if ($case === 'closed_input_time') $response['decision_trace'][0]['member_decisions'][0]['closed_inputs']['time'] = '2030-01-01T00:00:00Z';
            if ($case === 'source_input_hash') $response['decision_trace'][0]['closed_source_inputs_hash'] = 'not-a-hash';
            if ($case === 'source_input_columns') $response['decision_trace'][0]['closed_source_input_columns'] = 0;
            if ($case === 'member_input_hash') $response['decision_trace'][0]['member_decisions'][0]['closed_inputs_hash'] = 'not-a-hash';
            if ($case === 'member_input_columns') $response['decision_trace'][0]['member_decisions'][0]['closed_input_columns'] = 0;
            if (in_array($case, ['clock_time', 'duplicate_index', 'receipt_first_index', 'receipt_last_index',
                'member_version', 'closed_input_time', 'source_input_hash', 'source_input_columns',
                'member_input_hash', 'member_input_columns'], true)) $response = $this->resealTrace($response);
            $this->assertFalse(app(LabImmutableEvidenceService::class)->decisionTraceCompleteness($response, $run)['complete'], $case);
        }
    }

    public function test_ordinary_legacy_clock_still_starts_at_200(): void
    {
        $response = ['decision_trace' => [['event_type' => 'signal_evaluation', 'candle_index' => 200,
            'candle_time' => '2025-01-01T00:00:00Z']], 'data_quality' => ['decision_trace' => [
            'protocol' => 'candle_decision_trace_v1', 'requested' => true, 'complete' => true,
            'input_candle_count' => 201, 'evaluated_candle_count' => 1, 'event_count' => 1]]];
        $this->assertTrue(app(LabImmutableEvidenceService::class)->decisionTraceCompleteness($response)['complete']);
        $response['decision_trace'][0]['candle_index'] = 1;
        $this->assertFalse(app(LabImmutableEvidenceService::class)->decisionTraceCompleteness($response)['complete']);
    }

    /** Original request bytes and a sealed receipt are fixtures for a bounded consumer contract. */
    private function scopedFixture(int $warmup, int $rows, bool $originalFull = false, bool $native = true): array
    {
        $epochs = app(ResearchPaperEpochContractService::class);
        $evidence = app(LabImmutableEvidenceService::class);
        $origin = CarbonImmutable::parse('2025-01-01T00:00:00Z');
        $policy = $originalFull
            ? ['protocol' => 'specialist_council_original_full_source_v1', 'warmup_rows' => $warmup,
                'evaluation_mode' => 'full', 'selection' => 'entire_authorized_source']
            : ['protocol' => 'prospective_repair_probe_window_v1', 'warmup_rows' => $warmup,
                'evaluated_rows' => $rows, 'loaded_rows' => $warmup + $rows,
                'evaluated_start' => $origin->addSeconds($warmup * 300)->toIso8601String(),
                'evaluated_end' => $origin->addSeconds(($warmup + $rows - 1) * 300)->toIso8601String()];
        $policyHash = $epochs->parameterHash($policy);
        $scope = ['start_inclusive' => $origin->addSeconds($warmup * 300)->toIso8601String(),
            'end_exclusive' => $origin->addSeconds(($warmup + $rows) * 300)->toIso8601String(),
            'rows' => $rows, 'decision_rows' => $rows - 1, 'warmup_rows' => $warmup, 'policy_hash' => $policyHash];
        $trace = [];
        for ($index = $warmup + 1; $index < $warmup + $rows; $index++) {
            $stamp = $origin->addSeconds($index * 300)->toIso8601String();
            $signal = $origin->addSeconds(($index - 1) * 300)->toIso8601String();
            $clock = ['contract_hash' => str_repeat('c', 64), 'dataset_hash' => str_repeat('d', 64),
                'source_sha256' => str_repeat('a', 64), 'candle_index' => $index, 'signal_time' => $signal,
                'decision_at' => $stamp, 'execution_time' => $stamp];
            $decisionId = $epochs->parameterHash($clock);
            $closedInputs = ['time' => $signal];
            $trace[] = ['event_type' => 'signal_evaluation', 'candle_index' => $index, 'candle_time' => $stamp,
                'signal_time' => $signal, 'decision_at' => $stamp, 'execution_time' => $stamp,
                'decision_id' => $decisionId, 'source_clock' => $clock, 'features' => $closedInputs,
                'closed_source_inputs_hash' => $epochs->parameterHash($closedInputs), 'closed_source_input_columns' => count($closedInputs),
                'member_decisions' => [[
                    'specialist_id' => 'hour-source', 'member_version_hash' => str_repeat('b', 64),
                    'decision_id' => $epochs->parameterHash(['account_decision_id' => $decisionId,
                        'member_version_hash' => str_repeat('b', 64)]), 'closed_inputs' => $closedInputs,
                    'closed_inputs_hash' => $epochs->parameterHash($closedInputs), 'closed_input_columns' => count($closedInputs)]]];
        }
        $traceHash = $epochs->parameterHash($trace);
        $identity = ['protocol' => 'native_council_decision_trace_v1', 'contract_hash' => str_repeat('c', 64),
            'trace_hash' => $traceHash, 'source_rows' => $warmup + $rows, 'decision_rows' => $rows - 1,
            'warmup_rows' => $warmup, 'first_candle_index' => $warmup + 1,
            'last_candle_index' => $warmup + $rows - 1, 'scope_policy_hash' => $policyHash];
        $receipt = ['protocol' => 'specialist_council_receipt_v1', 'status' => 'computed',
            'contract_hash' => str_repeat('c', 64), 'dataset_hash' => str_repeat('d', 64),
            'source_rows' => $warmup + $rows, 'source_attestation' => ['actual_source_sha256' => str_repeat('a', 64)],
            'members' => [['specialist_id' => 'hour-source', 'member_version_hash' => str_repeat('b', 64)]],
            'evaluated_scope' => $scope, 'decision_trace_identity' => $identity];
        $receipt['receipt_hash'] = $epochs->parameterHash($receipt);
        $response = ['specialist_council_receipt' => $receipt, 'decision_trace' => $trace,
            'data_quality' => ['replay_evaluation_scope' => $scope, 'decision_trace' => ['protocol' => 'candle_decision_trace_v1',
                'scope_owner' => 'native_specialist_council_v1',
                'requested' => true, 'complete' => true, 'event_count' => count($trace),
                'input_candle_count' => $warmup + $rows, 'evaluated_candle_count' => $rows - 1,
                'warmup_rows' => $warmup, 'first_candle_index' => $warmup + 1,
                'evaluated_scope' => $scope, 'trace_hash' => $traceHash]]];
        if (! $native) {
            $response['specialist_council_receipt'] = [];
            $response['data_quality']['decision_trace']['scope_owner'] = 'authorized_original_council_arm_v1';
        }
        $contract = ['protocol' => 'specialist_council_runtime_v1', 'contract_hash' => str_repeat('c', 64)];
        $request = ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'evaluation_mode' => 'full', 'replay_dataset_hash' => str_repeat('d', 64),
            'strategies' => [['strategy' => 'ema_rsi_v1', 'specialist_council_contract' => $contract]],
            'policy_context' => [$originalFull ? 'full_replay_runtime_policy' : 'prospective_probe_window' => $policy]];
        if ($originalFull) {
            $arm = ['plan_hash' => str_repeat('e', 64), 'arm_key' => 'w1:candidate',
                'window_key' => str_repeat('f', 64), 'model_hash' => str_repeat('a', 64)];
            $request['policy_context']['specialist_council_authorized_arm'] = [...$arm, 'evaluation_scope' => $scope];
            $request['policy_context']['authorized_research_transport'] = ['original_council_arm' => [...$arm,
                'protocol' => 'authorized_original_council_arm_v1', 'independent_evidence' => false, 'promotion_evidence' => false,
                'evaluation_scope_json' => json_encode($scope, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)],
                'files' => ['M5' => ['rows' => $warmup + $rows]]];
        }
        if (! $native) unset($request['strategies'][0]['specialist_council_contract']);
        return [$this->persistOriginalRequest($request, bindStrategyAgent: true), $response];
    }

    private function persistOriginalRequest(array $request, bool $bindStrategyAgent = false): LabEvaluationRun
    {
        $lab = AiLaboratory::firstOrCreate(['symbol' => 'XAUUSD', 'timeframe' => 'H1'],
            ['name' => 'Trace contract fixture', 'strategy_families' => ['ema_rsi']]);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id,
            'generation' => (int) LabGeneration::where('ai_laboratory_id', $lab->id)->max('generation') + 1,
            'trigger_type' => 'test', 'status' => 'full_validation']);
        $model = ModelVersion::create(['name' => 'Unqualified trace contract fixture '.$generation->id, 'strategy' => 'ema_rsi_v1',
            'version' => 'v1', 'status' => 'testing', 'parameters' => [], 'metadata' => []]);
        $agent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'ema_rsi', 'origin' => 'test', 'lifecycle_status' => 'full_validation']);
        if ($bindStrategyAgent) {
            foreach ($request['strategies'] as &$strategy) $strategy['lab_agent_id'] = $agent->id;
            unset($strategy);
        }
        $evidence = app(LabImmutableEvidenceService::class);
        $run = LabEvaluationRun::create(['run_id' => (string) Str::uuid(), 'lab_generation_id' => $generation->id,
            'lab_agent_id' => $agent->id, 'model_version_id' => $model->id, 'phase' => 'full_validation', 'mode' => 'full',
            'status' => 'started', 'started_at' => now(), 'request_hash' => $evidence->hash($request)]);
        $evidence->recordArtifact($run, 'evaluation_request', $request, ['request_hash' => $run->request_hash]);
        return $run->fresh();
    }

    /** Rehashing a corrupt producer event still cannot change the original clock. */
    private function resealTrace(array $response): array
    {
        $epochs = app(ResearchPaperEpochContractService::class);
        $hash = $epochs->parameterHash($response['decision_trace']);
        $response['data_quality']['decision_trace']['trace_hash'] = $hash;
        $response['specialist_council_receipt']['decision_trace_identity']['trace_hash'] = $hash;
        unset($response['specialist_council_receipt']['receipt_hash']);
        $response['specialist_council_receipt']['receipt_hash'] = $epochs->parameterHash($response['specialist_council_receipt']);
        return $response;
    }
}
