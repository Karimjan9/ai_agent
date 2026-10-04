<?php

namespace Tests\Feature;

use App\Jobs\ProjectLabCandleDecisionEvents;
use App\Jobs\ProcessLabScreeningLearningProjection;
use App\Models\AiLaboratory;
use App\Models\CandidateGateDecision;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use App\Models\LabGeneration;
use App\Models\LearningRecoveryEvent;
use App\Models\ModelVersion;
use App\Models\ScreeningLearningOutbox;
use App\Services\LabImmutableEvidenceService;
use App\Services\ScreeningLearningOutboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DecisionTraceLearningContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_zero_trade_wait_history_is_complete_but_sparse_or_duplicate_candle_coverage_is_not(): void
    {
        $evidence = app(LabImmutableEvidenceService::class);
        $response = $this->response();
        $this->assertTrue($evidence->decisionTraceCompleteness($response)['complete']);
        $response['decision_trace'] = [$response['decision_trace'][0]];
        $response['data_quality']['decision_trace']['event_count'] = 1;
        $this->assertContains('DECISION_TRACE_CANDLE_COVERAGE_MISMATCH', $evidence->decisionTraceCompleteness($response)['reason_codes']);
        $response = $this->response();
        $response['decision_trace'][1]['candle_index'] = 200;
        $this->assertFalse($evidence->decisionTraceCompleteness($response)['complete']);
    }

    public function test_missing_flags_truncated_counts_and_non_integer_counts_are_incomplete(): void
    {
        $evidence = app(LabImmutableEvidenceService::class);
        foreach (['missing', 'truncated', 'disabled', 'string_count'] as $case) {
            $response = $this->response();
            if ($case === 'missing') unset($response['data_quality']);
            if ($case === 'truncated') $response['data_quality']['decision_trace']['event_count'] = 3;
            if ($case === 'disabled') $response['data_quality']['decision_trace']['complete'] = false;
            if ($case === 'string_count') $response['data_quality']['decision_trace']['evaluated_candle_count'] = '2';
            $this->assertFalse($evidence->decisionTraceCompleteness($response)['complete'], $case);
        }
    }

    public function test_empty_trace_needs_explicit_requested_complete_zero_evaluated_contract(): void
    {
        $evidence = app(LabImmutableEvidenceService::class);
        $response = $this->response(0);
        $this->assertTrue($evidence->decisionTraceCompleteness($response)['complete']);
        $response['data_quality']['decision_trace']['evaluated_candle_count'] = 1;
        $this->assertFalse($evidence->decisionTraceCompleteness($response)['complete']);
        unset($response['data_quality']['decision_trace']['evaluated_candle_count']);
        $this->assertFalse($evidence->decisionTraceCompleteness($response)['complete']);
        $response = $this->response(0);
        $response['data_quality']['decision_trace']['requested'] = false;
        $this->assertFalse($evidence->decisionTraceCompleteness($response)['complete']);
    }

    public function test_sealed_new_proof_binds_response_trace_and_manifest_without_requiring_trades(): void
    {
        Queue::fake();
        [$agent, $run] = $this->evidenceRun();
        $evidence = app(LabImmutableEvidenceService::class);
        $evidence->finishRun($run, 'completed', $this->response());
        $proof = $evidence->learningEligibility($run->fresh());
        $this->assertTrue($proof['complete']);
        $this->assertSame('decision_trace_producer_verified_v1', $proof['decision_trace_proof']);
        $manifest = LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', 'decision_trace_manifest')->firstOrFail();
        $trace = LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', 'decision_trace')->firstOrFail();
        $this->assertSame($run->response_hash, $manifest->metadata['result_hash']);
        $this->assertSame($trace->sha256, $manifest->metadata['artifact_sha256']);
        // Tampering here is test-only: a consumer must not bless mismatched
        // metadata merely because both complete flags are true.
        $manifest->update(['metadata' => [...$manifest->metadata, 'event_count' => 1]]);
        $this->assertContains('MISSING_COMPLETE_DECISION_TRACE', $evidence->learningEligibility($run->fresh())['reason_codes']);
    }

    public function test_attested_empty_trace_is_durable_but_unattested_legacy_empty_trace_is_not(): void
    {
        Queue::fake();
        [, $run] = $this->evidenceRun(0);
        $evidence = app(LabImmutableEvidenceService::class);
        $evidence->finishRun($run, 'completed', $this->response(0));
        $this->assertTrue($evidence->learningEligibility($run->fresh())['complete']);
        $meta = $run->response_meta;
        unset($meta['decision_trace_completeness']);
        $run->update(['response_meta' => $meta]);
        $this->assertFalse($evidence->learningEligibility($run->fresh())['complete']);
    }

    public function test_empty_declared_zero_trace_cannot_override_known_nonzero_request_coverage(): void
    {
        Queue::fake();
        [, $run] = $this->evidenceRun(2);
        $evidence = app(LabImmutableEvidenceService::class);
        $response = $this->response(0);
        $before = $evidence->replayEvidenceCompleteness($run, $response);
        $this->assertFalse($before['decision_trace']);
        $this->assertContains('DECISION_TRACE_EXPECTED_CANDLE_COUNT_MISMATCH', $before['decision_trace_reason_codes']);
        $evidence->finishRun($run, 'completed', $response);
        $this->assertFalse($evidence->learningEligibility($run->fresh())['complete']);
    }

    public function test_terminal_publication_and_projection_wait_for_the_outer_transaction_commit(): void
    {
        Queue::fake();
        [, $run] = $this->evidenceRun();
        $evidence = app(LabImmutableEvidenceService::class);
        DB::beginTransaction();
        try {
            $evidence->finishRun($run, 'completed', $this->response());
            Queue::assertNothingPushed();
            $this->assertTrue($evidence->learningEligibility($run->fresh())['complete']);
            DB::commit();
        } catch (\Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }
        Queue::assertPushed(ProjectLabCandleDecisionEvents::class, fn ($job) => $job->runId === $run->run_id);
        $this->assertTrue($evidence->learningEligibility($run->fresh())['complete']);
    }

    public function test_artifact_failure_rolls_back_terminal_visibility_and_retry_seals_once(): void
    {
        Queue::fake();
        [, $run] = $this->evidenceRun();
        $failing = new class extends LabImmutableEvidenceService {
            public function recordArtifact(?LabEvaluationRun $run, string $type, array $payload, array $metadata = [], ?LabAgent $agent = null, ?string $runId = null): LabEvidenceArtifact
            {
                if ($type === 'decision_trace_manifest') throw new \RuntimeException('TEST_TRACE_STORE_UNAVAILABLE');

                return parent::recordArtifact($run, $type, $payload, $metadata, $agent, $runId);
            }
        };
        try {
            $failing->finishRun($run, 'completed', $this->response());
            $this->fail('Expected test artifact failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('TEST_TRACE_STORE_UNAVAILABLE', $exception->getMessage());
        }
        $this->assertNotSame('completed', $run->fresh()->status);
        $this->assertNull($run->response_hash);
        $this->assertSame(0, LabEvidenceArtifact::where('run_id', $run->run_id)->whereIn('artifact_type', ['evaluation_response', 'decision_trace', 'trade_ledger'])->count());
        Queue::assertNothingPushed();
        $evidence = app(LabImmutableEvidenceService::class);
        $evidence->finishRun($run, 'completed', $this->response());
        $evidence->finishRun($run, 'technical_error', ['late' => true]);
        $this->assertTrue($evidence->learningEligibility($run->fresh())['complete']);
        $this->assertSame(1, LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', 'decision_trace_manifest')->count());
        Queue::assertPushed(ProjectLabCandleDecisionEvents::class, 1);
    }

    public function test_terminal_incomplete_screening_outbox_is_typed_dependency_and_duplicate_is_noop(): void
    {
        Queue::fake();
        [$agent, $run] = $this->evidenceRun();
        $response = $this->response();
        $response['decision_trace'] = [$response['decision_trace'][0]];
        $response['data_quality']['decision_trace']['event_count'] = 1;
        app(LabImmutableEvidenceService::class)->finishRun($run, 'completed', $response);
        $outbox = app(ScreeningLearningOutboxService::class);
        $projection = ['evidence_run_id' => $run->run_id, 'total_trades' => 0];
        $outbox->enqueue($agent, $projection, 0);
        $this->assertSame(0, $outbox->process());
        $row = ScreeningLearningOutbox::firstOrFail();
        $this->assertSame('blocked_dependency', $row->status);
        $this->assertContains('MISSING_COMPLETE_DECISION_TRACE', data_get(json_decode($row->last_error, true), 'evidence.reason_codes'));
        $outbox->enqueue($agent, $projection, 0);
        $this->assertSame(0, $outbox->process());
        $this->assertSame(1, (int) $row->fresh()->attempts);
        $this->assertDatabaseCount('agent_memories', 0);
    }

    public function test_complete_zero_trade_screening_outbox_records_inconclusive_memory_not_technical_dependency(): void
    {
        Queue::fake();
        [$agent, $run] = $this->evidenceRun();
        app(LabImmutableEvidenceService::class)->finishRun($run, 'completed', $this->response());
        $outbox = app(ScreeningLearningOutboxService::class);
        $outbox->enqueue($agent, ['evidence_run_id' => $run->run_id, 'total_trades' => 0], 0);
        $this->assertSame(1, $outbox->process());
        $this->assertSame('completed', ScreeningLearningOutbox::firstOrFail()->status);
        $this->assertDatabaseHas('agent_memories', ['source_type' => LabAgent::class, 'source_id' => $agent->id, 'outcome' => 'inconclusive']);
    }

    public function test_complete_trace_from_a_different_agent_cannot_settle_screening_memory(): void
    {
        Queue::fake();
        [, $otherRun] = $this->evidenceRun();
        [$agent] = $this->evidenceRun();
        app(LabImmutableEvidenceService::class)->finishRun($otherRun, 'completed', $this->response());
        $outbox = app(ScreeningLearningOutboxService::class);
        $outbox->enqueue($agent, ['evidence_run_id' => $otherRun->run_id, 'total_trades' => 0], 0);
        $this->assertSame(0, $outbox->process());
        $row = ScreeningLearningOutbox::firstOrFail();
        $this->assertSame('blocked_dependency', $row->status);
        $this->assertContains('SCREENING_EVIDENCE_OWNER_MISMATCH', data_get(json_decode($row->last_error, true), 'evidence.reason_codes'));
        $this->assertDatabaseCount('agent_memories', 0);
    }

    public function test_terminal_incomplete_projection_records_one_dependency_before_any_learning_side_effect(): void
    {
        Queue::fake();
        [$agent, $run] = $this->evidenceRun();
        $response = $this->response();
        $response['data_quality']['decision_trace']['complete'] = false;
        app(LabImmutableEvidenceService::class)->finishRun($run, 'completed', $response);
        $originalHash = $run->response_hash;
        $decision = CandidateGateDecision::create(['lab_agent_id' => $agent->id, 'stage' => 'screening',
            'decision' => 'failed', 'reason_codes' => ['INSUFFICIENT_EVIDENCE'], 'metrics' => [], 'evaluated_at' => now()]);
        $job = new ProcessLabScreeningLearningProjection($agent->id, $run->run_id, $decision->id,
            ['evidence_run_id' => $run->run_id, 'total_trades' => 0]);
        $this->app->call([$job, 'handle']);
        $this->app->call([$job, 'handle']);
        $dependency = LearningRecoveryEvent::firstOrFail();
        $this->assertSame('blocked_dependency', $dependency->status);
        $this->assertContains('MISSING_COMPLETE_DECISION_TRACE', data_get($dependency->metadata, 'evidence.reason_codes'));
        $this->assertFalse(data_get($dependency->metadata, 'may_dispatch_replay'));
        $this->assertDatabaseCount('learning_recovery_events', 1);
        $this->assertDatabaseCount('agent_knowledge_cards', 0);
        $this->assertDatabaseCount('lab_mutation_response_maps', 0);
        $this->assertDatabaseCount('agent_learning_settlements', 0);
        $this->assertDatabaseCount('agent_memories', 0);
        $this->assertSame([], $decision->fresh()->metrics);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame($originalHash, $run->response_hash);
    }

    private function response(int $evaluated = 2): array
    {
        $trace = [];
        for ($index = 0; $index < $evaluated; $index++) {
            $trace[] = ['candle_index' => 200 + $index, 'candle_time' => sprintf('2025-10-01T%02d:00:00Z', $index),
                'event_type' => 'signal_evaluation', 'action' => 'WAIT', 'accepted' => false, 'rejection_code' => 'no_signal'];
        }

        return ['total_trades' => 0, 'trade_ledger' => [], 'trades' => [], 'displayed_trade_count' => 0,
            'trade_ledger_hash' => hash('sha256', '[]'), 'decision_trace' => $trace,
            'data_quality' => ['decision_trace' => ['protocol' => 'candle_decision_trace_v1',
                'requested' => true, 'complete' => true, 'event_count' => count($trace), 'evaluated_candle_count' => $evaluated]]];
    }

    /** @return array{LabAgent,LabEvaluationRun} */
    private function evidenceRun(int $evaluated = 2): array
    {
        $lab = AiLaboratory::firstOrCreate(['symbol' => 'XAUUSD', 'timeframe' => 'H1'], ['name' => 'Trace contract', 'strategy_families' => ['hybrid']]);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => LabGeneration::where('ai_laboratory_id', $lab->id)->count() + 1, 'trigger_type' => 'test', 'status' => 'screening']);
        $model = ModelVersion::create(['name' => 'Trace model-'.$generation->id, 'strategy' => 'hybrid', 'version' => 'v1', 'generation' => 1,
            'status' => 'testing', 'parameters' => [], 'metadata' => []]);
        $agent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'origin' => 'test', 'lifecycle_status' => 'screened']);
        $evidence = app(LabImmutableEvidenceService::class);
        $run = $evidence->beginRun($agent, 'screening', 'screen');
        $evidence->attachRequest($run, ['symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'candles' => array_fill(0, 200 + $evaluated, ['time' => '2025-10-01T00:00:00Z', 'close' => 2000])]);

        return [$agent->fresh('modelVersion'), $run->fresh()];
    }
}
